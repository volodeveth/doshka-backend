<?php

namespace App\Repository;

use App\Entity\BoardList;
use App\Entity\Card;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    public function getMaxPosition(BoardList $list): int
    {
        $result = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->where('c.list = :list')
            ->setParameter('list', $list)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : -1;
    }

    /**
     * Find cards with due date = tomorrow (for deadline reminders).
     */
    public function findCardsWithDeadlineTomorrow(): array
    {
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $dayAfter = new \DateTimeImmutable('+2 days');

        return $this->createQueryBuilder('c')
            ->where('c.dueDate >= :tomorrow')
            ->andWhere('c.dueDate < :dayAfter')
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('dayAfter', $dayAfter)
            ->getQuery()
            ->getResult();
    }
}
