<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\GoogleAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AuthAndBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');

        $response = $this->get('/leads');
        $response->assertRedirect('/login');

        $response = $this->get('/admin');
        $response->assertRedirect('/login');
    }

    public function test_inactive_authorized_user_is_denied_access(): void
    {
        $user = User::create([
            'email' => 'inactivo@ejemplo.test',
            'name' => 'Usuario Inactivo',
            'role' => 'advisor',
            'active' => false,
            'google_subject' => 'google-sub-inactive',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('error');
    }

    public function test_oauth_callback_rejects_missing_or_mismatched_csrf_state(): void
    {
        session(['oauth_google_state' => 'valid-state-12345']);

        $response = $this->get('/auth/google/callback?state=invalid-state&code=test-code');
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('error');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.csrf_state_invalid',
        ]);
    }

    public function test_rejects_unverified_email_from_google(): void
    {
        User::create([
            'email' => 'asesor@ejemplo.test',
            'name' => 'Asesor Activo',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => null,
        ]);

        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Acceso denegado');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-1',
            googleEmail: 'asesor@ejemplo.test',
            googleEmailVerified: false // NO VERIFICADO
        );
    }

    public function test_allows_first_time_binding_for_preauthorized_active_user(): void
    {
        $user = User::create([
            'email' => 'admin@tusolucionfinanciera.cl',
            'name' => 'Admin Preautorizado',
            'role' => 'admin',
            'active' => true,
            'google_subject' => null, // Esperando primer login
        ]);

        $service = new GoogleAuthService();

        $authenticatedUser = $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-first-login',
            googleEmail: 'admin@tusolucionfinanciera.cl',
            googleEmailVerified: true,
            googleName: 'Admin Vinculado'
        );

        $this->assertEquals($user->id, $authenticatedUser->id);
        $this->assertEquals('google-sub-first-login', $authenticatedUser->google_subject);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_subject' => 'google-sub-first-login',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.bound_google_account',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_rejects_mismatched_google_subject_on_already_bound_account(): void
    {
        User::create([
            'email' => 'asesor@ejemplo.test',
            'name' => 'Asesor Vinculado',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => 'google-sub-original',
        ]);

        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Acceso denegado');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-impostor',
            googleEmail: 'asesor@ejemplo.test',
            googleEmailVerified: true
        );
    }

    public function test_rejects_email_not_preauthorized_in_database(): void
    {
        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no está autorizada');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-desconocido',
            googleEmail: 'desconocido@gmail.com',
            googleEmailVerified: true
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.denied_unregistered_account',
        ]);
    }

    public function test_prevents_subject_collision_with_another_account(): void
    {
        User::create([
            'email' => 'usuario1@ejemplo.test',
            'name' => 'Usuario 1',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => 'google-sub-compartido',
        ]);

        User::create([
            'email' => 'usuario2@ejemplo.test',
            'name' => 'Usuario 2',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => null,
        ]);

        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ya vinculado');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-compartido',
            googleEmail: 'usuario2@ejemplo.test',
            googleEmailVerified: true
        );
    }

    public function test_concurrent_binding_race_condition_protection_after_acquiring_row_lock(): void
    {
        $user = User::create([
            'email' => 'concurrente@ejemplo.test',
            'name' => 'Usuario Concurrente',
            'role' => 'admin',
            'active' => true,
            'google_subject' => null,
        ]);

        $firstRead = true;
        User::retrieved(function ($model) use (&$firstRead) {
            if ($firstRead && $model->email === 'concurrente@ejemplo.test') {
                $firstRead = false;
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $model->id)
                    ->update(['google_subject' => 'google-sub-hilo-1']);
            }
        });

        $service = new GoogleAuthService();

        // El hilo concurrente llega intentando vincular 'google-sub-hilo-2'
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conflicto de concurrencia');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-hilo-2',
            googleEmail: 'concurrente@ejemplo.test',
            googleEmailVerified: true
        );
    }

    public function test_concurrent_binding_rejects_if_account_deactivated_before_lock(): void
    {
        $user = User::create([
            'email' => 'desactivado@ejemplo.test',
            'name' => 'Usuario Desactivado',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => null,
        ]);

        $firstRead = true;
        User::retrieved(function ($model) use (&$firstRead) {
            if ($firstRead && $model->email === 'desactivado@ejemplo.test') {
                $firstRead = false;
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $model->id)
                    ->update(['active' => false]);
            }
        });

        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('desactivada');

        $service->authenticateVerifiedIdentity(
            googleSubject: 'google-sub-nuevo',
            googleEmail: 'desactivado@ejemplo.test',
            googleEmailVerified: true
        );
    }

    public function test_google_id_token_verification_succeeds_with_valid_signature_and_claims(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200, [
                'Cache-Control' => 'public, max-age=3600',
            ]),
        ]);

        $service = new GoogleAuthService();
        $token = $this->makeGoogleIdToken([
            'sub' => 'google-user-sub-12345',
            'email' => 'asesor@ejemplo.test',
            'email_verified' => true,
            'name' => 'Asesor de Prueba',
        ], 'key1');

        $claims = $service->verifyGoogleIdToken($token, $clientId);

        $this->assertEquals('google-user-sub-12345', $claims['sub']);
        $this->assertEquals('asesor@ejemplo.test', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertEquals('Asesor de Prueba', $claims['name']);
    }

    public function test_google_id_token_verification_rejects_invalid_signature(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $service = new GoogleAuthService();

        // Firmado con la clave 2 pero declarando kid de la clave 1
        $key2 = $this->getTestGoogleKey('key2');
        $payload = [
            'iss' => 'https://accounts.google.com',
            'aud' => $clientId,
            'sub' => 'sub-forged',
            'email' => 'hacker@ejemplo.test',
            'email_verified' => true,
            'exp' => time() + 3600,
        ];
        $forgedToken = \Firebase\JWT\JWT::encode($payload, $key2['priv'], 'RS256', 'google-key-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Firma criptográfica del id_token');

        $service->verifyGoogleIdToken($forgedToken, $clientId);
    }

    public function test_google_id_token_verification_rejects_invalid_issuer(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $service = new GoogleAuthService();
        $token = $this->makeGoogleIdToken([
            'iss' => 'https://malicious-issuer.com',
        ], 'key1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Emisor (iss) de Google no reconocido');

        $service->verifyGoogleIdToken($token, $clientId);
    }

    public function test_google_id_token_verification_rejects_mismatched_audience(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $service = new GoogleAuthService();
        $token = $this->makeGoogleIdToken([
            'aud' => 'different-client-id.apps.googleusercontent.com',
        ], 'key1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audiencia (aud) de Google no coincide');

        $service->verifyGoogleIdToken($token, $clientId);
    }

    public function test_google_id_token_verification_rejects_expired_token(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $service = new GoogleAuthService();
        $token = $this->makeGoogleIdToken([
            'exp' => time() - 300,
        ], 'key1');

        $this->expectException(RuntimeException::class);
        // La biblioteca Firebase o la verificación explícita detectan el token expirado
        $this->expectExceptionMessageMatches('/(expirado|Expired)/i');

        $service->verifyGoogleIdToken($token, $clientId);
    }

    public function test_google_id_token_verification_rejects_unverified_email(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $service = new GoogleAuthService();
        $token = $this->makeGoogleIdToken([
            'email_verified' => false,
        ], 'key1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El correo de Google no está verificado');

        $service->verifyGoogleIdToken($token, $clientId);
    }

    public function test_google_id_token_verification_handles_key_rotation_successfully(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        // 1. Simular caché inicial con solo la clave 1
        \Illuminate\Support\Facades\Cache::put(
            GoogleAuthService::JWKS_CACHE_KEY,
            $this->getTestJwksResponse(['key1']),
            3600
        );

        // 2. Google publica el JWKS actualizado con la clave 1 y la nueva clave rotada 2
        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response(
                $this->getTestJwksResponse(['key1', 'key2']),
                200
            ),
        ]);

        // 3. Llega un token firmado con la nueva clave rotada (key2)
        $rotatedToken = $this->makeGoogleIdToken([
            'sub' => 'sub-post-rotation',
            'email' => 'asesor@ejemplo.test',
        ], 'key2');

        $service = new GoogleAuthService();

        // El servicio debe detectar la ausencia del kid en caché, refrescar contra Google y validar con éxito
        $claims = $service->verifyGoogleIdToken($rotatedToken, $clientId);

        $this->assertEquals('sub-post-rotation', $claims['sub']);
        $this->assertEquals('asesor@ejemplo.test', $claims['email']);
    }

    public function test_google_id_token_verification_rejects_unknown_key_id_after_refresh(): void
    {
        $clientId = 'test-client-id.apps.googleusercontent.com';
        config(['services.google.client_id' => $clientId]);

        Http::fake([
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
        ]);

        $key1 = $this->getTestGoogleKey('key1');
        $payload = [
            'iss' => 'https://accounts.google.com',
            'aud' => $clientId,
            'sub' => 'sub-unknown-kid',
            'email' => 'asesor@ejemplo.test',
            'email_verified' => true,
            'exp' => time() + 3600,
        ];
        $tokenWithUnknownKid = \Firebase\JWT\JWT::encode($payload, $key1['priv'], 'RS256', 'completely-unknown-kid');

        $service = new GoogleAuthService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Clave pública con kid 'completely-unknown-kid' no encontrada");

        $service->verifyGoogleIdToken($tokenWithUnknownKid, $clientId);
    }

    public function test_full_oauth_callback_flow_succeeds_with_verified_id_token_and_userinfo(): void
    {
        $user = User::create([
            'email' => 'admin@tusolucionfinanciera.cl',
            'name' => 'Administrador Preautorizado',
            'role' => 'admin',
            'active' => true,
            'google_subject' => null,
        ]);

        $clientId = 'test-client-id.apps.googleusercontent.com';
        config([
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect_uri' => 'https://crm.tusolucionfinanciera.cl/auth/google/callback',
        ]);

        session(['oauth_google_state' => 'state-flow-123']);

        $idToken = $this->makeGoogleIdToken([
            'sub' => 'google-sub-flow-verified',
            'email' => 'admin@tusolucionfinanciera.cl',
            'email_verified' => true,
            'name' => 'Admin Nombre',
        ], 'key1');

        Http::fake([
            GoogleAuthService::GOOGLE_TOKEN_URL => Http::response([
                'access_token' => 'mock-access-token-flow',
                'id_token' => $idToken,
            ], 200),
            GoogleAuthService::GOOGLE_JWKS_URL => Http::response($this->getTestJwksResponse(['key1']), 200),
            GoogleAuthService::GOOGLE_USERINFO_URL => Http::response([
                'sub' => 'google-sub-flow-verified',
                'email' => 'admin@tusolucionfinanciera.cl',
                'email_verified' => true,
                'name' => 'Admin Nombre',
            ], 200),
        ]);

        $response = $this->get('/auth/google/callback?code=mock-code&state=state-flow-123');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertEquals('google-sub-flow-verified', $user->google_subject);
    }
}
