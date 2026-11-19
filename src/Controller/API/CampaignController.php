<?php

declare(strict_types=1);

namespace App\Controller\API;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use App\Service\Campaign\CampaignTracker;
use App\Service\Campaign\VisitorId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public: what the site shows a visitor who arrived from a campaign QR
 * (?abm=<slug>&abm_v=<visitor>). Also records the "landing" funnel step.
 */
#[Route(path: 'api/v1/campaign')]
class CampaignController extends AbstractController
{
    #[Route('/{slug}', name: 'public_campaign_offer', requirements: ['slug' => Campaign::SLUG_PATTERN], methods: [Request::METHOD_GET])]
    public function offer(
        string $slug,
        Request $request,
        CampaignRepository $campaigns,
        CampaignTracker $tracker,
        RateLimiterFactoryInterface $eventLimiter,
    ): JsonResponse {
        if (!$eventLimiter->create($request->getClientIp() ?? 'anon')->consume(1)->isAccepted()) {
            return $this->json(['ok' => false], 429);
        }

        $campaign = $campaigns->findBySlug($slug);
        if (null === $campaign || !$campaign->isActive()) {
            return $this->json(['ok' => false], 404);
        }

        $visitorId = VisitorId::normalize($request->query->get('v'));
        if (null !== $visitorId) {
            $tracker->landed($visitorId, (int) $campaign->getId());
        }

        $promocode = $campaign->getPromocode();
        $offer = null;
        $now = new \DateTime();
        if (null !== $promocode && $promocode->isActive()
            && (null === $promocode->getValidTo() || $promocode->getValidTo() >= $now)
            && (null === $promocode->getValidFrom() || $promocode->getValidFrom() <= $now)) {
            $offer = [
                'code' => $promocode->getCode(),
                'discount_type' => $promocode->getDiscountType()->value,
                'discount_value' => $promocode->getValue(),
                'currency' => $promocode->getCurrency()->value,
                // Calendar date as stored (inclusive) — no timezone shift on the client.
                'valid_until' => $promocode->getValidTo()?->format('Y-m-d'),
                'min_order_amount' => $promocode->getMinOrderAmount(),
            ];
        }

        $response = $this->json([
            'ok' => true,
            'slug' => $campaign->getSlug(),
            'attribution_days' => $campaign->getAttributionDays(),
            'offer' => $offer,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
