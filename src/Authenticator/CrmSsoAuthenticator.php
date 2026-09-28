<?php

namespace App\Authenticator;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Вхід в адмінку магазину з CRM — єдиної точки входу (zayavky.artbeton.market).
 *
 * Люди заходять в одне місце й одним способом — посиланням із бота в CRM, —
 * а пункт «🛒 Магазин» там видає пропуск сюди:
 *
 *     /admin/sso?t=base64url(json{tg, exp, nonce, to}).base64url(HMAC-SHA256)
 *
 * Секрет спільний (CRM_SSO_SECRET тут = SHOP_SSO_SECRET у CRM). Пропуск живе
 * хвилину й одноразовий: nonce запам'ятовуємо, повтор відхиляємо. Людину
 * впізнаємо за Telegram-акаунтом — він той самий в обох системах, — і пускаємо
 * лише того, хто тут має роль менеджера.
 */
class CrmSsoAuthenticator extends AbstractAuthenticator
{
    public const ROUTE = 'admin_crm_sso';

    public function __construct(
        private UserLoaderInterface $userLoader,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(CRM_SSO_SECRET)%')]
        private string $secret,
        #[Autowire('%env(CRM_URL)%')]
        private string $crmUrl,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === self::ROUTE;
    }

    public function authenticate(Request $request): Passport
    {
        $pass = self::verify((string) $request->query->get('t'), $this->secret);

        if ($pass === null) {
            throw new CustomUserMessageAuthenticationException('Посилання на магазин недійсне або застаріло. Відкрийте «🛒 Магазин» у CRM ще раз.');
        }

        $nonce = $this->cache->getItem('crm_sso_' . preg_replace('/[^a-f0-9]/', '', $pass['nonce']));

        if ($nonce->isHit()) {
            throw new CustomUserMessageAuthenticationException('Це посилання вже використане. Відкрийте «🛒 Магазин» у CRM ще раз.');
        }

        $this->cache->save($nonce->set(true)->expiresAfter(300));

        $user = $this->userLoader->loadByTelegramId($pass['tg']);

        if ($user === null || ! in_array('ROLE_MANAGER', $user->getRoles(), true) && ! in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new CustomUserMessageAuthenticationException('Для вашого Telegram-акаунта немає доступу до магазину. Попросіть Івана видати роль менеджера.');
        }

        $request->attributes->set('_crm_sso_to', $pass['to']);

        return new SelfValidatingPassport(new UserBadge($pass['tg'], fn (string $id) => $this->userLoader->loadByTelegramId($id)));
    }

    /**
     * Розібрати й перевірити пропуск. null — підпис не той, строк минув чи формат зламаний.
     *
     * @return array{tg: string, exp: int, nonce: string, to: string}|null
     */
    public static function verify(string $pass, string $secret, ?int $now = null): ?array
    {
        if ($secret === '' || substr_count($pass, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $pass);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($data) || ! isset($data['tg'], $data['exp'], $data['nonce'], $data['to'])) {
            return null;
        }

        if ((int) $data['exp'] < ($now ?? time())) {
            return null;
        }

        $to = (string) $data['to'];

        return [
            'tg' => (string) $data['tg'],
            'exp' => (int) $data['exp'],
            'nonce' => (string) $data['nonce'],
            // Лише власні сторінки адмінки — інакше пропуск став би відкритим редиректом.
            'to' => preg_match('#^/admin(/[A-Za-z0-9/_-]*)?$#', $to) ? $to : '/admin/orders',
        ];
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse((string) $request->attributes->get('_crm_sso_to', '/admin/orders'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $crm = rtrim($this->crmUrl, '/');

        return new Response(
            sprintf(
                '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Магазин</title>'
                . '<div style="font:16px/1.5 system-ui;max-width:26rem;margin:15vh auto;padding:24px;border:1px solid #e3e6ea;border-radius:12px">'
                . '<p>%s</p>%s</div>',
                htmlspecialchars($exception->getMessageKey(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $crm !== '' ? sprintf('<p><a href="%s/crm/">← Повернутись у CRM</a></p>', htmlspecialchars($crm, ENT_QUOTES)) : '',
            ),
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
