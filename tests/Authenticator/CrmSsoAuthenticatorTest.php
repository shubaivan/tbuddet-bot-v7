<?php

namespace App\Tests\Authenticator;

use App\Authenticator\CrmSsoAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * Пропуск із CRM (zayavky: App\Service\ShopLink) — формат мусить збігатися байт
 * у байт: base64url(json).base64url(HMAC-SHA256).
 */
class CrmSsoAuthenticatorTest extends TestCase
{
    private const SECRET = 'shared-secret';

    public function testValidPassIsAccepted(): void
    {
        $pass = CrmSsoAuthenticator::verify($this->pass(['tg' => '471925876', 'exp' => 2000, 'nonce' => 'ab12', 'to' => '/admin/products']), self::SECRET, 1990);

        self::assertSame('471925876', $pass['tg'] ?? null);
        self::assertSame('/admin/products', $pass['to'] ?? null);
    }

    public function testExpiredPassIsRefused(): void
    {
        self::assertNull(CrmSsoAuthenticator::verify($this->pass(['tg' => '1', 'exp' => 2000, 'nonce' => 'a', 'to' => '/admin']), self::SECRET, 2001));
    }

    public function testForgedOrForeignPassIsRefused(): void
    {
        $pass = $this->pass(['tg' => '1', 'exp' => 2000, 'nonce' => 'a', 'to' => '/admin']);

        self::assertNull(CrmSsoAuthenticator::verify($pass, 'other-secret', 1990), 'чужий секрет');
        self::assertNull(CrmSsoAuthenticator::verify($pass . 'x', self::SECRET, 1990), 'зіпсований підпис');
        self::assertNull(CrmSsoAuthenticator::verify($pass, '', 1990), 'без секрету вхід вимкнено');
        self::assertNull(CrmSsoAuthenticator::verify('garbage', self::SECRET, 1990));
    }

    public function testTargetOutsideAdminFallsBackToOrders(): void
    {
        $pass = CrmSsoAuthenticator::verify($this->pass(['tg' => '1', 'exp' => 2000, 'nonce' => 'a', 'to' => 'https://evil.example']), self::SECRET, 1990);

        self::assertSame('/admin/orders', $pass['to'] ?? null);
    }

    private function pass(array $data): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, self::SECRET, true)), '+/', '-_'), '=');
    }
}
