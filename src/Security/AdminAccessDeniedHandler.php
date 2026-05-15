<?php

namespace App\Security;

use App\Entity\TelegramUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * Renders the friendly "no admin access" page when a logged-in TelegramUser
 * hits an /admin/* path without ROLE_MANAGER. Without this, the user gets the
 * default Symfony 403 — confusing UX after a successful Telegram widget login.
 *
 * Anonymous users (no auth) on /admin are redirected to '/', where SiteController
 * shows the public landing with the Telegram login widget.
 */
final class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $user = $this->security->getUser();

        if ($user instanceof TelegramUser) {
            $html = $this->twig->render('login/site/no-permission.html.twig', [
                'user' => $user,
            ]);

            return new Response($html, Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('login_public'),
            Response::HTTP_FOUND,
        );
    }
}
