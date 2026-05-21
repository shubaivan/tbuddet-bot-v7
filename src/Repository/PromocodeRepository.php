<?php

namespace App\Repository;

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
     * Most-recently-created FIRST_ORDER code assigned to the given buyer, or null.
     *
     * Used by {@see \App\Service\Promocode\FirstOrderPromocodeService} to find the
     * buyer's existing personal code before deciding whether to rotate it. Pass
     * exactly one of $user / $telegramUser — whichever identity the caller holds.
     */
    public function findLatestFirstOrderFor(?User $user, ?TelegramUser $telegramUser): ?Promocode
    {
        if ($user === null && $telegramUser === null) {
            return null;
        }

        $qb = $this->createQueryBuilder('p')
            ->where('p.purpose = :purpose')
            ->setParameter('purpose', PromocodePurposeEnum::FIRST_ORDER)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1);

        if ($user !== null) {
            $qb->andWhere('p.assignedUser = :user')->setParameter('user', $user);
        } else {
            $qb->andWhere('p.assignedTelegramUser = :tgUser')->setParameter('tgUser', $telegramUser);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
