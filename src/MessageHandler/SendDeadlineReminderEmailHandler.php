<?php

namespace App\MessageHandler;

use App\Message\SendDeadlineReminderEmail;
use App\Repository\CardRepository;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendDeadlineReminderEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
        private readonly CardRepository $cardRepo,
    ) {}

    public function __invoke(SendDeadlineReminderEmail $message): void
    {
        $user = $this->userRepo->find($message->userId);
        $card = $this->cardRepo->find($message->cardId);

        if (!$user || !$card || !$card->getDueDate()) {
            return;
        }

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Deadline reminder: ' . $card->getTitle())
            ->html(sprintf(
                '<p>Hi <strong>%s</strong>,</p>
                <p>The card <strong>"%s"</strong> on board <strong>"%s"</strong> is due tomorrow: <strong>%s</strong>.</p>
                <p>Log in to check the card!</p>',
                htmlspecialchars($user->getUsername()),
                htmlspecialchars($card->getTitle()),
                htmlspecialchars($card->getList()->getBoard()->getTitle()),
                $card->getDueDate()->format('Y-m-d H:i')
            ));

        $this->mailer->send($email);
    }
}
