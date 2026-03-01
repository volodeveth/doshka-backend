<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Comment;
use App\Entity\User;
use App\Message\SendCommentNotificationEmail;
use App\Repository\CommentRepository;
use App\Security\BoardVoter;
use App\Security\CommentVoter;
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
#[OA\Tag(name: 'Comments')]
class CommentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    #[Route('/cards/{id}/comments', name: 'comments_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/cards/{id}/comments',
        operationId: 'getComments',
        summary: 'List all comments on a card (oldest first)',
        security: [['JWT' => []]],
        tags: ['Comments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of comments',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'content', type: 'string'),
                            new OA\Property(property: 'cardId', type: 'integer'),
                            new OA\Property(
                                property: 'author',
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'username', type: 'string'),
                                    new OA\Property(property: 'avatarUrl', type: 'string', nullable: true),
                                ]
                            ),
                            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Card not found'),
        ]
    )]
    public function index(Card $card, CommentRepository $commentRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $card->getList()->getBoard());

        $comments = $commentRepo->findBy(['card' => $card], ['createdAt' => 'ASC']);

        return $this->json(array_map(fn(Comment $c) => $this->normalizeComment($c), $comments));
    }

    #[Route('/cards/{id}/comments', name: 'comments_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/cards/{id}/comments',
        operationId: 'createComment',
        summary: 'Add a comment to a card (notifies assignees via email)',
        security: [['JWT' => []]],
        tags: ['Comments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Looks good to me!'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Comment created'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function create(
        Card $card,
        Request $request,
        MessageBusInterface $bus,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $card->getList()->getBoard());

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        $comment = new Comment();
        $comment->setCard($card);
        $comment->setAuthor($user);
        $comment->setContent($data['content'] ?? '');

        $errors = $this->validator->validate($comment);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($comment);
        $this->em->flush();

        $board = $card->getList()->getBoard();
        $this->activityLogger->log($board, $card, $user, 'comment.added', [
            'commentId' => $comment->getId(),
        ]);

        // Notify card assignees (except the commenter)
        foreach ($card->getAssignees() as $assignee) {
            if ($assignee->getUser()->getId() !== $user->getId()) {
                $bus->dispatch(new SendCommentNotificationEmail(
                    $assignee->getUser()->getId(),
                    $card->getId(),
                    $comment->getId(),
                    $user->getId()
                ));
            }
        }

        return $this->json($this->normalizeComment($comment), Response::HTTP_CREATED);
    }

    #[Route('/comments/{id}', name: 'comments_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/comments/{id}',
        operationId: 'updateComment',
        summary: 'Edit a comment (author only)',
        security: [['JWT' => []]],
        tags: ['Comments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Comment ID', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Updated comment text'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Comment updated'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden — author only'),
            new OA\Response(response: 404, description: 'Comment not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Comment $comment, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['content'])) $comment->setContent($data['content']);

        $errors = $this->validator->validate($comment);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json($this->normalizeComment($comment));
    }

    #[Route('/comments/{id}', name: 'comments_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/comments/{id}',
        operationId: 'deleteComment',
        summary: 'Delete a comment (author or board admin/owner)',
        security: [['JWT' => []]],
        tags: ['Comments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Comment ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Comment deleted'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden — author or admin/owner only'),
            new OA\Response(response: 404, description: 'Comment not found'),
        ]
    )]
    public function delete(Comment $comment): JsonResponse
    {
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        $this->em->remove($comment);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function normalizeComment(Comment $comment): array
    {
        return [
            'id'        => $comment->getId(),
            'content'   => $comment->getContent(),
            'cardId'    => $comment->getCard()->getId(),
            'author'    => [
                'id'        => $comment->getAuthor()->getId(),
                'username'  => $comment->getAuthor()->getUsername(),
                'avatarUrl' => $comment->getAuthor()->getAvatarUrl(),
            ],
            'createdAt' => $comment->getCreatedAt()?->format(\DateTimeInterface::RFC3339),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::RFC3339),
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
