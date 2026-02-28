<?php

namespace App\Repository;

use App\Entity\Board;
use App\Entity\BoardList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardList>
 */
class BoardListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardList::class);
    }

    public function findByBoardOrdered(Board $board): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.board = :board')
            ->setParameter('board', $board)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getMaxPosition(Board $board): int
    {
        $result = $this->createQueryBuilder('l')
            ->select('MAX(l.position)')
            ->where('l.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : -1;
    }
}
