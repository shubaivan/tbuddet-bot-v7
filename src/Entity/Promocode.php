<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Repository\PromocodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromocodeRepository::class)]
#[ORM\HasLifecycleCallbacks()]
#[ORM\Table(name: 'promocode')]
class Promocode
{
    use CreatedUpdatedAtAwareTrait;

    public const DELIVERY_CHANNEL_TELEGRAM = 'telegram';
    public const DELIVERY_CHANNEL_EMAIL = 'email';
    public const DELIVERY_CHANNEL_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'code', type: 'string', length: 32, unique: true, nullable: false)]
    private string $code;

    #[ORM\Column(name: 'discount_type', type: 'string', length: 20, nullable: false, enumType: DiscountTypeEnum::class)]
    private DiscountTypeEnum $discountType;

    /**
     * For PERCENT: 1..100 (currency-agnostic in numeric sense, but still bound to one currency
     * so admins don't accidentally mix shops).
     * For FIXED_AMOUNT: whole units of $currency (same scale as UserOrder.total_amount).
     */
    #[ORM\Column(name: 'value', type: 'integer', nullable: false)]
    private int $value;

    /**
     * Storefront this code is valid in. Each promocode is bound to exactly one currency
     * so a "500 знижки" code can never accidentally apply to a USD cart (and vice versa).
     * If you want the same campaign in both shops, create two codes.
     */
    #[ORM\Column(name: 'currency', type: 'string', length: 3, nullable: false, enumType: CurrencyEnum::class)]
    private CurrencyEnum $currency = CurrencyEnum::UAH;

    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $validTo = null;

    /** Total redemptions allowed. NULL = unlimited. */
    #[ORM\Column(name: 'max_uses', type: 'integer', nullable: true)]
    private ?int $maxUses = null;

    /** Redemptions allowed per (user|telegram_user|guest_phone). NULL = unlimited. */
    #[ORM\Column(name: 'max_uses_per_user', type: 'integer', nullable: true)]
    private ?int $maxUsesPerUser = null;

    #[ORM\Column(name: 'min_order_amount', type: 'integer', nullable: true)]
    private ?int $minOrderAmount = null;

    /** Denormalized counter for cheap admin display + fast-fail validation. Source of truth is the ledger. */
    #[ORM\Column(name: 'times_used', type: 'integer', nullable: false, options: ['default' => 0])]
    private int $timesUsed = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean', nullable: false, options: ['default' => true])]
    private bool $isActive = true;

    /** If set, only this web user may redeem (single-use codes issued by admin). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedUser = null;

    /** If set, only this Telegram user may redeem. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'assigned_telegram_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $assignedTelegramUser = null;

    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $deliveredAt = null;

    #[ORM\Column(name: 'delivery_channel', type: 'string', length: 16, nullable: true)]
    private ?string $deliveryChannel = null;

    /** Admin who created the code (User entity, since admins live in client_user). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByAdmin = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getDiscountType(): DiscountTypeEnum
    {
        return $this->discountType;
    }

    public function setDiscountType(DiscountTypeEnum $discountType): self
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getCurrency(): CurrencyEnum
    {
        return $this->currency;
    }

    public function setCurrency(CurrencyEnum $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getValidFrom(): ?\DateTime
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTime $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTime
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTime $validTo): self
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getMaxUsesPerUser(): ?int
    {
        return $this->maxUsesPerUser;
    }

    public function setMaxUsesPerUser(?int $maxUsesPerUser): self
    {
        $this->maxUsesPerUser = $maxUsesPerUser;

        return $this;
    }

    public function getMinOrderAmount(): ?int
    {
        return $this->minOrderAmount;
    }

    public function setMinOrderAmount(?int $minOrderAmount): self
    {
        $this->minOrderAmount = $minOrderAmount;

        return $this;
    }

    public function getTimesUsed(): int
    {
        return $this->timesUsed;
    }

    public function setTimesUsed(int $timesUsed): self
    {
        $this->timesUsed = $timesUsed;

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

    public function getAssignedUser(): ?User
    {
        return $this->assignedUser;
    }

    public function setAssignedUser(?User $assignedUser): self
    {
        $this->assignedUser = $assignedUser;

        return $this;
    }

    public function getAssignedTelegramUser(): ?TelegramUser
    {
        return $this->assignedTelegramUser;
    }

    public function setAssignedTelegramUser(?TelegramUser $assignedTelegramUser): self
    {
        $this->assignedTelegramUser = $assignedTelegramUser;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTime
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTime $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getDeliveryChannel(): ?string
    {
        return $this->deliveryChannel;
    }

    public function setDeliveryChannel(?string $deliveryChannel): self
    {
        $this->deliveryChannel = $deliveryChannel;

        return $this;
    }

    public function getCreatedByAdmin(): ?User
    {
        return $this->createdByAdmin;
    }

    public function setCreatedByAdmin(?User $createdByAdmin): self
    {
        $this->createdByAdmin = $createdByAdmin;

        return $this;
    }

    /**
     * Compute the discount this promocode would apply to a given subtotal
     * (in whole units of {@see getCurrency()}).
     * Clamped to [0, $subtotal] so a fixed-amount code never makes the total negative.
     */
    public function computeDiscount(int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        $raw = match ($this->discountType) {
            DiscountTypeEnum::PERCENT => (int) round($subtotal * $this->value / 100),
            DiscountTypeEnum::FIXED_AMOUNT => $this->value,
        };

        return max(0, min($raw, $subtotal));
    }

    /**
     * Single-line summary suitable for LiqPay's order description and for confirmation
     * messages in the Telegram bot / web checkout. Shown to the buyer so they see
     * exactly which code applied and how much was knocked off the total.
     *
     * Example: "Промокод SPRING20: -10% (-200 грн)"
     *          "Промокод ABM-7G4K-9PXM: -500 грн"
     */
    public function describeDiscount(int $appliedDiscount): string
    {
        $suffix = $this->discountType === DiscountTypeEnum::PERCENT
            ? sprintf('-%d%% (-%d %s)', $this->value, $appliedDiscount, $this->currency->label())
            : sprintf('-%d %s', $appliedDiscount, $this->currency->label());

        return sprintf('Промокод %s: %s', $this->code, $suffix);
    }
}
