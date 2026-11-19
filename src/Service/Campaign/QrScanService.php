<?php

declare(strict_types=1);

namespace App\Service\Campaign;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles one hit on /q/{slug}: log it, mint/reuse the anonymous visitor id and
 * build the redirect to the shop. Never throws — a printed QR must always land
 * somewhere useful even if the DB hiccups or the campaign was switched off.
 */
class QrScanService
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignStore $store,
        private readonly BotDetector $botDetector,
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
    ) {
    }

    public function handle(string $rawSlug, ScanContext $ctx, ?\DateTimeImmutable $now = null): ScanResult
    {
        $now ??= new \DateTimeImmutable();
        $slug = Campaign::normalizeSlug($rawSlug);
        $botReason = $this->botDetector->detect($ctx->userAgent, $ctx->method, $ctx->headers);

        $campaign = null;
        try {
            $campaign = Campaign::isValidSlug($slug) ? $this->campaigns->findBySlug($slug) : null;
        } catch (\Throwable $e) {
            $this->logger->error('qr: campaign lookup failed', ['slug' => $slug, 'error' => $e->getMessage()]);
        }
        $live = null !== $campaign && $campaign->isActive();

        $visitorId = null;
        $isRepeat = false;
        if ($live && null === $botReason) {
            try {
                [$visitorId, $isRepeat] = $this->resolveVisitor($campaign, $ctx->cookieVisitorId, $now);
            } catch (\Throwable $e) {
                $this->logger->error('qr: visitor upsert failed', ['slug' => $slug, 'error' => $e->getMessage()]);
                $visitorId = null;
            }
        }

        try {
            $this->store->insertScan([
                'campaign_id' => $campaign?->getId(),
                'slug' => $slug,
                'visitor_id' => $visitorId,
                'ip' => $ctx->ip,
                'user_agent' => $ctx->userAgent,
                'referer' => $ctx->referer,
                'accept_language' => $ctx->acceptLanguage,
                'is_bot' => null !== $botReason,
                'bot_reason' => $botReason,
                'is_repeat' => $isRepeat,
            ], $now);
        } catch (\Throwable $e) {
            $this->logger->error('qr: scan insert failed', ['slug' => $slug, 'error' => $e->getMessage()]);
        }

        if (!$live) {
            return new ScanResult($this->buildUrl('/uk/', ['utm_source' => 'qr', 'utm_medium' => 'offline', 'utm_campaign' => $slug]));
        }

        $query = ['abm' => $campaign->getSlug()];
        if (null !== $visitorId) {
            $query['abm_v'] = $visitorId;
        }
        $query += ['utm_source' => 'qr', 'utm_medium' => 'offline', 'utm_campaign' => $campaign->getSlug()];

        return new ScanResult(
            $this->buildUrl($campaign->getTargetPath(), $query),
            $visitorId,
            $campaign->getAttributionDays(),
        );
    }

    /**
     * A returning device keeps its id (and its original first touch, even if it
     * was another campaign). Otherwise mint a new visitor for this campaign.
     *
     * @return array{0: string, 1: bool} [visitor id, is repeat]
     */
    private function resolveVisitor(Campaign $campaign, ?string $cookieVisitorId, \DateTimeImmutable $now): array
    {
        $cookieVisitorId = VisitorId::normalize($cookieVisitorId);
        if (null !== $cookieVisitorId && null !== $this->store->findVisitor($cookieVisitorId)) {
            $this->store->touchVisitor($cookieVisitorId, $now);

            return [$cookieVisitorId, true];
        }

        $visitorId = VisitorId::generate();
        $this->store->createVisitor($visitorId, (int) $campaign->getId(), $now);

        return [$visitorId, false];
    }

    /**
     * @param array<string, string> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $base = rtrim($this->frontendUrl, '/');
        if ('' === $base || str_contains($base, '!ChangeMe!')) {
            $base = 'https://artbeton.market';
        }
        $sep = str_contains($path, '?') ? '&' : '?';

        return $base.$path.$sep.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
