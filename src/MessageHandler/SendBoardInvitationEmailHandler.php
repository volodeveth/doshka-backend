<?php

namespace App\MessageHandler;

use App\Message\SendBoardInvitationEmail;
use App\Repository\BoardRepository;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendBoardInvitationEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
        private readonly BoardRepository $boardRepo,
    ) {}

    public function __invoke(SendBoardInvitationEmail $message): void
    {
        $invitedUser = $this->userRepo->find($message->invitedUserId);
        $board       = $this->boardRepo->find($message->boardId);
        $invitedBy   = $this->userRepo->find($message->invitedByUserId);

        if (!$invitedUser || !$board || !$invitedBy) {
            return;
        }

        $email = (new Email())
            ->to($invitedUser->getEmail())
            ->subject('You have been invited to a board: ' . $board->getTitle())
            ->html(sprintf(
                '<p>Hi <strong>%s</strong>,</p>
                <p><strong>%s</strong> has invited you to the board <strong>"%s"</strong>.</p>
                <p>Log in to start collaborating!</p>',
                htmlspecialchars($invitedUser->getUsername()),
                htmlspecialchars($invitedBy->getUsername()),
                htmlspecialchars($board->getTitle())
            ));

        $this->mailer->send($email);
    }
}
