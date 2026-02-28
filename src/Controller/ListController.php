<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardList;
use App\Entity\User;
use App\Repository\BoardListRepository;
use App\Security\BoardVoter;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ListController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    #[Route('/boards/{id}/lists', name: 'lists_index', methods: ['GET'])]
    public function index(Board $board, BoardListRepository $listRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $lists = $listRepo->findByBoardOrdered($board);

        return $this->json(array_map(fn(BoardList $l) => $this->normalizeList($l), $lists));
    }

    #[Route('/boards/{id}/lists', name: 'lists_create', methods: ['POST'])]
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
    public function delete(BoardList $list): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $list->getBoard());

        $this->em->remove($list);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/lists/{id}/reorder', name: 'lists_reorder', methods: ['POST'])]
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
