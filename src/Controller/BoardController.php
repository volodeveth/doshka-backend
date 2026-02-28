<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardMember;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\BoardMemberRepository;
use App\Repository\BoardRepository;
use App\Repository\UserRepository;
use App\Security\BoardVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SendBoardInvitationEmail;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class BoardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    // ─────────────────────────────── Boards ────────────────────────────────

    #[Route('/boards', name: 'boards_index', methods: ['GET'])]
    public function index(BoardRepository $boardRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $boards = $boardRepository->findByMember($user);

        return $this->json(
            $this->normalizeBoards($boards),
            Response::HTTP_OK
        );
    }

    #[Route('/boards', name: 'boards_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $board = new Board();
        $board->setTitle($data['title'] ?? '');
        $board->setDescription($data['description'] ?? null);
        $board->setColor($data['color'] ?? null);
        $board->setOwner($user);

        $errors = $this->validator->validate($board);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Add owner as a member with 'owner' role
        $ownerMember = new BoardMember();
        $ownerMember->setBoard($board);
        $ownerMember->setUser($user);
        $ownerMember->setRole(BoardMember::ROLE_OWNER);
        $board->addMember($ownerMember);

        $this->em->persist($board);
        $this->em->persist($ownerMember);
        $this->em->flush();

        $this->activityLogger->log($board, null, $user, 'board.created', [
            'title' => $board->getTitle(),
        ]);

        return $this->json($this->normalizeBoard($board), Response::HTTP_CREATED);
    }

    #[Route('/boards/{id}', name: 'boards_show', methods: ['GET'])]
    public function show(Board $board): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        return $this->json($this->normalizeBoard($board, true));
    }

    #[Route('/boards/{id}', name: 'boards_update', methods: ['PATCH'])]
    public function update(Board $board, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $board);

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) $board->setTitle($data['title']);
        if (array_key_exists('description', $data)) $board->setDescription($data['description']);
        if (array_key_exists('color', $data)) $board->setColor($data['color']);

        $errors = $this->validator->validate($board);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($this->normalizeBoard($board));
    }

    #[Route('/boards/{id}', name: 'boards_delete', methods: ['DELETE'])]
    public function delete(Board $board): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::DELETE, $board);

        $this->em->remove($board);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/boards/{id}/activity', name: 'boards_activity', methods: ['GET'])]
    public function activity(Board $board, Request $request, ActivityRepository $activityRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        $activities = $activityRepository->findByBoardPaginated($board, $page, $limit);

        return $this->json(array_map(fn($a) => [
            'id'        => $a->getId(),
            'action'    => $a->getAction(),
            'payload'   => $a->getPayload(),
            'user'      => ['id' => $a->getUser()->getId(), 'username' => $a->getUser()->getUsername()],
            'card'      => $a->getCard() ? ['id' => $a->getCard()->getId(), 'title' => $a->getCard()->getTitle()] : null,
            'createdAt' => $a->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
        ], $activities));
    }

    // ─────────────────────────── Board Members ──────────────────────────────

    #[Route('/boards/{id}/members', name: 'boards_members_index', methods: ['GET'])]
    public function members(Board $board, BoardMemberRepository $memberRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $members = $memberRepo->findBy(['board' => $board]);

        return $this->json(array_map(fn(BoardMember $m) => [
            'id'   => $m->getId(),
            'role' => $m->getRole(),
            'user' => [
                'id'        => $m->getUser()->getId(),
                'username'  => $m->getUser()->getUsername(),
                'email'     => $m->getUser()->getEmail(),
                'avatarUrl' => $m->getUser()->getAvatarUrl(),
            ],
        ], $members));
    }

    #[Route('/boards/{id}/members', name: 'boards_members_add', methods: ['POST'])]
    public function addMember(
        Board $board,
        Request $request,
        UserRepository $userRepo,
        BoardMemberRepository $memberRepo,
        MessageBusInterface $bus,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $board);

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        if (!$email) {
            return $this->json(['error' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        $invitedUser = $userRepo->findByEmail($email);
        if (!$invitedUser) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if ($memberRepo->findOneByBoardAndUser($board, $invitedUser)) {
            return $this->json(['error' => 'User is already a member'], Response::HTTP_CONFLICT);
        }

        $role = in_array($data['role'] ?? '', BoardMember::ROLES, true) ? $data['role'] : BoardMember::ROLE_MEMBER;

        $member = new BoardMember();
        $member->setBoard($board);
        $member->setUser($invitedUser);
        $member->setRole($role);

        $this->em->persist($member);
        $this->em->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $this->activityLogger->log($board, null, $currentUser, 'member.added', [
            'userId'   => $invitedUser->getId(),
            'username' => $invitedUser->getUsername(),
            'role'     => $role,
        ]);

        $bus->dispatch(new SendBoardInvitationEmail(
            $invitedUser->getId(),
            $board->getId(),
            $currentUser->getId()
        ));

        return $this->json([
            'id'   => $member->getId(),
            'role' => $member->getRole(),
            'user' => ['id' => $invitedUser->getId(), 'username' => $invitedUser->getUsername()],
        ], Response::HTTP_CREATED);
    }

    #[Route('/boards/{boardId}/members/{userId}', name: 'boards_members_update', methods: ['PATCH'])]
    public function updateMember(
        int $boardId,
        int $userId,
        BoardMemberRepository $memberRepo,
        UserRepository $userRepo,
        Request $request,
    ): JsonResponse {
        $board = $this->em->find(Board::class, $boardId);
        if (!$board) return $this->json(['error' => 'Board not found'], Response::HTTP_NOT_FOUND);

        $this->denyAccessUnlessGranted(BoardVoter::OWN, $board);

        $targetUser = $userRepo->find($userId);
        if (!$targetUser) return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);

        $member = $memberRepo->findOneByBoardAndUser($board, $targetUser);
        if (!$member) return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);

        if ($member->isOwner()) {
            return $this->json(['error' => 'Cannot change owner role'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $newRole = $data['role'] ?? null;

        if (!$newRole || !in_array($newRole, [BoardMember::ROLE_ADMIN, BoardMember::ROLE_MEMBER], true)) {
            return $this->json(['error' => 'Invalid role. Allowed: admin, member'], Response::HTTP_BAD_REQUEST);
        }

        $member->setRole($newRole);
        $this->em->flush();

        return $this->json(['id' => $member->getId(), 'role' => $member->getRole()]);
    }

    #[Route('/boards/{boardId}/members/{userId}', name: 'boards_members_remove', methods: ['DELETE'])]
    public function removeMember(
        int $boardId,
        int $userId,
        BoardMemberRepository $memberRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $board = $this->em->find(Board::class, $boardId);
        if (!$board) return $this->json(['error' => 'Board not found'], Response::HTTP_NOT_FOUND);

        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $board);

        $targetUser = $userRepo->find($userId);
        if (!$targetUser) return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);

        $member = $memberRepo->findOneByBoardAndUser($board, $targetUser);
        if (!$member) return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Owner cannot be removed unless another owner takes over
        if ($member->isOwner() && $targetUser === $currentUser) {
            return $this->json(['error' => 'Cannot remove the board owner'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($member);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // ─────────────────────────── Helpers ────────────────────────────────────

    private function normalizeBoards(array $boards): array
    {
        return array_map(fn($b) => $this->normalizeBoard($b), $boards);
    }

    private function normalizeBoard(Board $board, bool $full = false): array
    {
        $data = [
            'id'          => $board->getId(),
            'title'       => $board->getTitle(),
            'description' => $board->getDescription(),
            'color'       => $board->getColor(),
            'createdAt'   => $board->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
            'owner'       => [
                'id'       => $board->getOwner()->getId(),
                'username' => $board->getOwner()->getUsername(),
            ],
            'memberCount' => $board->getMembers()->count(),
        ];

        if ($full) {
            $data['members'] = $board->getMembers()->map(fn(BoardMember $m) => [
                'id'   => $m->getId(),
                'role' => $m->getRole(),
                'user' => [
                    'id'        => $m->getUser()->getId(),
                    'username'  => $m->getUser()->getUsername(),
                    'avatarUrl' => $m->getUser()->getAvatarUrl(),
                ],
            ])->toArray();

            $data['lists'] = $board->getLists()->map(fn($list) => [
                'id'       => $list->getId(),
                'title'    => $list->getTitle(),
                'position' => $list->getPosition(),
                'cards'    => $list->getCards()->map(fn($card) => [
                    'id'        => $card->getId(),
                    'title'     => $card->getTitle(),
                    'position'  => $card->getPosition(),
                    'dueDate'   => $card->getDueDate()?->format(\DateTimeInterface::RFC3339),
                    'labels'    => $card->getCardLabels()->map(fn($cl) => [
                        'id'    => $cl->getLabel()->getId(),
                        'name'  => $cl->getLabel()->getName(),
                        'color' => $cl->getLabel()->getColor(),
                    ])->toArray(),
                    'assignees' => $card->getAssignees()->map(fn($cm) => [
                        'id'       => $cm->getUser()->getId(),
                        'username' => $cm->getUser()->getUsername(),
                    ])->toArray(),
                ])->toArray(),
            ])->toArray();

            $data['labels'] = $board->getLabels()->map(fn($l) => [
                'id'    => $l->getId(),
                'name'  => $l->getName(),
                'color' => $l->getColor(),
            ])->toArray();
        }

        return $data;
    }

    private function formatErrors(\Symfony\Component\Validator\ConstraintViolationListInterface $errors): array
    {
        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[$error->getPropertyPath()] = $error->getMessage();
        }
        return ['errors' => $errorMessages];
    }
}
