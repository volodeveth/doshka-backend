<?php

namespace App\Message;

final class SendCommentNotificationEmail
{
    public function __construct(
        public readonly int $recipientUserId,
        public readonly int $cardId,
        public readonly int $commentId,
        public readonly int $authorId,
    ) {}
}
