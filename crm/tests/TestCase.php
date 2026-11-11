<?php

namespace Tests;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use InvalidArgumentException;

abstract class TestCase extends BaseTestCase
{
    protected function getTestGoogleKey(string $keyName = 'key1'): array
    {
        $fixturePath = __DIR__ . '/fixtures/test_google_jwks.json';
        $data = json_decode(file_get_contents($fixturePath), true);
        return $data[$keyName] ?? throw new InvalidArgumentException("Test key {$keyName} not found in fixtures");
    }

    protected function getTestJwksResponse(array $keyNames = ['key1']): array
    {
        $keys = [];
        foreach ($keyNames as $name) {
            $keyData = $this->getTestGoogleKey($name);
            $keys[] = $keyData['jwk'];
        }
        return ['keys' => $keys];
    }

    protected function makeGoogleIdToken(array $overrides = [], string $keyName = 'key1'): string
    {
        $keyData = $this->getTestGoogleKey($keyName);
        $payload = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => config('services.google.client_id', 'test-client-id.apps.googleusercontent.com'),
            'sub' => 'google-sub-test-12345',
            'email' => 'asesor@ejemplo.test',
            'email_verified' => true,
            'name' => 'Usuario Test',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides);

        return JWT::encode($payload, $keyData['priv'], 'RS256', $keyData['kid']);
    }
}
