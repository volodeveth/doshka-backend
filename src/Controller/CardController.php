<?php

namespace App\Controller;

use App\Entity\BoardList;
use App\Entity\Card;
use App\Entity\CardMember;
use App\Entity\User;
use App\Message\SendCardAssignedEmail;
use App\Repository\BoardMemberRepository;
use App\Repository\CardMemberRepository;
use App\Repository\CardRepository;
use App\Repository\UserRepository;
use App\Security\BoardVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
#[OA\Tag(name: 'Cards')]
class CardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    // ─────────────────────────────── Cards ─────────────────────────────────

    #[Route('/lists/{id}/cards', name: 'cards_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/lists/{id}/cards',
        operationId: 'getCards',
        summary: 'Get all cards in a list',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'List ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of cards',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'position', type: 'integer'),
                            new OA\Property(property: 'dueDate', type: 'string', format: 'date-time', nullable: true),
                            new OA\Property(property: 'listId', type: 'integer'),
                            new OA\Property(property: 'labels', type: 'array', items: new OA\Items(type: 'object')),
                            new OA\Property(property: 'assignees', type: 'array', items: new OA\Items(type: 'object')),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'List not found'),
        ]
    )]
    public function index(BoardList $list): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $list->getBoard());

        return $this->json(
            $list->getCards()->map(fn($c) => $this->normalizeCard($c))->toArray()
        );
    }

    #[Route('/lists/{id}/cards', name: 'cards_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/lists/{id}/cards',
        operationId: 'createCard',
        summary: 'Create a new card in a list',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'List ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Implement feature X'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'dueDate', type: 'string', format: 'date-time', nullable: true, example: '2026-03-15T18:00:00+00:00'),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Auto-assigned if omitted'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Card created'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function create(BoardList $list, Request $request, CardRepository $cardRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $list->getBoard());

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $position = $cardRepo->getMaxPosition($list) + 1;

        $card = new Card();
        $card->setList($list);
        $card->setTitle($data['title'] ?? '');
        $card->setDescription($data['description'] ?? null);
        $card->setPosition($data['position'] ?? $position);

        if (isset($data['dueDate'])) {
            $card->setDueDate(new \DateTimeImmutable($data['dueDate']));
        }

        $errors = $this->validator->validate($card);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($card);
        $this->em->flush();

        /** @var User $user */
        $user = $this->getUser();
        $this->activityLogger->log($list->getBoard(), $card, $user, 'card.created', [
            'title' => $card->getTitle(),
        ]);

        return $this->json($this->normalizeCard($card), Response::HTTP_CREATED);
    }

    #[Route('/cards/{id}', name: 'cards_show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/cards/{id}',
        operationId: 'getCard',
        summary: 'Get card details with description and comments',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Card details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'position', type: 'integer'),
                        new OA\Property(property: 'dueDate', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'listId', type: 'integer'),
                        new OA\Property(property: 'labels', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'assignees', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'comments', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card not found'),
        ]
    )]
    public function show(Card $card): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $card->getList()->getBoard());

        return $this->json($this->normalizeCard($card, true));
    }

    #[Route('/cards/{id}', name: 'cards_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/cards/{id}',
        operationId: 'updateCard',
        summary: 'Update card title, description, due date or move to another list',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Updated title'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'dueDate', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'listId', type: 'integer', nullable: true, description: 'Move card to another list'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Card updated'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Card $card, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) $card->setTitle($data['title']);
        if (array_key_exists('description', $data)) $card->setDescription($data['description']);
        if (array_key_exists('dueDate', $data)) {
            $card->setDueDate($data['dueDate'] ? new \DateTimeImmutable($data['dueDate']) : null);
        }

        // Move to another list within the same board
        if (isset($data['listId'])) {
            $newList = $this->em->find(BoardList::class, $data['listId']);
            if (!$newList || $newList->getBoard() !== $card->getList()->getBoard()) {
                return $this->json(['error' => 'Invalid list'], Response::HTTP_BAD_REQUEST);
            }
            $oldList = $card->getList();
            $card->setList($newList);

            /** @var User $user */
            $user = $this->getUser();
            $this->activityLogger->log($card->getList()->getBoard(), $card, $user, 'card.moved', [
                'from' => $oldList->getTitle(),
                'to'   => $newList->getTitle(),
            ]);
        }

        $errors = $this->validator->validate($card);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($this->normalizeCard($card));
    }

    #[Route('/cards/{id}', name: 'cards_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/cards/{id}',
        operationId: 'deleteCard',
        summary: 'Delete a card',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Card deleted'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card not found'),
        ]
    )]
    public function delete(Card $card): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $this->em->remove($card);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/cards/{id}/reorder', name: 'cards_reorder', methods: ['POST'])]
    #[OA\Post(
        path: '/api/cards/{id}/reorder',
        operationId: 'reorderCard',
        summary: 'Change the position of a card within its list',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['position'],
                properties: [
                    new OA\Property(property: 'position', type: 'integer', example: 1, description: 'New zero-based position'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Card reordered'),
            new OA\Response(response: 400, description: 'Invalid position'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function reorder(Card $card, Request $request, CardRepository $cardRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        $newPosition = $data['position'] ?? null;

        if ($newPosition === null || !is_int($newPosition) || $newPosition < 0) {
            return $this->json(['error' => 'Invalid position'], Response::HTTP_BAD_REQUEST);
        }

        $list = $card->getList();
        $allCards = $list->getCards()->toArray();
        usort($allCards, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        $oldPosition = $card->getPosition();

        foreach ($allCards as $item) {
            if ($item->getId() === $card->getId()) continue;
            $pos = $item->getPosition();

            if ($oldPosition < $newPosition) {
                if ($pos > $oldPosition && $pos <= $newPosition) {
                    $item->setPosition($pos - 1);
                }
            } else {
                if ($pos >= $newPosition && $pos < $oldPosition) {
                    $item->setPosition($pos + 1);
                }
            }
        }

        $card->setPosition($newPosition);
        $this->em->flush();

        return $this->json($this->normalizeCard($card));
    }

    #[Route('/cards/{id}/move', name: 'cards_move', methods: ['POST'])]
    #[OA\Post(
        path: '/api/cards/{id}/move',
        operationId: 'moveCard',
        summary: 'Move a card to a different list',
        security: [['JWT' => []]],
        tags: ['Cards'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['listId'],
                properties: [
                    new OA\Property(property: 'listId', type: 'integer', example: 5, description: 'Target list ID (must be on the same board)'),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Position in target list, appended at end if omitted'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Card moved'),
            new OA\Response(response: 400, description: 'Invalid target list'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function move(Card $card, Request $request, CardRepository $cardRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        $listId = $data['listId'] ?? null;

        if (!$listId) {
            return $this->json(['error' => 'listId is required'], Response::HTTP_BAD_REQUEST);
        }

        $newList = $this->em->find(BoardList::class, $listId);
        if (!$newList || $newList->getBoard() !== $card->getList()->getBoard()) {
            return $this->json(['error' => 'Target list not found or belongs to another board'], Response::HTTP_BAD_REQUEST);
        }

        $oldListTitle = $card->getList()->getTitle();
        $newPosition = $cardRepo->getMaxPosition($newList) + 1;

        $card->setList($newList);
        $card->setPosition(isset($data['position']) ? (int) $data['position'] : $newPosition);

        $this->em->flush();

        /** @var User $user */
        $user = $this->getUser();
        $this->activityLogger->log($newList->getBoard(), $card, $user, 'card.moved', [
            'from' => $oldListTitle,
            'to'   => $newList->getTitle(),
        ]);

        return $this->json($this->normalizeCard($card));
    }

    // ─────────────────────────── Card Members ────────────────────────────────

    #[Route('/cards/{id}/members', name: 'cards_members_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/cards/{id}/members',
        operationId: 'getCardMembers',
        summary: 'List users assigned to a card',
        security: [['JWT' => []]],
        tags: ['Card Members'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of assignees',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(
                                property: 'user',
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'username', type: 'string'),
                                    new OA\Property(property: 'avatarUrl', type: 'string', nullable: true),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function cardMembers(Card $card): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $card->getList()->getBoard());

        return $this->json(
            $card->getAssignees()->map(fn(CardMember $cm) => [
                'id'   => $cm->getId(),
                'user' => [
                    'id'        => $cm->getUser()->getId(),
                    'username'  => $cm->getUser()->getUsername(),
                    'avatarUrl' => $cm->getUser()->getAvatarUrl(),
                ],
            ])->toArray()
        );
    }

    #[Route('/cards/{id}/members', name: 'cards_members_add', methods: ['POST'])]
    #[OA\Post(
        path: '/api/cards/{id}/members',
        operationId: 'addCardMember',
        summary: 'Assign a board member to a card',
        security: [['JWT' => []]],
        tags: ['Card Members'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['userId'],
                properties: [
                    new OA\Property(property: 'userId', type: 'integer', example: 3, description: 'Must be a board member'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User assigned to card'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden or user is not a board member'),
            new OA\Response(response: 404, description: 'User or card not found'),
            new OA\Response(response: 409, description: 'User already assigned'),
        ]
    )]
    public function addCardMember(
        Card $card,
        Request $request,
        UserRepository $userRepo,
        BoardMemberRepository $boardMemberRepo,
        CardMemberRepository $cardMemberRepo,
        MessageBusInterface $bus,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        $userId = $data['userId'] ?? null;

        if (!$userId) {
            return $this->json(['error' => 'userId is required'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $userRepo->find($userId);
        if (!$targetUser) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $board = $card->getList()->getBoard();
        if (!$boardMemberRepo->findOneByBoardAndUser($board, $targetUser)) {
            return $this->json(['error' => 'User is not a board member'], Response::HTTP_FORBIDDEN);
        }

        if ($cardMemberRepo->findOneByCardAndUser($card, $targetUser)) {
            return $this->json(['error' => 'User is already assigned'], Response::HTTP_CONFLICT);
        }

        $cardMember = new CardMember();
        $cardMember->setCard($card);
        $cardMember->setUser($targetUser);

        $this->em->persist($cardMember);
        $this->em->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $this->activityLogger->log($board, $card, $currentUser, 'card.member_added', [
            'userId'   => $targetUser->getId(),
            'username' => $targetUser->getUsername(),
        ]);

        $bus->dispatch(new SendCardAssignedEmail($targetUser->getId(), $card->getId(), $currentUser->getId()));

        return $this->json([
            'id'   => $cardMember->getId(),
            'user' => ['id' => $targetUser->getId(), 'username' => $targetUser->getUsername()],
        ], Response::HTTP_CREATED);
    }

    #[Route('/cards/{cardId}/members/{userId}', name: 'cards_members_remove', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/cards/{cardId}/members/{userId}',
        operationId: 'removeCardMember',
        summary: 'Unassign a user from a card',
        security: [['JWT' => []]],
        tags: ['Card Members'],
        parameters: [
            new OA\Parameter(name: 'cardId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Assignment removed'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card, user or assignment not found'),
        ]
    )]
    public function removeCardMember(
        int $cardId,
        int $userId,
        CardMemberRepository $cardMemberRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $card = $this->em->find(Card::class, $cardId);
        if (!$card) return $this->json(['error' => 'Card not found'], Response::HTTP_NOT_FOUND);

        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $targetUser = $userRepo->find($userId);
        if (!$targetUser) return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);

        $cardMember = $cardMemberRepo->findOneByCardAndUser($card, $targetUser);
        if (!$cardMember) return $this->json(['error' => 'Assignment not found'], Response::HTTP_NOT_FOUND);

        $this->em->remove($cardMember);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    // ─────────────────────────────── Helpers ────────────────────────────────

    private function normalizeCard(Card $card, bool $full = false): array
    {
        $data = [
            'id'        => $card->getId(),
            'title'     => $card->getTitle(),
            'position'  => $card->getPosition(),
            'dueDate'   => $card->getDueDate()?->format(\DateTimeInterface::RFC3339),
            'listId'    => $card->getList()->getId(),
            'createdAt' => $card->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
            'updatedAt' => $card->getUpdatedAt()?->format(\DateTimeInterface::RFC3339),
            'labels'    => $card->getCardLabels()->map(fn($cl) => [
                'id'    => $cl->getLabel()->getId(),
                'name'  => $cl->getLabel()->getName(),
                'color' => $cl->getLabel()->getColor(),
            ])->toArray(),
            'assignees' => $card->getAssignees()->map(fn($cm) => [
                'id'       => $cm->getUser()->getId(),
                'username' => $cm->getUser()->getUsername(),
                'avatarUrl' => $cm->getUser()->getAvatarUrl(),
            ])->toArray(),
        ];

        if ($full) {
            $data['description'] = $card->getDescription();
            $data['comments'] = $card->getComments()->map(fn($c) => [
                'id'        => $c->getId(),
                'content'   => $c->getContent(),
                'author'    => ['id' => $c->getAuthor()->getId(), 'username' => $c->getAuthor()->getUsername()],
                'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
                'updatedAt' => $c->getUpdatedAt()?->format(\DateTimeInterface::RFC3339),
            ])->toArray();
        }

        return $data;
    }

    private function formatErrors(\Symfony\Component\Validator\ConstraintViolationListInterface $errors): array
    {
        $result = [];
        foreach ($errors as $error) {
            $result[$error->getPropertyPath()] = $error->getMessage();
        }
        return ['errors' => $result];
    }
}
