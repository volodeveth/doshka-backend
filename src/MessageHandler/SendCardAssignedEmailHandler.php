<?php

namespace App\MessageHandler;

use App\Message\SendCardAssignedEmail;
use App\Repository\CardRepository;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendCardAssignedEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
        private readonly CardRepository $cardRepo,
    ) {}

    public function __invoke(SendCardAssignedEmail $message): void
    {
        $assignedUser = $this->userRepo->find($message->assignedUserId);
        $card         = $this->cardRepo->find($message->cardId);
        $assignedBy   = $this->userRepo->find($message->assignedByUserId);

        if (!$assignedUser || !$card || !$assignedBy) {
            return;
        }

        $boardTitle = $card->getList()->getBoard()->getTitle();

        $email = (new Email())
            ->to($assignedUser->getEmail())
            ->subject('You were assigned to a card: ' . $card->getTitle())
            ->html(sprintf(
                '<p>Hi <strong>%s</strong>,</p>
                <p><strong>%s</strong> assigned you to the card <strong>"%s"</strong> on board <strong>"%s"</strong>.</p>
                %s
                <p>Log in to view the card!</p>',
                htmlspecialchars($assignedUser->getUsername()),
                htmlspecialchars($assignedBy->getUsername()),
                htmlspecialchars($card->getTitle()),
                htmlspecialchars($boardTitle),
                $card->getDueDate()
                    ? sprintf('<p>Due: <strong>%s</strong></p>', $card->getDueDate()->format('Y-m-d H:i'))
                    : ''
            ));

        $this->mailer->send($email);
    }
}
