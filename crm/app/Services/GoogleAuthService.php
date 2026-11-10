<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use TSF\Security\GoogleAccountBinding;

class GoogleAuthService
{
    public const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function getAuthorizationUrl(Request $request): string
    {
        $clientId = config('services.google.client_id');
        $redirectUri = config('services.google.redirect_uri');

        if (empty($clientId) || empty($redirectUri)) {
            throw new RuntimeException('Google OAuth client_id o redirect_uri no están configurados.');
        }

        $state = Str::random(40);
        $request->session()->put('oauth_google_state', $state);

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ];

        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Valida el callback de Google OAuth y devuelve el usuario autorizado.
     *
     * @throws RuntimeException si la autenticación o autorización es rechazada
     */
    public function handleCallback(Request $request): User
    {
        $stateIncoming = (string) $request->query('state', '');
        $stateSession = (string) $request->session()->pull('oauth_google_state', '');

        if ($stateSession === '' || !hash_equals($stateSession, $stateIncoming)) {
            AuditEvent::log('auth.csrf_state_invalid', null, null, [
                'ip' => $request->ip(),
            ]);
            throw new RuntimeException('Validación de estado CSRF inválida.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            $error = (string) $request->query('error', 'unknown');
            AuditEvent::log('auth.google_cancelled', null, null, ['error' => $error]);
            throw new RuntimeException('Google no devolvió un código de autorización.');
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirectUri = config('services.google.redirect_uri');

        // Intercambiar código por tokens
        $response = Http::asForm()->timeout(10)->post(self::GOOGLE_TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            AuditEvent::log('auth.token_exchange_failed', null, null, [
                'status' => $response->status(),
            ]);
            throw new RuntimeException('Error al intercambiar el código con Google.');
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $idToken = $data['id_token'] ?? null;

        if (!$accessToken || !$idToken) {
            throw new RuntimeException('Respuesta de Google incompleta.');
        }

        // Consultar el endpoint oficial de userinfo de Google con el access token
        $userinfoResponse = Http::withToken($accessToken)->timeout(10)->get(self::GOOGLE_USERINFO_URL);
        if (!$userinfoResponse->successful()) {
            throw new RuntimeException('No se pudo verificar el perfil del usuario con Google.');
        }

        $userinfo = $userinfoResponse->json();
        $googleSubject = (string) ($userinfo['sub'] ?? '');
        $googleEmail = (string) ($userinfo['email'] ?? '');
        $googleEmailVerified = (bool) ($userinfo['email_verified'] ?? false);
        $googleName = (string) ($userinfo['name'] ?? '');

        return $this->authenticateVerifiedIdentity($googleSubject, $googleEmail, $googleEmailVerified, $googleName, $request->ip());
    }

    /**
     * Aplica la regla estricta de autorización y vinculación de cuenta.
     */
    public function authenticateVerifiedIdentity(
        string $googleSubject,
        string $googleEmail,
        bool $googleEmailVerified,
        string $googleName = '',
        ?string $ip = null
    ): User {
        $normalizedEmail = mb_strtolower(trim($googleEmail), 'UTF-8');

        // Buscar si existe una cuenta previamente autorizada en la base de datos
        $user = User::where('email', $normalizedEmail)->first();

        if (!$user) {
            AuditEvent::log('auth.denied_unregistered_account', null, null, [
                'ip' => $ip,
                'email_domain' => substr(strrchr($normalizedEmail, "@") ?: '', 1),
            ]);
            throw new RuntimeException('Esta cuenta no está autorizada para acceder al CRM.');
        }

        $decision = GoogleAccountBinding::decide(
            $user->active,
            $user->email,
            $user->google_subject,
            $normalizedEmail,
            $googleEmailVerified,
            $googleSubject
        );

        if ($decision === 'deny') {
            AuditEvent::log('auth.denied_binding_check', $user->id, null, [
                'active' => $user->active,
                'email_verified' => $googleEmailVerified,
                'has_bound_subject' => ($user->google_subject !== null),
            ]);
            throw new RuntimeException('Acceso denegado: cuenta inactiva, correo no verificado o discrepancia de identidad.');
        }

        if ($decision === 'bind') {
            // Vinculación atómica inicial con bloqueo de fila y control de colisión
            DB::transaction(function () use ($user, $googleSubject, $googleName) {
                /** @var User $lockedUser */
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                // Verificar que otro proceso concurrente no haya vinculado el mismo google_subject
                $existingSubject = User::where('google_subject', $googleSubject)->first();
                if ($existingSubject && $existingSubject->id !== $lockedUser->id) {
                    throw new RuntimeException('Identificador de Google ya vinculado a otra cuenta.');
                }

                $lockedUser->google_subject = $googleSubject;
                if (empty($lockedUser->name) && !empty($googleName)) {
                    $lockedUser->name = $googleName;
                }
                $lockedUser->save();

                AuditEvent::log('auth.bound_google_account', $lockedUser->id, null, [
                    'role' => $lockedUser->role,
                ]);
            });

            // Refrescar usuario actualizado
            $user->refresh();
        }

        AuditEvent::log('auth.login_success', $user->id, null, [
            'role' => $user->role,
            'ip' => $ip,
        ]);

        return $user;
    }
}
