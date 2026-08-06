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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    $comment->setStatus('pending');
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


  // Pour lister tous les commentaires
  #[Route('/comments', name: 'comments_all', methods: ['GET'])]
  public function all(CommentRepository $commentRepo): JsonResponse
  {
    $comments = $commentRepo->findBy([], ['createdAt' => 'DESC']);

    return $this->json([
      'comments' => array_map(
        fn (Comment $c) => $this->serializeComment($c),
        $comments
      )
    ], 200);
  }


  // Pour récupérer le détail d'un commentaire
  #[Route('/comments/{id}', name: 'comments_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function show(int $id, CommentRepository $commentRepo): JsonResponse
  {
    $comment = $commentRepo->find($id);

    if (!$comment) {
      return $this->json([
        'success' => false,
        'message' => 'Commentaire introuvable.'
      ], 404);
    }

    return $this->json([
      'comment' => $this->serializeComment($comment)
    ], 200);
  }


  // Pour valider ou refuser un commentaire
  #[Route('/comments/{id}/status', name: 'comments_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function updateStatus(
    int $id,
    Request $request,
    CommentRepository $commentRepo,
    EntityManagerInterface $em,
    MailerInterface $mailer
  ): JsonResponse {
    $comment = $commentRepo->find($id);

    if (!$comment) {
      return $this->json([
        'success' => false,
        'message' => 'Commentaire introuvable.'
      ], 404);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $status = $data['status'] ?? null;

    if (!in_array($status, ['pending', 'valid', 'refused'], true)) {
      return $this->json([
        'success' => false,
        'message' => 'Statut invalide (pending, valid ou refused).'
      ], 400);
    }

    $comment->setStatus($status);
    $em->flush();

    if ($status === 'valid') {
      $this->sendCommentApprovalMail($mailer, $comment);
    }

    return $this->json([
      'success' => true,
      'message' => 'Statut mis à jour.',
      'comment' => $this->serializeComment($comment)
    ], 200);
  }


  // Pour refuser un commentaire : motif obligatoire, mail à l'auteur puis suppression définitive
  #[Route('/comments/{id}/reject', name: 'comments_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function reject(
    int $id,
    Request $request,
    CommentRepository $commentRepo,
    EntityManagerInterface $em,
    MailerInterface $mailer
  ): JsonResponse {
    $comment = $commentRepo->find($id);

    if (!$comment) {
      return $this->json([
        'success' => false,
        'message' => 'Commentaire introuvable.'
      ], 404);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $reason = trim($data['reason'] ?? '');

    if (mb_strlen($reason) < 10) {
      return $this->json([
        'success' => false,
        'message' => 'Un motif de refus d\'au moins 10 caractères est obligatoire.'
      ], 400);
    }

    // On prévient l'auteur avant la suppression définitive
    $this->sendCommentRejectionMail($mailer, $comment, $reason);

    $em->remove($comment);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Commentaire refusé et supprimé.'
    ], 200);
  }


  // Pour supprimer définitivement un commentaire
  #[Route('/comments/{id}', name: 'comments_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function delete(int $id, CommentRepository $commentRepo, EntityManagerInterface $em): JsonResponse
  {
    $comment = $commentRepo->find($id);

    if (!$comment) {
      return $this->json([
        'success' => false,
        'message' => 'Commentaire introuvable.'
      ], 404);
    }

    $em->remove($comment);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Commentaire supprimé.'
    ], 200);
  }


  // Envoie un mail à l'auteur quand son commentaire est validé
  private function sendCommentApprovalMail(MailerInterface $mailer, Comment $comment): void
  {
    $author = $comment->getAuthor();

    if (!$author || !$author->getEmail()) {
      return;
    }

    $characterName = $comment->getCharacter()?->getName() ?? 'un personnage';

    $mail = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to($author->getEmail())
      ->subject('Votre commentaire a été validé')
      ->text(sprintf(
        "Bonjour %s,\n\nVotre commentaire sur le personnage \"%s\" a été validé et est désormais visible sur FantasyRealm Online.\n\nMerci pour votre participation !",
        $author->getPseudo(),
        $characterName
      ));

    $mailer->send($mail);
  }


  // Envoie un mail à l'auteur quand son commentaire est refusé (avec le motif)
  private function sendCommentRejectionMail(MailerInterface $mailer, Comment $comment, string $reason): void
  {
    $author = $comment->getAuthor();

    if (!$author || !$author->getEmail()) {
      return;
    }

    $characterName = $comment->getCharacter()?->getName() ?? 'un personnage';

    $mail = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to($author->getEmail())
      ->subject('Votre commentaire a été refusé')
      ->text(sprintf(
        "Bonjour %s,\n\nVotre commentaire sur le personnage \"%s\" n'a pas été validé et a été supprimé.\n\nVotre commentaire :\n\"%s\"\n\nMotif du refus :\n%s\n\nÀ bientôt sur FantasyRealm Online !",
        $author->getPseudo(),
        $characterName,
        $comment->getMessage(),
        $reason
      ));

    $mailer->send($mail);
  }


  // Pour transformer un Comment en tableau JSON
  private function serializeComment(Comment $c): array
  {
    $character = $c->getCharacter();

    return [
      'id' => $c->getId(),
      'author' => $c->getAuthor()?->getPseudo(),
      'message' => $c->getMessage(),
      'rating' => $c->getRating(),
      'status' => $c->getStatus(),
      'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
      'character' => [
        'id' => $character?->getId(),
        'name' => $character?->getName(),
        'image' => $character?->getImage()
      ]
    ];
  }
}
