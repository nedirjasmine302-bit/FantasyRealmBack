<?php

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class CharacterController extends AbstractController
{
  // Pour créer un personnage
  #[Route('/characters', name: 'characters_create', methods: ['POST'])]
  public function create(Request $request, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour créer un personnage.'
      ], 401);
    }

    $data = json_decode($request->getContent(), true);

    $name = trim($data['name'] ?? '');
    $type = trim($data['type'] ?? $data['gender'] ?? '');
    $description = trim($data['description'] ?? '');
    $image = $data['image'] ?? null;
    $appearance = $data['appearance'] ?? [];

    if (mb_strlen($name) < 3) {
      return $this->json([
        'success' => false,
        'message' => 'Le nom doit contenir au moins 3 caractères.'
      ], 400);
    }

    if ($type === '') {
      return $this->json([
        'success' => false,
        'message' => 'Le type du personnage est obligatoire.'
      ], 400);
    }

    if (mb_strlen($description) < 30) {
      return $this->json([
        'success' => false,
        'message' => 'La description doit contenir au moins 30 caractères.'
      ], 400);
    }

    if (!$image) {
      return $this->json([
        'success' => false,
        'message' => 'L\'image du personnage est obligatoire.'
      ], 400);
    }

    $character = new Character();
    $character->setName($name);
    $character->setType($type);
    $character->setDescription($description);
    $character->setImage($image);
    $character->setAppearance(is_array($appearance) ? $appearance : []);
    $character->setStatus('draft'); //à faire valider par un employé
    $character->setCreator($user);

    $em->persist($character);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Votre personnage a été créé et est en attente de validation.',
      'character' => $this->serializeCharacter($character)
    ], 201);
  }


  // Pour lister les personnages
  #[Route('/characters', name: 'characters_list', methods: ['GET'])]
  public function list(CharacterRepository $repo): JsonResponse
  {
    $characters = $repo->findBy([], ['createdAt' => 'DESC']);

    return $this->json([
      'characters' => array_map(
        fn (Character $c) => $this->serializeCharacter($c),
        $characters
      )
    ], 200);
  }


  // Pour afficher le détail d'un personnage
  #[Route('/characters/{id}', name: 'characters_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function show(int $id, CharacterRepository $repo): JsonResponse
  {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    return $this->json([
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour modifier un personnage
  #[Route('/characters/{id}', name: 'characters_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  public function update(
    int $id,
    Request $request,
    CharacterRepository $repo,
    EntityManagerInterface $em
  ): JsonResponse {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Utilisateur non authentifié.'
      ], 401);
    }

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId()) {
      return $this->json([
        'success' => false,
        'message' => 'Ce personnage ne vous appartient pas.'
      ], 403);
    }

    $data = json_decode($request->getContent(), true) ?? [];

    if (isset($data['name'])) {
      $name = trim($data['name']);

      if (mb_strlen($name) < 3) {
        return $this->json([
          'success' => false,
          'message' => 'Le nom doit contenir au moins 3 caractères.'
        ], 400);
      }

      $character->setName($name);
    }

    if (isset($data['type'])) {
      $character->setType(trim($data['type']));
    }

    if (isset($data['description'])) {
      $description = trim($data['description']);

      if (mb_strlen($description) < 30) {
        return $this->json([
          'success' => false,
          'message' => 'La description doit contenir au moins 30 caractères.'
        ], 400);
      }

      $character->setDescription($description);
    }

    if (isset($data['image'])) {
      $character->setImage($data['image']);
    }

    if (isset($data['appearance']) && is_array($data['appearance'])) {
      $character->setAppearance($data['appearance']);
    }

    if ($character->getStatus() === 'valid') {
      if (array_key_exists('armor', $data)) {
        $character->setArmor($data['armor']);
      }

      if (array_key_exists('weapon', $data)) {
        $character->setWeapon($data['weapon']);
      }

      if (array_key_exists('relique', $data)) {
        $character->setRelique($data['relique']);
      }
    }

    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Personnage mis à jour.',
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour changer le statut d'un personnage
  #[Route('/characters/{id}/status', name: 'characters_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function updateStatus(
    int $id,
    Request $request,
    CharacterRepository $repo,
    EntityManagerInterface $em
  ): JsonResponse {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
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

    $character->setStatus($status);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Statut mis à jour.',
      'character' => $this->serializeCharacter($character)
    ], 200);
  }

  // Pour transformer un Character en tableau JSON
  private function serializeCharacter(Character $c): array
  {
    return [
      'id' => $c->getId(),
      'name' => $c->getName(),
      'type' => $c->getType(),
      'description' => $c->getDescription(),
      'image' => $c->getImage(),
      'appearance' => $c->getAppearance(),
      'status' => $c->getStatus(),
      'armor' => $c->getArmor(),
      'weapon' => $c->getWeapon(),
      'relique' => $c->getRelique(),
      'creator' => $c->getCreator()?->getPseudo(),
      'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
