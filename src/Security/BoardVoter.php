<?php

namespace App\Security;

use App\Entity\Board;
use App\Entity\BoardMember;
use App\Entity\User;
use App\Repository\BoardMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class BoardVoter extends Voter
{
    public const VIEW   = 'BOARD_VIEW';
    public const EDIT   = 'BOARD_EDIT';
    public const MANAGE = 'BOARD_MANAGE'; // admin or owner
    public const OWN    = 'BOARD_OWN';   // owner only
    public const DELETE = 'BOARD_DELETE';

    public function __construct(
        private readonly BoardMemberRepository $boardMemberRepository
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Board
            && in_array($attribute, [
                self::VIEW, self::EDIT, self::MANAGE, self::OWN, self::DELETE,
            ]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Board $board */
        $board = $subject;

        $membership = $this->boardMemberRepository->findOneByBoardAndUser($board, $user);
        if (!$membership) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true, // any member can view
            self::EDIT   => true, // any member can edit cards
            self::MANAGE => $membership->canManage(),
            self::OWN    => $membership->isOwner(),
            self::DELETE => $membership->isOwner(),
            default      => false,
        };
    }
}
