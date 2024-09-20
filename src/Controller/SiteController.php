<?php

namespace App\Controller;

use App\Entity\TelegramUser;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(name: 'login_')]
class SiteController extends AbstractController
{
    #[Route(path: '/', name: 'public')]
    public function publicAction(): Response
    {
        return $this->render('login/site/public.html.twig');
    }

    #[Route(path: '/private', name: 'private')]
    #[IsGranted('ROLE_USER')]
    public function privateAction(Nutgram $bot): Response
    {
        $user = $this->getUser();
        if (!$user instanceof TelegramUser) {
            throw new \LogicException();
        }
        if ($user->getTelegramId()) {
            $bot->sendMessage(
                text: 'Hello from private area!',
                chat_id: $user->getTelegramId(),
                parse_mode: ParseMode::HTML
            );
        }

        return $this->render('login/site/private.html.twig');
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    public function widgetAction(string $telegramBotName): Response
    {
        return $this->render('login/site/widget.html.twig', [
            'telegram_bot_name' => $telegramBotName,
        ]);
    }
}
