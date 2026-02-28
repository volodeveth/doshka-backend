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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class CommentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    #[Route('/cards/{id}/comments', name: 'comments_index', methods: ['GET'])]
    public function index(Card $card, CommentRepository $commentRepo): JsonResponse
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $card->getList()->getBoard());

        $comments = $commentRepo->findBy(['card' => $card], ['createdAt' => 'ASC']);

        return $this->json(array_map(fn(Comment $c) => $this->normalizeComment($c), $comments));
    }

    #[Route('/cards/{id}/comments', name: 'comments_create', methods: ['POST'])]
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
