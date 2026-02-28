<?php

namespace App\Repository;

use App\Entity\Card;
use App\Entity\CardMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardMember>
 */
class CardMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardMember::class);
    }

    public function findOneByCardAndUser(Card $card, User $user): ?CardMember
    {
        return $this->findOneBy(['card' => $card, 'user' => $user]);
    }
}
