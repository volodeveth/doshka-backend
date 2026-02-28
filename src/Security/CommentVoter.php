<?php

namespace App\Security;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\BoardMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CommentVoter extends Voter
{
    public const EDIT   = 'COMMENT_EDIT';
    public const DELETE = 'COMMENT_DELETE';

    public function __construct(
        private readonly BoardMemberRepository $boardMemberRepository
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Comment
            && in_array($attribute, [self::EDIT, self::DELETE]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Comment $comment */
        $comment = $subject;

        // Must be board member
        $board = $comment->getCard()->getList()->getBoard();
        $membership = $this->boardMemberRepository->findOneByBoardAndUser($board, $user);
        if (!$membership) {
            return false;
        }

        // Can edit/delete own comments; admins/owners can delete any
        if ($comment->getAuthor() === $user) {
            return true;
        }

        return match ($attribute) {
            self::DELETE => $membership->canManage(),
            self::EDIT   => false, // only author can edit
            default      => false,
        };
    }
}
