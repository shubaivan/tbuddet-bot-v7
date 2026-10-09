<?php

declare(strict_types=1);

namespace App\Service\Seo;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Токен доступу сервісного акаунта Google на одну область (scope). Без SDK:
 * JWT підписуємо openssl'ем — той самий рецепт, що в doshka.
 */
final class GoogleServiceAccount
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var array<string, string> scope → токен */
    private array $tokens = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $gscKeyFile,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->gscKeyFile && is_readable($this->gscKeyFile);
    }

    public function token(string $scope): string
    {
        if (isset($this->tokens[$scope])) {
            return $this->tokens[$scope];
        }

        $key = json_decode((string) file_get_contents($this->gscKeyFile), true, 512, \JSON_THROW_ON_ERROR);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR))
            .'.'.$b64(json_encode([
                'iss' => $key['client_email'],
                'scope' => $scope,
                'aud' => self::TOKEN_URI,
                'iat' => $now,
                'exp' => $now + 3600,
            ], \JSON_THROW_ON_ERROR));

        if (!openssl_sign($unsigned, $signature, $key['private_key'], 'sha256WithRSAEncryption')) {
            throw new \RuntimeException('Google: не вдалося підписати JWT');
        }

        $data = $this->http->request('POST', self::TOKEN_URI, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$b64($signature),
            ],
            'timeout' => 15,
        ])->toArray(false);

        return $this->tokens[$scope] = $data['access_token'] ?? throw new \RuntimeException('Google: токен не видано — '.json_encode($data));
    }
}
