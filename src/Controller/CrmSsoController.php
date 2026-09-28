<?php

namespace App\Controller;

use App\Authenticator\CrmSsoAuthenticator;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Єдина точка входу — CRM. Тут лише два маршрути, що це забезпечують:
 * пропуск із CRM і вихід, який виводить і з CRM теж.
 */
class CrmSsoController extends AbstractController
{
    /** Тіло не виконується — запит перехоплює CrmSsoAuthenticator. */
    #[Route('/admin/sso', name: CrmSsoAuthenticator::ROUTE, methods: ['GET'])]
    public function sso(): Response
    {
        throw new LogicException('Має перехопити CrmSsoAuthenticator.');
    }

    /**
     * Після виходу з магазину — вихід і з CRM: вхід один, то й вихід один.
     * Без CRM_URL (локально) — на стару сторінку входу.
     */
    #[Route('/admin/signed-out', name: 'admin_signed_out', methods: ['GET'])]
    public function signedOut(#[Autowire('%env(CRM_URL)%')] string $crmUrl): Response
    {
        return $crmUrl !== ''
            ? new RedirectResponse(rtrim($crmUrl, '/') . '/crm/logout')
            : $this->redirectToRoute('login_public');
    }
}
