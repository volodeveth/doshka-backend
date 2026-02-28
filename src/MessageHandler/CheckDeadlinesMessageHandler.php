<?php

namespace App\MessageHandler;

use App\Message\CheckDeadlinesMessage;
use App\Message\SendDeadlineReminderEmail;
use App\Repository\CardRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class CheckDeadlinesMessageHandler
{
    public function __construct(
        private readonly CardRepository $cardRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(CheckDeadlinesMessage $message): void
    {
        $cards = $this->cardRepository->findCardsWithDeadlineTomorrow();

        foreach ($cards as $card) {
            foreach ($card->getAssignees() as $assignee) {
                $this->bus->dispatch(new SendDeadlineReminderEmail(
                    $assignee->getUser()->getId(),
                    $card->getId()
                ));
            }
        }
    }
}
