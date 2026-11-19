<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignVisitorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anonymous visitor minted by a QR scan (cookie abm_vid / X-Abm-Visitor header).
 * campaign = first touch. user = the shop account it later signed in as.
 * Written with raw DBAL by {@see \App\Service\Campaign\CampaignStore}; mapped for reads.
 */
#[ORM\Entity(repositoryClass: CampaignVisitorRepository::class, readOnly: true)]
#[ORM\Table(name: 'campaign_visitor')]
#[ORM\Index(name: 'idx_campaign_visitor_campaign', columns: ['campaign_id'])]
#[ORM\Index(name: 'idx_campaign_visitor_user', columns: ['user_id'])]
class CampaignVisitor
{
    #[ORM\Id]
    #[ORM\Column(name: 'visitor_id', type: 'string', length: 32)]
    private string $visitorId;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column(name: 'first_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'linked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $linkedAt = null;

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getLinkedAt(): ?\DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
