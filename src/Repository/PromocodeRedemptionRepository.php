<?php

namespace App\Repository;

use App\Entity\Promocode;
use App\Entity\PromocodeRedemption;
use App\Entity\TelegramUser;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromocodeRedemption>
 *
 * @method PromocodeRedemption|null find($id, $lockMode = null, $lockVersion = null)
 * @method PromocodeRedemption|null findOneBy(array $criteria, array $orderBy = null)
 * @method PromocodeRedemption[]    findAll()
 * @method PromocodeRedemption[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PromocodeRedemptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromocodeRedemption::class);
    }

    /**
     * Count how many times this buyer has already redeemed this promocode.
     * Identity is whichever of the three is non-null (User > TelegramUser > guest phone).
     */
    public function countRedemptionsForBuyer(
        Promocode $promocode,
        ?User $user,
        ?TelegramUser $telegramUser,
        ?string $guestPhone,
    ): int {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.promocode = :promocode')
            ->setParameter('promocode', $promocode);

        if ($user !== null) {
            $qb->andWhere('r.user = :user')->setParameter('user', $user);
        } elseif ($telegramUser !== null) {
            $qb->andWhere('r.telegramUser = :tg')->setParameter('tg', $telegramUser);
        } elseif ($guestPhone !== null && $guestPhone !== '') {
            // A guest is the phone they typed: count their guest redemptions AND those of
            // the account with this phone (log out → use the code again as a guest).
            $qb->leftJoin('r.user', 'ru')
                ->andWhere('r.guestPhone = :phone OR ru.phone = :phone')
                ->setParameter('phone', $guestPhone);
        } else {
            // Anonymous-with-no-phone redemptions can't be tracked per-buyer — treat as zero,
            // since max_uses_per_user can't be enforced anyway. The global max_uses still applies.
            return 0;
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
