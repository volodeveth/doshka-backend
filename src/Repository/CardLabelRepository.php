<?php

namespace App\Repository;

use App\Entity\Card;
use App\Entity\CardLabel;
use App\Entity\Label;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardLabel>
 */
class CardLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardLabel::class);
    }

    public function findOneByCardAndLabel(Card $card, Label $label): ?CardLabel
    {
        return $this->findOneBy(['card' => $card, 'label' => $label]);
    }
}
