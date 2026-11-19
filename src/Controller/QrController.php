<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Campaign\QrScanService;
use App\Service\Campaign\ScanContext;
use App\Service\Campaign\VisitorId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Target of printed QR codes: https://artbeton.market/q/{slug}.
 * nginx on artbeton.market passes /q/ straight to this app (php-fpm), so the
 * scan is logged with the real client IP, then 302 to the shop.
 */
class QrController extends AbstractController
{
    #[Route('/q/{slug}', name: 'public_qr_scan', requirements: ['slug' => '[^/]{1,64}'], methods: ['GET', 'HEAD'])]
    public function scan(string $slug, Request $request, QrScanService $qrScanService): RedirectResponse
    {
        $result = $qrScanService->handle($slug, ScanContext::fromRequest($request));

        $response = new RedirectResponse($result->redirectUrl, 302);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if (null !== $result->cookieVisitorId) {
            $response->headers->setCookie(Cookie::create(
                VisitorId::COOKIE,
                $result->cookieVisitorId,
                new \DateTimeImmutable(sprintf('+%d days', $result->cookieDays)),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
