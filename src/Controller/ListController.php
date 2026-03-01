<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardList;
use App\Entity\User;
use App\Repository\BoardListRepository;
use App\Security\BoardVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
#[OA\Tag(name: 'Lists')]
class ListController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    #[Route('/boards/{id}/lists', name: 'lists_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/boards/{id}/lists',
        operationId: 'getLists',
        summary: 'Get all lists for a board ordered by position',
        security: [['JWT' => []]],
        tags: ['Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Board ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of columns',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'title', type: 'string', example: 'To Do'),
                            new OA\Property(property: 'position', type: 'integer', example: 0),
                            new OA\Property(property: 'boardId', type: 'integer'),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Board not found'),
        ]
    )]
    public function index(Board $board, BoardListRepository $listRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $lists = $listRepo->findByBoardOrdered($board);

        return $this->json(array_map(fn(BoardList $l) => $this->normalizeList($l), $lists));
    }

    #[Route('/boards/{id}/lists', name: 'lists_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/boards/{id}/lists',
        operationId: 'createList',
        summary: 'Create a new list in a board',
        security: [['JWT' => []]],
        tags: ['Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Board ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'In Progress'),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Auto-assigned if omitted'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'List created'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden — requires admin or owner role'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function create(Board $board, Request $request, BoardListRepository $listRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $board);

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $position = $listRepo->getMaxPosition($board) + 1;

        $list = new BoardList();
        $list->setBoard($board);
        $list->setTitle($data['title'] ?? '');
        $list->setPosition($data['position'] ?? $position);

        $errors = $this->validator->validate($list);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($list);
        $this->em->flush();

        /** @var User $user */
        $user = $this->getUser();
        $this->activityLogger->log($board, null, $user, 'list.created', [
            'listId' => $list->getId(),
            'title'  => $list->getTitle(),
        ]);

        return $this->json($this->normalizeList($list), Response::HTTP_CREATED);
    }

    #[Route('/lists/{id}', name: 'lists_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/lists/{id}',
        operationId: 'updateList',
        summary: 'Rename a list',
        security: [['JWT' => []]],
        tags: ['Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'List ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Done'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'List updated'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'List not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(BoardList $list, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $list->getBoard());

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) $list->setTitle($data['title']);

        $errors = $this->validator->validate($list);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($this->normalizeList($list));
    }

    #[Route('/lists/{id}', name: 'lists_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/lists/{id}',
        operationId: 'deleteList',
        summary: 'Delete a list',
        security: [['JWT' => []]],
        tags: ['Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'List ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'List deleted'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'List not found'),
        ]
    )]
    public function delete(BoardList $list): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $list->getBoard());

        $this->em->remove($list);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/lists/{id}/reorder', name: 'lists_reorder', methods: ['POST'])]
    #[OA\Post(
        path: '/api/lists/{id}/reorder',
        operationId: 'reorderList',
        summary: 'Change the position of a list within the board',
        security: [['JWT' => []]],
        tags: ['Lists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'List ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['position'],
                properties: [
                    new OA\Property(property: 'position', type: 'integer', example: 2, description: 'New zero-based position'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'List reordered'),
            new OA\Response(response: 400, description: 'Invalid position'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function reorder(BoardList $list, Request $request, BoardListRepository $listRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $list->getBoard());

        $data = json_decode($request->getContent(), true);
        $newPosition = $data['position'] ?? null;

        if ($newPosition === null || !is_int($newPosition) || $newPosition < 0) {
            return $this->json(['error' => 'Invalid position'], Response::HTTP_BAD_REQUEST);
        }

        $board = $list->getBoard();
        $allLists = $listRepo->findByBoardOrdered($board);
        $oldPosition = $list->getPosition();

        // Reorder logic
        foreach ($allLists as $item) {
            if ($item->getId() === $list->getId()) continue;

            $pos = $item->getPosition();

            if ($oldPosition < $newPosition) {
                // Moving down: shift items in between up
                if ($pos > $oldPosition && $pos <= $newPosition) {
                    $item->setPosition($pos - 1);
                }
            } else {
                // Moving up: shift items in between down
                if ($pos >= $newPosition && $pos < $oldPosition) {
                    $item->setPosition($pos + 1);
                }
            }
        }

        $list->setPosition($newPosition);
        $this->em->flush();

        return $this->json($this->normalizeList($list));
    }

    private function normalizeList(BoardList $list): array
    {
        return [
            'id'        => $list->getId(),
            'title'     => $list->getTitle(),
            'position'  => $list->getPosition(),
            'boardId'   => $list->getBoard()->getId(),
            'createdAt' => $list->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
        ];
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
