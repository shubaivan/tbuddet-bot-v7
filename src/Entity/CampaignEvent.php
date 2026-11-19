<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One funnel step of a campaign visitor. Written with raw DBAL by
 * {@see \App\Service\Campaign\CampaignStore}; mapped for reads.
 */
#[ORM\Entity(repositoryClass: CampaignEventRepository::class, readOnly: true)]
#[ORM\Table(name: 'campaign_event')]
#[ORM\Index(name: 'idx_campaign_event_campaign_type', columns: ['campaign_id', 'type'])]
#[ORM\Index(name: 'idx_campaign_event_visitor', columns: ['visitor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_campaign_event_landing', columns: ['visitor_id'], options: ['where' => "(type = 'landing')"])]
class CampaignEvent
{
    public const LANDING = 'landing';
    public const REGISTER = 'register';
    public const LOGIN = 'login';
    public const ADD_TO_CART = 'add_to_cart';
    public const ORDER = 'order';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column(name: 'visitor_id', type: 'string', length: 32, nullable: true)]
    private ?string $visitorId = null;

    #[ORM\Column(name: 'type', type: 'string', length: 20)]
    private string $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: UserOrder::class)]
    #[ORM\JoinColumn(name: 'user_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?UserOrder $userOrder = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getVisitorId(): ?string
    {
        return $this->visitorId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getUserOrder(): ?UserOrder
    {
        return $this->userOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
