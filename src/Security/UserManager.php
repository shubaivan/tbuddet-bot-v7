<?php

namespace App\Security;

use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class UserManager
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private EntityManagerInterface $em
    ) {}

    public function save(TelegramUser $user): void
    {
        $this->em->persist($user);
        $this->em->flush();

        $item = $this->getCacheItem($user->getTelegramId());
        $item->set($user);
        $this->cache->save($item);
    }

    public function find(string $id): ?TelegramUser
    {
        $item = $this->getCacheItem($id);
        if ($item->isHit()) {
            return $item->get();
        }

        $userRepository = $this->em->getRepository(TelegramUser::class);
        $user = $userRepository->findOneBy(['telegram_id' => $id]);

        return $user ?? null;
    }

    private function getCacheItem(string $id): CacheItemInterface
    {
        return $this->cache->getItem(sprintf('user-%s', $id));
    }
}
