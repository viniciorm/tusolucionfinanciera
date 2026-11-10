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

    public function test_google_id_token_verification_validates_claims_and_expiration(): void
    {
        $service = new GoogleAuthService();
        $expectedAudience = 'my-client-id.apps.googleusercontent.com';

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'iss' => 'https://accounts.google.com',
                'aud' => $expectedAudience,
                'sub' => 'google-sub-tokeninfo-123',
                'email' => 'valido@ejemplo.test',
                'email_verified' => 'true',
                'name' => 'Nombre Verificado',
                'exp' => time() + 3600,
            ], 200),
        ]);

        $claims = $service->verifyGoogleIdToken('mock-id-token-xyz', $expectedAudience);

        $this->assertEquals('google-sub-tokeninfo-123', $claims['sub']);
        $this->assertEquals('valido@ejemplo.test', $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function test_google_id_token_verification_rejects_invalid_token(): void
    {
        $service = new GoogleAuthService();

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'error' => 'invalid_token',
                'error_description' => 'Invalid Value',
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es válido');

        $service->verifyGoogleIdToken('invalid-token', 'my-client-id');
    }
}
