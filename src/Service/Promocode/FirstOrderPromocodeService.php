<?php

namespace App\Service\Promocode;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\DiscountTypeEnum;
use App\Entity\Enum\PromocodePurposeEnum;
use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use App\Repository\PromocodeRepository;
use App\Repository\UserOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Issues and rotates a personal "first order" promocode for buyers who have not
 * yet placed a paid order.
 *
 * Behaviour ({@see resolve()}):
 *   1. Buyer already has a paid order  → no offer (they have converted).
 *   2. Buyer has a still-valid personal code → return the same code every visit.
 *   3. Code missing or expired → retire the stale one and mint a fresh 30-day code.
 *
 * Rotation is lazy: there is no cron. A code is only ever (re)generated the next
 * time the buyer appears — opening the site logged-in or sending /start in the
 * bot. Buyers who never return cost nothing.
 */
class FirstOrderPromocodeService
{
    /** Percentage knocked off the whole order. */
    private const DISCOUNT_PERCENT = 10;

    /** How long a freshly minted code stays valid before it rotates. */
    private const VALIDITY_DAYS = 30;

    /**
     * @param string[] $firstOrderTestUserIds         QA bypass — client_user IDs
     * @param string[] $firstOrderTestTelegramUserIds  QA bypass — telegram_user IDs
     */
    public function __construct(
        private readonly PromocodeRepository $promocodeRepository,
        private readonly UserOrderRepository $userOrderRepository,
        private readonly PromocodeCodeGenerator $codeGenerator,
        private readonly PromocodeDeliveryService $deliveryService,
        private readonly EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $firstOrderTestUserIds = [],
        private readonly array $firstOrderTestTelegramUserIds = [],
    ) {
    }

    /**
     * Personal first-order code for a web user, or null if they no longer qualify.
     * A freshly minted code is also delivered (email / Telegram DM) as a reminder.
     */
    public function getActiveOfferForUser(User $user): ?Promocode
    {
        return $this->resolve($user, null, deliverOnMint: true);
    }

    /**
     * Personal first-order code for a Telegram bot user, or null if they no longer
     * qualify. Delivery is skipped on mint — the bot renders the code inline in the
     * /start menu, so a separate DM would just duplicate it.
     */
    public function getActiveOfferForTelegramUser(TelegramUser $telegramUser): ?Promocode
    {
        return $this->resolve(null, $telegramUser, deliverOnMint: false);
    }

    private function resolve(?User $user, ?TelegramUser $telegramUser, bool $deliverOnMint): ?Promocode
    {
        // 1. A buyer who has already paid for an order has converted — offer over.
        //    QA-whitelisted IDs skip this check so the flow can be tested by an
        //    account that already has real orders.
        if (!$this->isTestWhitelisted($user, $telegramUser)
            && $this->userOrderRepository->countPaidOrdersFor($user, $telegramUser) > 0) {
            return null;
        }

        // 2. Existing personal code still usable → hand back the same one.
        $existing = $this->promocodeRepository->findLatestFirstOrderFor($user, $telegramUser);
        if ($existing !== null && $this->isStillOfferable($existing)) {
            return $existing;
        }

        // 3. Expired/stale code → retire it so only one live personal code exists.
        if ($existing !== null && $existing->isActive()) {
            $existing->setIsActive(false);
            $this->em->flush();
        }

        return $this->mint($user, $telegramUser, $deliverOnMint);
    }

    /**
     * A personal code is still offerable while it is active, unexpired and not yet
     * consumed. (A consumed code implies a paid order, which {@see resolve()} step 1
     * already catches — this is a belt-and-braces guard.)
     */
    private function isStillOfferable(Promocode $promocode): bool
    {
        if (!$promocode->isActive()) {
            return false;
        }

        if ($promocode->getValidTo() !== null && $promocode->getValidTo() < new \DateTime()) {
            return false;
        }

        if ($promocode->getMaxUses() !== null && $promocode->getTimesUsed() >= $promocode->getMaxUses()) {
            return false;
        }

        return true;
    }

    /**
     * QA escape hatch: an explicitly whitelisted client_user / telegram_user is
     * treated as still eligible even though they have a paid order — so the
     * first-order flow can be tested end-to-end without wiping real orders.
     * Driven by the FIRST_ORDER_PROMO_TEST_* env vars; empty in normal operation.
     */
    private function isTestWhitelisted(?User $user, ?TelegramUser $telegramUser): bool
    {
        if ($user !== null && in_array((string) $user->getId(), $this->firstOrderTestUserIds, true)) {
            return true;
        }

        if ($telegramUser !== null
            && in_array((string) $telegramUser->getId(), $this->firstOrderTestTelegramUserIds, true)) {
            return true;
        }

        return false;
    }

    private function mint(?User $user, ?TelegramUser $telegramUser, bool $deliver): Promocode
    {
        $now = new \DateTime();
        $validTo = (clone $now)->modify(sprintf('+%d days', self::VALIDITY_DAYS));

        $promocode = (new Promocode())
            ->setCode($this->codeGenerator->generate())
            ->setPurpose(PromocodePurposeEnum::FIRST_ORDER)
            ->setDiscountType(DiscountTypeEnum::PERCENT)
            ->setValue(self::DISCOUNT_PERCENT)
            ->setCurrency(CurrencyEnum::UAH)
            ->setValidFrom($now)
            ->setValidTo($validTo)
            ->setMaxUses(1)
            ->setMaxUsesPerUser(1)
            ->setIsActive(true)
            ->setAssignedUser($user)
            ->setAssignedTelegramUser($telegramUser);

        $this->em->persist($promocode);
        $this->em->flush();

        // Best-effort reminder delivery — never let a mailer/Telegram hiccup
        // block the caller (the code is shown on-screen regardless).
        if ($deliver) {
            try {
                $this->deliveryService->deliver($promocode);
            } catch (\Throwable $e) {
                $this->logger?->error('First-order promocode delivery failed', [
                    'promocode_id' => $promocode->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $promocode;
    }
}
