<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Analytics\ActivityService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Покупець увійшов на сайт поштою й паролем (/api/login_check) — сповіщення
 * в тему «Магазин». Вхід через Telegram сповіщає TelegramAuthController.
 *
 * Тільки JsonLoginAuthenticator: оновлення JWT-токена — теж «успішний вхід»
 * для Symfony, але людина нічого не робила, і такі повідомлення були б шумом.
 */
#[AsEventListener]
final class CustomerLoginListener
{
    public function __construct(
        private readonly ActivityService $activity,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$event->getAuthenticator() instanceof JsonLoginAuthenticator || !$user instanceof User) {
            return;
        }

        try {
            $this->activity->customerSignedIn(
                $user,
                false,
                'email',
                $this->urls->generate('app_admin_user_detail', ['source' => 'web', 'id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable) {
        }
    }
}
