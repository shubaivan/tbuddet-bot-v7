<?php

namespace App\Entity;

use App\Entity\Enum\CurrencyEnum;
use App\Repository\PromocodeRedemptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ledger row recording a single application of a promocode against a UserOrder.
 *
 * The UNIQUE (promocode_id, user_order_id) constraint prevents double-apply on
 * retried POSTs — concurrent inserts collide and the second one is rejected at
 * the DB level, which is what makes redeem() safe under retries.
 */
#[ORM\Entity(repositoryClass: PromocodeRedemptionRepository::class)]
#[ORM\Table(name: 'promocode_redemption')]
#[ORM\UniqueConstraint(name: 'uniq_promocode_user_order', columns: ['promocode_id', 'user_order_id'])]
#[ORM\Index(name: 'idx_promo_redemption_user', columns: ['promocode_id', 'user_id'])]
#[ORM\Index(name: 'idx_promo_redemption_tg_user', columns: ['promocode_id', 'telegram_user_id'])]
#[ORM\Index(name: 'idx_promo_redemption_guest_phone', columns: ['promocode_id', 'guest_phone'])]
class PromocodeRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Promocode::class)]
    #[ORM\JoinColumn(name: 'promocode_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Promocode $promocode;

    #[ORM\ManyToOne(targetEntity: UserOrder::class)]
    #[ORM\JoinColumn(name: 'user_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserOrder $userOrder;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'telegram_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $telegramUser = null;

    /** E.164-normalized phone for anonymous web purchases. NULL when user_id or telegram_user_id is set. */
    #[ORM\Column(name: 'guest_phone', type: 'string', length: 20, nullable: true)]
    private ?string $guestPhone = null;

    /** Snapshot in whole units of {@see $currency} — survives Promocode deletion. */
    #[ORM\Column(name: 'discount_applied', type: 'integer', nullable: false)]
    private int $discountApplied;

    /** Currency snapshot at redemption time. */
    #[ORM\Column(name: 'currency', type: 'string', length: 3, nullable: false, enumType: CurrencyEnum::class)]
    private CurrencyEnum $currency;

    #[ORM\Column(name: 'redeemed_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $redeemedAt;

    public function __construct()
    {
        $this->redeemedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPromocode(): Promocode
    {
        return $this->promocode;
    }

    public function setPromocode(Promocode $promocode): self
    {
        $this->promocode = $promocode;

        return $this;
    }

    public function getUserOrder(): UserOrder
    {
        return $this->userOrder;
    }

    public function setUserOrder(UserOrder $userOrder): self
    {
        $this->userOrder = $userOrder;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getTelegramUser(): ?TelegramUser
    {
        return $this->telegramUser;
    }

    public function setTelegramUser(?TelegramUser $telegramUser): self
    {
        $this->telegramUser = $telegramUser;

        return $this;
    }

    public function getGuestPhone(): ?string
    {
        return $this->guestPhone;
    }

    public function setGuestPhone(?string $guestPhone): self
    {
        $this->guestPhone = $guestPhone;

        return $this;
    }

    public function getDiscountApplied(): int
    {
        return $this->discountApplied;
    }

    public function setDiscountApplied(int $discountApplied): self
    {
        $this->discountApplied = $discountApplied;

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

    public function getRedeemedAt(): \DateTime
    {
        return $this->redeemedAt;
    }

    public function setRedeemedAt(\DateTime $redeemedAt): self
    {
        $this->redeemedAt = $redeemedAt;

        return $this;
    }
}
