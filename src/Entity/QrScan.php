<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QrScanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One hit on /q/{slug}. Bots / link-preview fetchers are kept (is_bot) so the
 * raw count stays auditable, but reports count humans only.
 * Written with raw DBAL by {@see \App\Service\Campaign\CampaignStore}; mapped for reads.
 */
#[ORM\Entity(repositoryClass: QrScanRepository::class, readOnly: true)]
#[ORM\Table(name: 'qr_scan')]
#[ORM\Index(name: 'idx_qr_scan_campaign_time', columns: ['campaign_id', 'scanned_at'])]
#[ORM\Index(name: 'idx_qr_scan_visitor', columns: ['visitor_id'])]
#[ORM\Index(name: 'idx_qr_scan_retention', columns: ['ip_anonymized', 'scanned_at'])]
class QrScan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Campaign $campaign = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 64, options: ['default' => ''])]
    private string $slug = '';

    #[ORM\Column(name: 'visitor_id', type: 'string', length: 32, nullable: true)]
    private ?string $visitorId = null;

    #[ORM\Column(name: 'scanned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scannedAt;

    #[ORM\Column(name: 'ip', type: 'string', length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'ip_anonymized', type: 'boolean', options: ['default' => false])]
    private bool $ipAnonymized = false;

    #[ORM\Column(name: 'user_agent', type: 'string', length: 512, options: ['default' => ''])]
    private string $userAgent = '';

    #[ORM\Column(name: 'referer', type: 'string', length: 512, nullable: true)]
    private ?string $referer = null;

    #[ORM\Column(name: 'accept_language', type: 'string', length: 64, nullable: true)]
    private ?string $acceptLanguage = null;

    #[ORM\Column(name: 'is_bot', type: 'boolean', options: ['default' => false])]
    private bool $isBot = false;

    #[ORM\Column(name: 'bot_reason', type: 'string', length: 32, nullable: true)]
    private ?string $botReason = null;

    #[ORM\Column(name: 'is_repeat', type: 'boolean', options: ['default' => false])]
    private bool $isRepeat = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getVisitorId(): ?string
    {
        return $this->visitorId;
    }

    public function getScannedAt(): \DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function isIpAnonymized(): bool
    {
        return $this->ipAnonymized;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function getAcceptLanguage(): ?string
    {
        return $this->acceptLanguage;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function getBotReason(): ?string
    {
        return $this->botReason;
    }

    public function isRepeat(): bool
    {
        return $this->isRepeat;
    }
}
