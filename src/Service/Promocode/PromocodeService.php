<?php

namespace App\Service\Promocode;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\PromocodeErrorEnum;
use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Repository\PromocodeRedemptionRepository;
use App\Repository\PromocodeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for promocode validation and redemption.
 *
 * Used by:
 *  - the web checkout API (ShoppingCartController + a future /api/v1/promocode/validate endpoint)
 *  - the Telegram bot checkout conversation (PriceRingConversation)
 *
 * The atomicity guarantee for redemption lives at the DB level: the UNIQUE
 * constraint on (promocode_id, user_order_id) catches a concurrent second
 * redeem() on the same order and rejects it.
 */
class PromocodeService
{
    public function __construct(
        private readonly PromocodeRepository $promocodeRepository,
        private readonly PromocodeRedemptionRepository $redemptionRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Stateless check — returns whether $code can be applied to a $subtotal-UAH cart
     * for the given buyer identity. Does NOT persist anything.
     *
     * Identity precedence: $user > $telegramUser > $guestPhone. Pass whichever the
     * caller has — anonymous web purchases pass only $guestPhone (normalized E.164).
     */
    public function validate(
        string $code,
        int $subtotal,
        CurrencyEnum $cartCurrency,
        ?User $user,
        ?TelegramUser $telegramUser,
        ?string $guestPhone,
    ): PromocodeValidationResult {
        $promocode = $this->promocodeRepository->findActiveByCode($code);
        if ($promocode === null) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::NOT_FOUND);
        }

        if (!$promocode->isActive()) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::INACTIVE);
        }

        if ($promocode->getCurrency() !== $cartCurrency) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::CURRENCY_MISMATCH);
        }

        $now = new \DateTime();
        if ($promocode->getValidFrom() !== null && $promocode->getValidFrom() > $now) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::NOT_YET_VALID);
        }
        if ($promocode->getValidTo() !== null && $promocode->getValidTo() < $now) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::EXPIRED);
        }

        if ($promocode->getMinOrderAmount() !== null && $subtotal < $promocode->getMinOrderAmount()) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::BELOW_MIN_ORDER);
        }

        if (!$this->buyerMatchesAssignment($promocode, $user, $telegramUser)) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::NOT_ASSIGNED_TO_YOU);
        }

        if ($promocode->getMaxUses() !== null && $promocode->getTimesUsed() >= $promocode->getMaxUses()) {
            return PromocodeValidationResult::failure(PromocodeErrorEnum::EXHAUSTED);
        }

        if ($promocode->getMaxUsesPerUser() !== null) {
            $usedByBuyer = $this->redemptionRepository->countRedemptionsForBuyer(
                $promocode,
                $user,
                $telegramUser,
                $guestPhone,
            );
            if ($usedByBuyer >= $promocode->getMaxUsesPerUser()) {
                return PromocodeValidationResult::failure(PromocodeErrorEnum::USER_LIMIT_REACHED);
            }
        }

        return PromocodeValidationResult::success($promocode, $promocode->computeDiscount($subtotal));
    }

    /**
     * Atomically records a redemption against the given order and bumps the counter.
     *
     * Caller MUST also write the discount snapshot onto the UserOrder
     * (subtotal_amount, discount_amount, promocode_code_used) and set its
     * total_amount = subtotal - discount BEFORE calling this — that way the
     * order is consistent even if the redemption insert fails.
     *
     * Returns false on UNIQUE collision (i.e. a redemption row for this
     * promocode + order already exists). Callers should treat that as
     * "already applied, proceed normally" — typically caused by a retried POST.
     *
     * Throws any other DB error so the caller's transaction rolls back.
     */
    public function redeem(
        Promocode $promocode,
        UserOrder $userOrder,
        ?User $user,
        ?TelegramUser $telegramUser,
        ?string $guestPhone,
        int $discountApplied,
    ): bool {
        // Raw DBAL insert (not ORM persist + flush) so a UNIQUE violation here doesn't
        // close the EntityManager — callers typically flush other unrelated changes
        // after this call (e.g. LiqPay response on the same UserOrder) and need
        // the EM alive even if the redemption was an idempotent duplicate.
        $conn = $this->em->getConnection();
        try {
            // Pull the id from the Postgres sequence explicitly — raw DBAL insert
            // doesn't fill it automatically the way `persist()` does.
            $conn->executeStatement(
                "INSERT INTO promocode_redemption
                    (id, promocode_id, user_order_id, user_id, telegram_user_id, guest_phone, discount_applied, currency, redeemed_at)
                 VALUES
                    (nextval('promocode_redemption_id_seq'), :promocode_id, :user_order_id, :user_id, :telegram_user_id, :guest_phone, :discount_applied, :currency, :redeemed_at)",
                [
                    'promocode_id' => $promocode->getId(),
                    'user_order_id' => $userOrder->getId(),
                    'user_id' => $user?->getId(),
                    'telegram_user_id' => $telegramUser?->getId(),
                    'guest_phone' => $guestPhone,
                    'discount_applied' => $discountApplied,
                    'currency' => $promocode->getCurrency()->value,
                    'redeemed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Same (promocode, order) pair already in the ledger — idempotent retry, not a real failure.
            return false;
        }

        // Bump the denormalized counter in a separate atomic update so concurrent
        // redemptions of the SAME code on DIFFERENT orders don't lose increments.
        $conn->executeStatement(
            'UPDATE promocode SET times_used = times_used + 1 WHERE id = :id',
            ['id' => $promocode->getId()],
        );
        // Keep the in-memory entity consistent with the DB.
        $promocode->setTimesUsed($promocode->getTimesUsed() + 1);

        return true;
    }

    private function buyerMatchesAssignment(
        Promocode $promocode,
        ?User $user,
        ?TelegramUser $telegramUser,
    ): bool {
        $assignedUser = $promocode->getAssignedUser();
        $assignedTg = $promocode->getAssignedTelegramUser();

        // No assignment → anyone may use.
        if ($assignedUser === null && $assignedTg === null) {
            return true;
        }

        if ($assignedUser !== null && $user !== null && $assignedUser->getId() === $user->getId()) {
            return true;
        }

        if ($assignedTg !== null && $telegramUser !== null && $assignedTg->getId() === $telegramUser->getId()) {
            return true;
        }

        return false;
    }
}
