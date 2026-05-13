<?php

namespace App\Controller\API;

use App\Entity\Enum\RoleEnum;
use App\Entity\User;
use App\Service\TelegramLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: 'api/v1/profile/telegram')]
class ProfileTelegramController extends AbstractController
{
    #[IsGranted(RoleEnum::USER->value)]
    #[Route('', name: 'api_profile_telegram_status', methods: [Request::METHOD_GET])]
    public function status(#[CurrentUser] User $user): JsonResponse
    {
        // `linked` = bot can DM this user (only true after /start, when chat_id is set).
        // `tg_authed` = the user proved ownership of a Telegram account (e.g. via the
        //   web login widget) — known whenever user_merge links them to a telegram_user
        //   even if they haven't pressed /start yet. Used by the FE to swap the CTA copy
        //   from "Connect Telegram" → "Enable Telegram notifications".
        return new JsonResponse([
            'linked'    => $user->getTelegramChatId() !== null,
            'chat_id'   => $user->getTelegramChatId(),
            'tg_authed' => $user->getMerge()?->getTelegramUser() !== null,
        ]);
    }

    #[IsGranted(RoleEnum::USER->value)]
    #[Route('/link', name: 'api_profile_telegram_link', methods: [Request::METHOD_POST])]
    public function link(
        #[CurrentUser] User $user,
        TelegramLinkService $linkService,
    ): JsonResponse {
        return new JsonResponse($linkService->issueLinkUrl($user));
    }

    #[IsGranted(RoleEnum::USER->value)]
    #[Route('', name: 'api_profile_telegram_unlink', methods: [Request::METHOD_DELETE])]
    public function unlink(
        #[CurrentUser] User $user,
        TelegramLinkService $linkService,
    ): JsonResponse {
        $linkService->unlink($user);
        return new JsonResponse(['linked' => false]);
    }
}
