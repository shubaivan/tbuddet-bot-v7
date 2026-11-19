<?php

declare(strict_types=1);

namespace App\Service\Campaign;

use App\Entity\CampaignEvent;
use App\Entity\User;
use App\Entity\UserOrder;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Attributes shop actions to a QR campaign (first touch, per-campaign window).
 *
 * Who is the visitor: the X-Abm-Visitor header the site sends after landing from
 * a QR (localStorage), else the in-window visitor already linked to the account.
 * Every public method is best-effort — tracking must never break checkout,
 * login or the cart, so all errors are logged and swallowed.
 */
class CampaignTracker
{
    public function __construct(
        private readonly CampaignStore $store,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Registration (email or Telegram) — links the visitor to the new account. */
    public function registered(User $user): void
    {
        $this->userEvent(CampaignEvent::REGISTER, $user);
    }

    public function loggedIn(User $user): void
    {
        $this->userEvent(CampaignEvent::LOGIN, $user);
    }

    public function addedToCart(User $user): void
    {
        $this->userEvent(CampaignEvent::ADD_TO_CART, $user);
    }

    /**
     * Snapshot the campaign on a freshly created order. QR visit in the window
     * wins; otherwise an order that used a campaign's promocode is attributed to
     * that campaign as source "promocode" (typed the printed code, no scan).
     * Call after the order has been flushed (needs its id).
     */
    public function attributeOrder(UserOrder $order, ?User $user, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        try {
            $orderId = $order->getId();
            if (null === $orderId) {
                return;
            }
            $visitor = $this->resolveVisitor($user, $now);
            if (null !== $visitor) {
                if (null !== $user) {
                    $this->store->linkVisitorToUser($visitor['visitor_id'], (int) $user->getId(), $now);
                }
                if ($this->store->setOrderCampaign($orderId, $visitor['campaign_id'], $visitor['visitor_id'], 'qr')) {
                    $this->store->insertEvent($visitor['campaign_id'], $visitor['visitor_id'], CampaignEvent::ORDER, $user?->getId(), $orderId, $now);
                }

                return;
            }

            $code = $order->getPromocodeCodeUsed();
            if (null !== $code && '' !== $code) {
                $campaignId = $this->store->findCampaignIdByPromocodeCode($code);
                if (null !== $campaignId && $this->store->setOrderCampaign($orderId, $campaignId, null, 'promocode')) {
                    $this->store->insertEvent($campaignId, null, CampaignEvent::ORDER, $user?->getId(), $orderId, $now);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('campaign: order attribution failed', ['order' => $order->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * The site's JS confirmed the visitor actually landed (the real "human" signal).
     * Recorded once per visitor.
     */
    public function landed(string $visitorId, int $campaignId, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        try {
            $visitor = $this->store->findAttributableVisitor($visitorId, $now);
            if (null === $visitor || $visitor['campaign_id'] !== $campaignId) {
                return;
            }
            $this->store->touchVisitor($visitorId, $now);
            $this->store->insertEvent($campaignId, $visitorId, CampaignEvent::LANDING, $visitor['user_id'], null, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('campaign: landing failed', ['error' => $e->getMessage()]);
        }
    }

    private function userEvent(string $type, User $user, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        try {
            $userId = $user->getId();
            if (null === $userId) {
                return;
            }
            $visitor = $this->resolveVisitor($user, $now);
            if (null === $visitor) {
                return;
            }
            $this->store->linkVisitorToUser($visitor['visitor_id'], $userId, $now);
            $this->store->insertEvent($visitor['campaign_id'], $visitor['visitor_id'], $type, $userId, null, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('campaign: event failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{visitor_id: string, campaign_id: int, user_id: ?int}|null
     */
    private function resolveVisitor(?User $user, \DateTimeImmutable $now): ?array
    {
        $headerId = VisitorId::normalize($this->requestStack->getMainRequest()?->headers->get(VisitorId::HEADER));
        if (null !== $headerId) {
            $visitor = $this->store->findAttributableVisitor($headerId, $now);
            if (null !== $visitor) {
                return $visitor;
            }
        }

        if (null !== $user && null !== $user->getId()) {
            return $this->store->findAttributableVisitorForUser((int) $user->getId(), $now);
        }

        return null;
    }
}
