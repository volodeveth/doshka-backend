<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Card;
use App\Entity\CardLabel;
use App\Entity\Label;
use App\Entity\User;
use App\Repository\CardLabelRepository;
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
#[OA\Tag(name: 'Labels')]
class LabelController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    #[Route('/boards/{id}/labels', name: 'labels_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/boards/{id}/labels',
        operationId: 'getLabels',
        summary: 'List all labels for a board',
        security: [['JWT' => []]],
        tags: ['Labels'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Board ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of labels',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string', example: 'Bug'),
                            new OA\Property(property: 'color', type: 'string', example: '#EB5A46'),
                            new OA\Property(property: 'boardId', type: 'integer'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Board $board): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        return $this->json(
            $board->getLabels()->map(fn($l) => $this->normalizeLabel($l))->toArray()
        );
    }

    #[Route('/boards/{id}/labels', name: 'labels_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/boards/{id}/labels',
        operationId: 'createLabel',
        summary: 'Create a new label for a board',
        security: [['JWT' => []]],
        tags: ['Labels'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Board ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'color'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Bug'),
                    new OA\Property(property: 'color', type: 'string', example: '#EB5A46'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Label created'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden — requires admin or owner role'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function create(Board $board, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $board);

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $label = new Label();
        $label->setBoard($board);
        $label->setName($data['name'] ?? '');
        $label->setColor($data['color'] ?? '');

        $errors = $this->validator->validate($label);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($label);
        $this->em->flush();

        return $this->json($this->normalizeLabel($label), Response::HTTP_CREATED);
    }

    #[Route('/labels/{id}', name: 'labels_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/labels/{id}',
        operationId: 'updateLabel',
        summary: 'Update label name or color',
        security: [['JWT' => []]],
        tags: ['Labels'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Label ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Feature'),
                    new OA\Property(property: 'color', type: 'string', example: '#61BD4F'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Label updated'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Label not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Label $label, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $label->getBoard());

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['name'])) $label->setName($data['name']);
        if (isset($data['color'])) $label->setColor($data['color']);

        $errors = $this->validator->validate($label);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($this->normalizeLabel($label));
    }

    #[Route('/labels/{id}', name: 'labels_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/labels/{id}',
        operationId: 'deleteLabel',
        summary: 'Delete a label',
        security: [['JWT' => []]],
        tags: ['Labels'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Label ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Label deleted'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Label not found'),
        ]
    )]
    public function delete(Label $label): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::MANAGE, $label->getBoard());

        $this->em->remove($label);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/cards/{id}/labels', name: 'card_labels_attach', methods: ['POST'])]
    #[OA\Post(
        path: '/api/cards/{id}/labels',
        operationId: 'attachLabel',
        summary: 'Attach a label to a card',
        security: [['JWT' => []]],
        tags: ['Card Labels'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['labelId'],
                properties: [
                    new OA\Property(property: 'labelId', type: 'integer', example: 2, description: 'Must belong to the same board'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Label attached'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Label not found or belongs to different board'),
            new OA\Response(response: 409, description: 'Label already attached'),
        ]
    )]
    public function attachLabel(Card $card, Request $request, CardLabelRepository $cardLabelRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        $labelId = $data['labelId'] ?? null;

        if (!$labelId) {
            return $this->json(['error' => 'labelId is required'], Response::HTTP_BAD_REQUEST);
        }

        $label = $this->em->find(Label::class, $labelId);
        if (!$label || $label->getBoard() !== $card->getList()->getBoard()) {
            return $this->json(['error' => 'Label not found or belongs to different board'], Response::HTTP_NOT_FOUND);
        }

        if ($cardLabelRepo->findOneByCardAndLabel($card, $label)) {
            return $this->json(['error' => 'Label already attached'], Response::HTTP_CONFLICT);
        }

        $cardLabel = new CardLabel();
        $cardLabel->setCard($card);
        $cardLabel->setLabel($label);

        $this->em->persist($cardLabel);
        $this->em->flush();

        /** @var User $user */
        $user = $this->getUser();
        $this->activityLogger->log($card->getList()->getBoard(), $card, $user, 'card.label_added', [
            'labelId' => $label->getId(),
            'name'    => $label->getName(),
        ]);

        return $this->json([
            'id'    => $cardLabel->getId(),
            'label' => $this->normalizeLabel($label),
        ], Response::HTTP_CREATED);
    }

    #[Route('/cards/{cardId}/labels/{labelId}', name: 'card_labels_detach', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/cards/{cardId}/labels/{labelId}',
        operationId: 'detachLabel',
        summary: 'Detach a label from a card',
        security: [['JWT' => []]],
        tags: ['Card Labels'],
        parameters: [
            new OA\Parameter(name: 'cardId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'labelId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Label detached'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card, label or attachment not found'),
        ]
    )]
    public function detachLabel(int $cardId, int $labelId, CardLabelRepository $cardLabelRepo): JsonResponse
    {
        $card = $this->em->find(Card::class, $cardId);
        if (!$card) return $this->json(['error' => 'Card not found'], Response::HTTP_NOT_FOUND);

        $this->denyAccessUnlessGranted(BoardVoter::EDIT, $card->getList()->getBoard());

        $label = $this->em->find(Label::class, $labelId);
        if (!$label) return $this->json(['error' => 'Label not found'], Response::HTTP_NOT_FOUND);

        $cardLabel = $cardLabelRepo->findOneByCardAndLabel($card, $label);
        if (!$cardLabel) return $this->json(['error' => 'Label not attached'], Response::HTTP_NOT_FOUND);

        $this->em->remove($cardLabel);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function normalizeLabel(Label $label): array
    {
        return [
            'id'      => $label->getId(),
            'name'    => $label->getName(),
            'color'   => $label->getColor(),
            'boardId' => $label->getBoard()->getId(),
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
