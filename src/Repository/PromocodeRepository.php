<?php

namespace App\Repository;

use App\Entity\Enum\CurrencyEnum;
use App\Entity\Enum\PromocodePurposeEnum;
use App\Entity\Promocode;
use App\Entity\TelegramUser;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Promocode>
 *
 * @method Promocode|null find($id, $lockMode = null, $lockVersion = null)
 * @method Promocode|null findOneBy(array $criteria, array $orderBy = null)
 * @method Promocode[]    findAll()
 * @method Promocode[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PromocodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promocode::class);
    }

    /**
     * Codes are stored uppercase; lookup uppercases the input so the user can type any case.
     */
    public function findActiveByCode(string $code): ?Promocode
    {
        return $this->findOneBy([
            'code' => strtoupper(trim($code)),
        ]);
    }

    /**
     * Most-recently-created FIRST_ORDER code assigned to the given buyer in the
     * given currency, or null.
     *
     * Used by {@see \App\Service\Promocode\FirstOrderPromocodeService} to find the
     * buyer's existing personal code before deciding whether to rotate it. Pass
     * exactly one of $user / $telegramUser — whichever identity the caller holds.
     * Currency is part of the lookup because a buyer gets one code per storefront
     * (UAH and USD codes are distinct).
     */
    public function findLatestFirstOrderFor(
        ?User $user,
        ?TelegramUser $telegramUser,
        CurrencyEnum $currency,
    ): ?Promocode {
        if ($user === null && $telegramUser === null) {
            return null;
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.purpose = :purpose')
            ->andWhere('p.currency = :currency')
            ->setParameter('purpose', PromocodePurposeEnum::FIRST_ORDER)
            ->setParameter('currency', $currency)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1);

        if ($user !== null) {
            $qb->andWhere('p.assignedUser = :user')->setParameter('user', $user);
        } else {
            $qb->andWhere('p.assignedTelegramUser = :tgUser')->setParameter('tgUser', $telegramUser);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * The public «first order» code (purpose WELCOME) in the given currency that can be
     * offered right now: active, inside its dates, not used up. Newest first.
     */
    public function findOfferableWelcomeCode(CurrencyEnum $currency): ?Promocode
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('p')
            ->where('p.purpose = :purpose')
            ->andWhere('p.currency = :currency')
            ->andWhere('p.isActive = true')
            ->andWhere('p.assignedUser IS NULL AND p.assignedTelegramUser IS NULL')
            ->andWhere('p.validFrom IS NULL OR p.validFrom <= :now')
            ->andWhere('p.validTo IS NULL OR p.validTo >= :now')
            ->andWhere('p.maxUses IS NULL OR p.timesUsed < p.maxUses')
            ->setParameter('purpose', PromocodePurposeEnum::WELCOME)
            ->setParameter('currency', $currency)
            ->setParameter('now', $now)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
