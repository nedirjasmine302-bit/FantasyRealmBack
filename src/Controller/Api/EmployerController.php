<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\AccessoryRepository;
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
class EmployerController extends AbstractController
{
  // Pour lister les employés
  #[Route('/employers', name: 'employers_list', methods: ['GET'])]
  public function list(UserRepository $userRepo): JsonResponse
  {
    return $this->json([
      'employers' => array_map(
        fn (User $u) => $this->serializeEmployer($u),
        $userRepo->findEmployers()
      )
    ], 200);
  }


  // Pour suspendre ou réactiver un employé
  #[Route('/employers/{id}/status', name: 'employers_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_ADMIN')]
  public function updateStatus(
    int $id,
    Request $request,
    UserRepository $userRepo,
    EntityManagerInterface $em,
    ActivityLogger $logger
  ): JsonResponse {
    $employer = $userRepo->find($id);

    if (!$employer) {
      return $this->json([
        'success' => false,
        'message' => 'Employé introuvable.'
      ], 404);
    }

    if (!$this->isEmployer($employer)) {
      return $this->json([
        'success' => false,
        'message' => 'Cet utilisateur n\'est pas un employé.'
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

    $employer->setActive($status === 'active');
    $em->flush();

    $admin = $this->getUser();
    $reactivated = $status === 'active';
    $logger->log(
      $admin instanceof User ? $admin : null,
      'suspend',
      $reactivated ? 'Réactivation d\'un employé' : 'Suspension d\'un employé',
      $logger->actorLabel($admin instanceof User ? $admin : null) . ($reactivated
        ? ' a réactivé l\'employé ' . $employer->getPseudo() . '.'
        : ' a suspendu l\'employé ' . $employer->getPseudo() . '.')
    );

    return $this->json([
      'success' => true,
      'message' => 'Statut mis à jour.',
      'employer' => $this->serializeEmployer($employer)
    ], 200);
  }


  // Pour supprimer définitivement un employé
  #[Route('/employers/{id}', name: 'employers_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_ADMIN')]
  public function delete(
    int $id,
    UserRepository $userRepo,
    AccessoryRepository $accessoryRepo,
    CharacterRepository $characterRepo,
    CommentRepository $commentRepo,
    EntityManagerInterface $em,
    ActivityLogger $logger
  ): JsonResponse {
    $employer = $userRepo->find($id);

    if (!$employer) {
      return $this->json([
        'success' => false,
        'message' => 'Employé introuvable.'
      ], 404);
    }

    if (!$this->isEmployer($employer)) {
      return $this->json([
        'success' => false,
        'message' => 'Cet utilisateur n\'est pas un employé.'
      ], 403);
    }

    $admin = $this->getUser();

    foreach ($accessoryRepo->findBy(['creator' => $employer]) as $accessory) {
      $accessory->setCreator($admin);
    }

    foreach ($commentRepo->findBy(['author' => $employer]) as $comment) {
      $em->remove($comment);
    }

    foreach ($characterRepo->findBy(['creator' => $employer]) as $character) {
      $this->purgeFavorites($em, $character->getId());

      foreach ($commentRepo->findBy(['character' => $character]) as $comment) {
        $em->remove($comment);
      }

      $em->remove($character);
    }

    $em->getConnection()->executeStatement(
      'DELETE FROM favorites WHERE user_id = :id',
      ['id' => $employer->getId()]
    );

    $employerPseudo = $employer->getPseudo();

    $em->remove($employer);
    $em->flush();

    $logger->log(
      $admin instanceof User ? $admin : null,
      'delete',
      'Suppression d\'un employé',
      $logger->actorLabel($admin instanceof User ? $admin : null) . ' a supprimé l\'employé ' . $employerPseudo . '.'
    );

    return $this->json([
      'success' => true,
      'message' => 'Employé supprimé.'
    ], 200);
  }


  // Un employé est un utilisateur avec le rôle employeur mais pas administrateur
  private function isEmployer(User $user): bool
  {
    $roles = $user->getRoles();

    return in_array('ROLE_EMPLOYER', $roles, true)
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
  private function serializeEmployer(User $u): array
  {
    return [
      'id' => $u->getId(),
      'pseudo' => $u->getPseudo(),
      'status' => $u->isActive() ? 'active' : 'suspended',
      'createdAt' => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
