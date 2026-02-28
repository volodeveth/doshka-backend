<?php

namespace App\MessageHandler;

use App\Message\SendCommentNotificationEmail;
use App\Repository\CardRepository;
use App\Repository\CommentRepository;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendCommentNotificationEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
        private readonly CardRepository $cardRepo,
        private readonly CommentRepository $commentRepo,
    ) {}

    public function __invoke(SendCommentNotificationEmail $message): void
    {
        $recipient = $this->userRepo->find($message->recipientUserId);
        $card      = $this->cardRepo->find($message->cardId);
        $comment   = $this->commentRepo->find($message->commentId);
        $author    = $this->userRepo->find($message->authorId);

        if (!$recipient || !$card || !$comment || !$author) {
            return;
        }

        $email = (new Email())
            ->to($recipient->getEmail())
            ->subject('New comment on card: ' . $card->getTitle())
            ->html(sprintf(
                '<p>Hi <strong>%s</strong>,</p>
                <p><strong>%s</strong> commented on card <strong>"%s"</strong>:</p>
                <blockquote>%s</blockquote>
                <p>Log in to reply!</p>',
                htmlspecialchars($recipient->getUsername()),
                htmlspecialchars($author->getUsername()),
                htmlspecialchars($card->getTitle()),
                nl2br(htmlspecialchars($comment->getContent()))
            ));

        $this->mailer->send($email);
    }
}
