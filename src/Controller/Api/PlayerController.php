<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CommentRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class PlayerController extends AbstractController
{
  // Pour lister les joueurs
  #[Route('/players', name: 'players_list', methods: ['GET'])]
  public function list(UserRepository $userRepo): JsonResponse
  {
    return $this->json([
      'players' => array_map(
        fn (User $u) => $this->serializePlayer($u),
        $userRepo->findPlayers()
      )
    ], 200);
  }


  // Pour suspendre ou réactiver un joueur
  #[Route('/players/{id}/status', name: 'players_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function updateStatus(
    int $id,
    Request $request,
    UserRepository $userRepo,
    EntityManagerInterface $em,
    ActivityLogger $logger
  ): JsonResponse {
    $player = $userRepo->find($id);

    if (!$player) {
      return $this->json([
        'success' => false,
        'message' => 'Joueur introuvable.'
      ], 404);
    }

    if (!$this->isPlayer($player)) {
      return $this->json([
        'success' => false,
        'message' => 'Cet utilisateur n\'est pas un joueur.'
      ], 403);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $status = $data['status'] ?? null;

    if (!in_array($status, ['active', 'suspended'], true)) {
      return $this->json([
        'success' => false,
        'message' => 'Statut invalide (active ou suspended).'
      ], 400);
    }

    $player->setActive($status === 'active');
    $em->flush();

    $admin = $this->getUser();
    $reactivated = $status === 'active';
    $logger->log(
      $admin instanceof User ? $admin : null,
      'suspend',
      $reactivated ? 'Réactivation d\'un joueur' : 'Suspension d\'un joueur',
      $logger->actorLabel($admin instanceof User ? $admin : null) . ($reactivated
        ? ' a réactivé le joueur ' . $player->getPseudo() . '.'
        : ' a suspendu le joueur ' . $player->getPseudo() . '.')
    );

    return $this->json([
      'success' => true,
      'message' => 'Statut mis à jour.',
      'player' => $this->serializePlayer($player)
    ], 200);
  }


  // Pour supprimer définitivement un joueur
  #[Route('/players/{id}', name: 'players_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function delete(
    int $id,
    UserRepository $userRepo,
    CharacterRepository $characterRepo,
    CommentRepository $commentRepo,
    EntityManagerInterface $em,
    ActivityLogger $logger
  ): JsonResponse {
    $player = $userRepo->find($id);

    if (!$player) {
      return $this->json([
        'success' => false,
        'message' => 'Joueur introuvable.'
      ], 404);
    }

    if (!$this->isPlayer($player)) {
      return $this->json([
        'success' => false,
        'message' => 'Cet utilisateur n\'est pas un joueur.'
      ], 403);
    }

    foreach ($commentRepo->findBy(['author' => $player]) as $comment) {
      $em->remove($comment);
    }

    foreach ($characterRepo->findBy(['creator' => $player]) as $character) {
      $this->purgeFavorites($em, $character->getId());

      foreach ($commentRepo->findBy(['character' => $character]) as $comment) {
        $em->remove($comment);
      }

      $em->remove($character);
    }

    $em->getConnection()->executeStatement(
      'DELETE FROM favorites WHERE user_id = :id',
      ['id' => $player->getId()]
    );

    $playerPseudo = $player->getPseudo();

    $em->remove($player);
    $em->flush();

    $admin = $this->getUser();
    $logger->log(
      $admin instanceof User ? $admin : null,
      'delete',
      'Suppression d\'un joueur',
      $logger->actorLabel($admin instanceof User ? $admin : null) . ' a supprimé le joueur ' . $playerPseudo . '.'
    );

    return $this->json([
      'success' => true,
      'message' => 'Joueur supprimé.'
    ], 200);
  }


  // Un joueur est un utilisateur sans rôle employeur ni administrateur
  private function isPlayer(User $user): bool
  {
    $roles = $user->getRoles();

    return !in_array('ROLE_EMPLOYER', $roles, true)
      && !in_array('ROLE_ADMIN', $roles, true);
  }


  // Supprime les favoris pointant vers un personnage
  private function purgeFavorites(EntityManagerInterface $em, int $characterId): void
  {
    $em->getConnection()->executeStatement(
      'DELETE FROM favorites WHERE character_id = :id',
      ['id' => $characterId]
    );
  }


  // Pour transformer un User en tableau JSON
  private function serializePlayer(User $u): array
  {
    return [
      'id' => $u->getId(),
      'pseudo' => $u->getPseudo(),
      'status' => $u->isActive() ? 'active' : 'suspended',
      'createdAt' => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
