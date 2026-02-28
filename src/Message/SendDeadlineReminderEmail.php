<?php

namespace App\Message;

final class SendDeadlineReminderEmail
{
    public function __construct(
        public readonly int $userId,
        public readonly int $cardId,
    ) {}
}
