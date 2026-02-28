<?php

namespace App\Message;

final class SendBoardInvitationEmail
{
    public function __construct(
        public readonly int $invitedUserId,
        public readonly int $boardId,
        public readonly int $invitedByUserId,
    ) {}
}
