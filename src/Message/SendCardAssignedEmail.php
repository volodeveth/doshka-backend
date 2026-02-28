<?php

namespace App\Message;

final class SendCardAssignedEmail
{
    public function __construct(
        public readonly int $assignedUserId,
        public readonly int $cardId,
        public readonly int $assignedByUserId,
    ) {}
}
