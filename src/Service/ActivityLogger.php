<?php

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Board;
use App\Entity\Card;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function log(Board $board, ?Card $card, User $user, string $action, ?array $payload = null): Activity
    {
        $activity = new Activity();
        $activity->setBoard($board);
        $activity->setCard($card);
        $activity->setUser($user);
        $activity->setAction($action);
        $activity->setPayload($payload);

        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }
}
