<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Діалог консультанта, що почався з телефонного «входу» у віджеті.
 *
 * Віджет отримує підписаний токен (id.hmac) і шле його з кожним повідомленням —
 * без токена /chat/message не відповідає, тож гейт не обходиться простим
 * запитом до API. Тут же накопичується статистика діалогу (питання, відповіді,
 * токени, посилання) для звіту «💬 Діалог завершено».
 *
 * Зберігається в пулі support_chat (поза var/cache — деплой не скидає).
 */
class ChatDialogStore
{
    /** Тиша довша за це — діалог вважається завершеним (крон-підмітання). */
    public const INACTIVITY_SECONDS = 1200;

    private const TTL = 172800;
    private const OPEN_INDEX = 'chat_dialogs_open';

    public function __construct(
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly LockFactory $lockFactory,
        #[\SensitiveParameter] private readonly string $appSecret,
    ) {
    }

    /**
     * @return array{id:string, token:string}
     */
    public function create(string $name, string $phone, string $page): array
    {
        $id = bin2hex(random_bytes(12));
        $now = time();
        $this->save([
            'id' => $id,
            'name' => $name,
            'phone' => $phone,
            'page' => $page,
            'startedAt' => $now,
            'lastAt' => $now,
            'questions' => 0,
            'replies' => 0,
            'input' => 0,
            'output' => 0,
            'operator' => false,
            'links' => [],
            'messages' => [],
            'ended' => false,
        ]);

        $this->withIndex(static function (array $ids) use ($id): array {
            $ids[] = $id;

            return $ids;
        });

        return ['id' => $id, 'token' => self::sign($id, $this->appSecret)];
    }

    public static function sign(string $id, string $secret): string
    {
        return $id.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', 'chat-dialog:'.$id, $secret, true)), '+/', '-_'), '=');
    }

    /** Повертає id діалогу, якщо підпис правильний, інакше null. */
    public static function verify(?string $token, string $secret): ?string
    {
        if (null === $token || !preg_match('/^([a-f0-9]{24})\.[A-Za-z0-9_-]+$/', $token, $m)) {
            return null;
        }

        return hash_equals(self::sign($m[1], $secret), $token) ? $m[1] : null;
    }

    public function idFromToken(?string $token): ?string
    {
        return self::verify($token, $this->appSecret);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $item = $this->supportChatCache->getItem('chat_dialog_'.$id);
        $v = $item->isHit() ? $item->get() : null;

        return \is_array($v) ? $v : null;
    }

    /**
     * Атомарно змінює діалог (кілька вкладок можуть писати одночасно).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     *
     * @return array<string, mixed>|null
     */
    public function update(string $id, callable $fn): ?array
    {
        $lock = $this->lockFactory->createLock('chat_dialog_'.$id, 10);
        $lock->acquire(true);
        try {
            $d = $this->get($id);
            if (null === $d) {
                return null;
            }
            $d = $fn($d);
            $this->save($d);

            return $d;
        } finally {
            $lock->release();
        }
    }

    /**
     * Позначає діалог завершеним рівно один раз. Повертає дані лише тому, хто
     * завершив першим (beacon із вкладки чи крон), іншим — null.
     *
     * @return array<string, mixed>|null
     */
    public function markEnded(string $id): ?array
    {
        $won = false;
        $d = $this->update($id, static function (array $d) use (&$won): array {
            if (!$d['ended']) {
                $d['ended'] = true;
                $d['endedAt'] = time();
                $won = true;
            }

            return $d;
        });

        $this->withIndex(static fn (array $ids): array => array_values(array_diff($ids, [$id])));

        return $won ? $d : null;
    }

    /** @return list<string> id відкритих діалогів, у яких тиша довша за $seconds */
    public function idle(int $seconds = self::INACTIVITY_SECONDS, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        $gone = [];
        foreach ($this->openIds() as $id) {
            $d = $this->get($id);
            if (null === $d || $d['ended']) {
                $gone[] = $id;
                continue;
            }
            if ($now - (int) $d['lastAt'] >= $seconds) {
                $out[] = $id;
            }
        }
        if ([] !== $gone) {
            $this->withIndex(static fn (array $ids): array => array_values(array_diff($ids, $gone)));
        }

        return $out;
    }

    /** @return list<string> */
    private function openIds(): array
    {
        $item = $this->supportChatCache->getItem(self::OPEN_INDEX);
        $v = $item->isHit() ? $item->get() : [];

        return \is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /** @param callable(list<string>): list<string> $fn */
    private function withIndex(callable $fn): void
    {
        $lock = $this->lockFactory->createLock(self::OPEN_INDEX, 10);
        $lock->acquire(true);
        try {
            $ids = array_values(array_unique($fn($this->openIds())));
            $item = $this->supportChatCache->getItem(self::OPEN_INDEX);
            $item->set($ids)->expiresAfter(self::TTL);
            $this->supportChatCache->save($item);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $d */
    private function save(array $d): void
    {
        $item = $this->supportChatCache->getItem('chat_dialog_'.$d['id']);
        $item->set($d)->expiresAfter(self::TTL);
        $this->supportChatCache->save($item);
    }
}
