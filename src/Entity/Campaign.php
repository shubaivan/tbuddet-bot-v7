<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\CampaignRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Offline / QR campaign (e.g. a flyer at an event). The printed QR points at
 * FRONTEND_URL/q/{slug}; every hit is logged in qr_scan and redirected to
 * target_path with the campaign + visitor id in the query.
 */
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaign')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_campaign_promocode', columns: ['promocode_id'])]
#[ORM\HasLifecycleCallbacks()]
class Campaign
{
    use CreatedUpdatedAtAwareTrait;

    public const SLUG_PATTERN = '[a-z0-9][a-z0-9_-]{0,31}';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 32, nullable: false)]
    private string $slug = '';

    #[ORM\Column(name: 'name', type: 'string', length: 120, nullable: false, options: ['default' => ''])]
    private string $name = '';

    /** Code shown to campaign visitors (popup + checkout prefill). Optional. */
    #[ORM\ManyToOne(targetEntity: Promocode::class)]
    #[ORM\JoinColumn(name: 'promocode_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Promocode $promocode = null;

    #[ORM\Column(name: 'target_path', type: 'string', length: 255, nullable: false, options: ['default' => '/uk/'])]
    private string $targetPath = '/uk/';

    #[ORM\Column(name: 'is_active', type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'attribution_days', type: 'integer', nullable: false, options: ['default' => 60])]
    private int $attributionDays = 60;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = self::normalizeSlug($slug);

        return $this;
    }

    public static function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public static function isValidSlug(string $slug): bool
    {
        return 1 === preg_match('/^'.self::SLUG_PATTERN.'$/', $slug);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getPromocode(): ?Promocode
    {
        return $this->promocode;
    }

    public function setPromocode(?Promocode $promocode): self
    {
        $this->promocode = $promocode;

        return $this;
    }

    public function getTargetPath(): string
    {
        return $this->targetPath;
    }

    /** Always a site-relative path starting with "/" — never an absolute URL (no open redirect). */
    public function setTargetPath(string $targetPath): self
    {
        $path = trim($targetPath);
        if ('' === $path || '/' !== $path[0] || str_starts_with($path, '//') || str_contains($path, '\\')) {
            $path = '/uk/';
        }
        $this->targetPath = $path;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getAttributionDays(): int
    {
        return $this->attributionDays;
    }

    public function setAttributionDays(int $attributionDays): self
    {
        $this->attributionDays = max(1, min(365, $attributionDays));

        return $this;
    }
}
