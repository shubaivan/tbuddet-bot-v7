<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Entity\User;
use App\Entity\UserMerge;
use App\Repository\TelegramUserRepository;
use App\Repository\UserMergeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUserService
{
    private ?TelegramUser $currentUser;

    public function __construct(
        private TelegramUserRepository $telegramUserRepository,
        private UserRepository $userRepository,
        private UserMergeRepository $userMergeRepository,
        private EntityManagerInterface $em
    ) {}

    public function initUser(array $from)
    {
        $telegramUser = new TelegramUser();
        $this->currentUser = $this->telegramUserRepository->getByTelegramId($from['id']);
        if (!$this->currentUser) {
            $telegramUser->setTelegramId($from['id']);

            if (isset($from['first_name'])) {
                $telegramUser->setFirstName($from['first_name']);
            }

            if (isset($from['last_name'])) {
                $telegramUser->setLastName($from['last_name']);
            }

            if (isset($from['username'])) {
                $telegramUser->setUsername($from['username']);
            }

            if (isset($from['language_code'])) {
                $telegramUser->setLanguageCode($from['language_code']);
            }

            if (isset($from['chat_id'])) {
                $telegramUser->setChatId($from['chat_id']);
            }

            $this->telegramUserRepository->save($telegramUser);

            $this->currentUser = $telegramUser;
        }

        if (isset($from['chat_id'])) {
            $this->currentUser->setChatId($from['chat_id']);
            $this->em->flush();
        }

        return $this->currentUser;
    }

    /**
     * Callers pass only the user's OWN contact (contact.user_id === sender, checked in
     * SaveContactCommand and PriceRingConversation::handlePhone) — so the phone is
     * marked verified (GuestOrderLinker trusts only verified phones).
     */
    public function savePhone(string $phone_number): void
    {
        if ($this->currentUser) {
            $normalizePhone = substr($phone_number, -10);
            $user = $this->userRepository->matchUserByPhone($normalizePhone);
            // A web account's profile phone is typed in, never verified: link the bot
            // user to it only when the account already belongs to this Telegram user
            // (logged in with Telegram / linked from the profile). Otherwise anyone who
            // registered with a stranger's number would get the stranger's bot orders
            // and Telegram login.
            if ($user && self::isOwnAccount($user, $this->currentUser)) {
                $merge = $this->userMergeRepository->getByUser($user);
                if (!$merge) {
                    $merge = new UserMerge();
                    $this->em->persist($merge);
                }
                $merge->setUser($user)->setTelegramUser($this->currentUser);
            }
        }

        $this->currentUser->setPhoneNumber($phone_number);
        $this->currentUser->setPhoneVerifiedAt(new \DateTimeImmutable());
    }

    public function getCurrentUser(): ?TelegramUser
    {
        return $this->currentUser;
    }

    /** The web account's own Telegram identity is this bot user (set by the Telegram login or the profile link). */
    public static function isOwnAccount(User $user, TelegramUser $telegramUser): bool
    {
        return null !== $user->getTelegramChatId()
            && (string) $user->getTelegramChatId() === (string) $telegramUser->getTelegramId();
    }
}
