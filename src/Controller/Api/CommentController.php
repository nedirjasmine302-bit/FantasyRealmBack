<?php

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class CommentController extends AbstractController
{
  // Pour récupérer les commentaires validés d'un personnage
  #[Route('/characters/{id}/comments', name: 'comments_list', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function list(int $id, CharacterRepository $characterRepo, CommentRepository $commentRepo): JsonResponse
  {
    $character = $characterRepo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $comments = $commentRepo->findValidatedByCharacter($character);

    return $this->json([
      'comments' => array_map(
        fn (Comment $c) => $this->serializeComment($c),
        $comments
      )
    ], 200);
  }


  // Pour publier un nouveau commentaire (en attente de validation)
  #[Route('/characters/{id}/comments', name: 'comments_create', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function create(
    int $id,
    Request $request,
    CharacterRepository $characterRepo,
    EntityManagerInterface $em
  ): JsonResponse {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour laisser un commentaire.'
      ], 401);
    }

    $character = $characterRepo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $message = trim($data['message'] ?? '');
    $rating = (int) ($data['rating'] ?? 0);

    if (mb_strlen($message) < 30) {
      return $this->json([
        'success' => false,
        'message' => 'Le commentaire doit contenir au moins 30 caractères.'
      ], 400);
    }

    if ($rating < 1 || $rating > 5) {
      return $this->json([
        'success' => false,
        'message' => 'La note doit être comprise entre 1 et 5.'
      ], 400);
    }

    $comment = new Comment();
    $comment->setMessage($message);
    $comment->setRating($rating);
    $comment->setStatus('pending'); //à faire valider par un employé
    $comment->setAuthor($user);
    $comment->setCharacter($character);

    $em->persist($comment);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Votre commentaire a été envoyé et est en attente de validation.',
      'comment' => $this->serializeComment($comment)
    ], 201);
  }


  // Pour transformer un Comment en tableau JSON
  private function serializeComment(Comment $c): array
  {
    return [
      'id' => $c->getId(),
      'author' => $c->getAuthor()?->getPseudo(),
      'message' => $c->getMessage(),
      'rating' => $c->getRating(),
      'status' => $c->getStatus(),
      'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
