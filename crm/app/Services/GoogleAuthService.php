<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use TSF\Security\GoogleAccountBinding;

class GoogleAuthService
{
    public const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const GOOGLE_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    public const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';
    public const JWKS_CACHE_KEY = 'google_oauth2_jwks';

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
     * Valida el callback de Google OAuth, verifica criptográficamente el id_token y userinfo,
     * y autentica aplicando vinculación segura de identidad.
     *
     * @throws RuntimeException si la autenticación o verificación de identidad falla
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

        // 1. Intercambiar código por tokens
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

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $idToken = $tokenData['id_token'] ?? null;

        if (!$accessToken || !$idToken) {
            throw new RuntimeException('Respuesta de Google incompleta: faltan tokens.');
        }

        // 2. Validación estricta del id_token mediante el endpoint oficial de verificación de Google
        $verifiedClaims = $this->verifyGoogleIdToken((string) $idToken, (string) $clientId);

        // 3. Consulta de perfil al endpoint oficial de userinfo como capa adicional de verificación
        $userinfoResponse = Http::withToken($accessToken)->timeout(10)->get(self::GOOGLE_USERINFO_URL);
        if (!$userinfoResponse->successful()) {
            throw new RuntimeException('No se pudo verificar el perfil del usuario con Google.');
        }
        $userinfo = $userinfoResponse->json();

        // 4. Verificación cruzada entre id_token y userinfo
        $tokenSub = (string) ($verifiedClaims['sub'] ?? '');
        $userinfoSub = (string) ($userinfo['sub'] ?? '');
        if ($tokenSub === '' || !hash_equals($tokenSub, $userinfoSub)) {
            AuditEvent::log('auth.identity_mismatch', null, null, [
                'ip' => $request->ip(),
            ]);
            throw new RuntimeException('Discrepancia en el identificador Google entre id_token y userinfo.');
        }

        $googleEmail = (string) ($verifiedClaims['email'] ?? '');
        $googleEmailVerified = (bool) ($verifiedClaims['email_verified'] ?? false);
        $googleName = (string) ($verifiedClaims['name'] ?? ($userinfo['name'] ?? ''));

        return $this->authenticateVerifiedIdentity($tokenSub, $googleEmail, $googleEmailVerified, $googleName, $request->ip());
    }

    /**
     * Obtiene el conjunto de claves públicas JWKS de Google (https://www.googleapis.com/oauth2/v3/certs).
     * Aplica almacenamiento en caché respetando la directiva Cache-Control con soporte para refresco forzado.
     *
     * @throws RuntimeException
     */
    public function getGooglePublicKeys(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = Cache::get(self::JWKS_CACHE_KEY);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }

        $response = Http::timeout(10)->get(self::GOOGLE_JWKS_URL);
        if (!$response->successful()) {
            throw new RuntimeException('No se pudieron obtener las claves públicas de Google para verificar el id_token.');
        }

        $jwks = $response->json();
        if (!is_array($jwks) || empty($jwks['keys'])) {
            throw new RuntimeException('Formato de claves públicas (JWKS) de Google inválido.');
        }

        $cacheControl = (string) ($response->header('Cache-Control') ?? '');
        $ttl = 3600;
        if (preg_match('/max-age=(\d+)/i', $cacheControl, $matches)) {
            $ttl = (int) $matches[1];
        }

        Cache::put(self::JWKS_CACHE_KEY, $jwks, min(max($ttl, 300), 86400));

        return $jwks;
    }

    /**
     * Verifica criptográficamente el id_token con las claves públicas de Google (JWKS).
     * Valida firma (RS256), emisor, audiencia, expiración y rotación de claves sin recurrir a tokeninfo.
     *
     * @throws RuntimeException
     */
    public function verifyGoogleIdToken(string $idToken, string $expectedAudience): array
    {
        $tokenParts = explode('.', $idToken);
        if (count($tokenParts) !== 3) {
            throw new RuntimeException('Formato de id_token inválido (debe constar de 3 partes).');
        }

        $headerJson = base64_decode(strtr($tokenParts[0], '-_', '+/'), true);
        if (!$headerJson) {
            throw new RuntimeException('Encabezado de id_token no es base64 válido.');
        }

        $header = json_decode($headerJson, true);
        if (!is_array($header)) {
            throw new RuntimeException('Encabezado de id_token no es JSON válido.');
        }

        $kid = (string) ($header['kid'] ?? '');
        $alg = (string) ($header['alg'] ?? '');

        if ($kid === '') {
            throw new RuntimeException('El id_token no contiene kid en el encabezado.');
        }
        if ($alg !== 'RS256') {
            throw new RuntimeException("Algoritmo de firma de id_token no soportado: '{$alg}'. Se requiere RS256.");
        }

        // Obtener claves públicas cacheadas
        $jwks = $this->getGooglePublicKeys(false);
        $keys = JWK::parseKeySet($jwks);

        // Si el kid no existe en el conjunto cacheado, refrescar para manejar rotación de claves
        if (!isset($keys[$kid])) {
            $jwks = $this->getGooglePublicKeys(true);
            $keys = JWK::parseKeySet($jwks);
        }

        if (!isset($keys[$kid])) {
            throw new RuntimeException("Clave pública con kid '{$kid}' no encontrada en las claves públicas de Google tras refrescar certificados.");
        }

        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw new RuntimeException('Firma criptográfica del id_token de Google rechazada: ' . $e->getMessage(), 0, $e);
        }

        $claims = (array) $decoded;

        // Validar emisor (iss)
        $iss = (string) ($claims['iss'] ?? '');
        if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
            throw new RuntimeException("Emisor (iss) de Google no reconocido: '{$iss}'.");
        }

        // Validar audiencia (aud)
        $aud = (string) ($claims['aud'] ?? '');
        if ($aud !== $expectedAudience) {
            throw new RuntimeException('Audiencia (aud) de Google no coincide con el cliente configurado.');
        }

        // Validar expiración (exp)
        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp <= time()) {
            throw new RuntimeException('El id_token de Google ha expirado.');
        }

        // Validar correo verificado
        $emailVerified = $claims['email_verified'] ?? false;
        $claims['email_verified'] = ($emailVerified === true || $emailVerified === 'true');
        if (!$claims['email_verified']) {
            throw new RuntimeException('El correo de Google no está verificado.');
        }

        // Validar subject no vacío
        if (empty($claims['sub'])) {
            throw new RuntimeException('El id_token no contiene un identificador único (sub).');
        }

        return $claims;
    }

    /**
     * Aplica la regla estricta de autorización y vinculación de cuenta con doble comprobación
     * tras adquirir el bloqueo de fila para impedir carreras concurrentes.
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

        // Decisión preliminar fuera de transacción
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
            // Vinculación atómica inicial con bloqueo de fila y DOBLE COMPROBACIÓN posterior
            return DB::transaction(function () use ($user, $googleSubject, $normalizedEmail, $googleEmailVerified, $googleName, $ip) {
                /** @var User $lockedUser */
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                // RE-COMPROBACIÓN ESTRICTA TRAS ADQUIRIR EL BLOQUEO:
                // 1. Estado activo de la cuenta
                if (!$lockedUser->active) {
                    AuditEvent::log('auth.denied_concurrency_inactive', $lockedUser->id, null, ['ip' => $ip]);
                    throw new RuntimeException('Acceso denegado: la cuenta fue desactivada mientras se procesaba el acceso.');
                }

                // 2. Coincidencia estricta de correo autorizado
                if (strcasecmp(trim($lockedUser->email), trim($normalizedEmail)) !== 0) {
                    AuditEvent::log('auth.denied_concurrency_email_mismatch', $lockedUser->id, null, ['ip' => $ip]);
                    throw new RuntimeException('Acceso denegado: discrepancia de correo tras adquirir bloqueo de fila.');
                }

                // 3. Comprobar si una solicitud paralela concurrente ya vinculó esta cuenta
                if ($lockedUser->google_subject !== null) {
                    // Ya vinculada por proceso concurrente: debe coincidir exactamente con el mismo googleSubject
                    if (!hash_equals($lockedUser->google_subject, $googleSubject)) {
                        AuditEvent::log('auth.denied_concurrency_conflict', $lockedUser->id, null, [
                            'ip' => $ip,
                            'existing_subject' => $lockedUser->google_subject,
                            'incoming_subject' => $googleSubject,
                        ]);
                        throw new RuntimeException('Conflicto de concurrencia: la cuenta fue vinculada simultáneamente a otro identificador Google.');
                    }
                } else {
                    // 4. Verificar que el googleSubject no esté vinculado a OTRO usuario del sistema
                    $collision = User::where('google_subject', $googleSubject)
                        ->where('id', '!=', $lockedUser->id)
                        ->first();

                    if ($collision) {
                        AuditEvent::log('auth.denied_subject_collision', $lockedUser->id, null, [
                            'ip' => $ip,
                            'collision_user_id' => $collision->id,
                        ]);
                        throw new RuntimeException('Identificador de Google ya vinculado a otra cuenta de usuario.');
                    }

                    // Establecer la vinculación definitiva
                    $lockedUser->google_subject = $googleSubject;
                }

                if (empty($lockedUser->name) && !empty($googleName)) {
                    $lockedUser->name = $googleName;
                }
                $lockedUser->save();

                AuditEvent::log('auth.bound_google_account', $lockedUser->id, null, [
                    'role' => $lockedUser->role,
                ]);

                return $lockedUser;
            });
        }

        // Caso 'allow': cuenta ya vinculada previamente
        AuditEvent::log('auth.login_success', $user->id, null, [
            'role' => $user->role,
            'ip' => $ip,
        ]);

        return $user;
    }
}
