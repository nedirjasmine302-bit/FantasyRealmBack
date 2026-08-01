<?php

namespace App\Controller\Api;

use App\Entity\Accessory;
use App\Entity\User;
use App\Repository\AccessoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class AccessoryController extends AbstractController
{
  // Les types d'accessoires possibles
  private const TYPES = [
    ['value' => 'armor', 'label' => 'Armure'],
    ['value' => 'weapon', 'label' => 'Arme'],
    ['value' => 'relique', 'label' => 'Relique'],
  ];

  private const RARITIES = ['standard', 'rare', 'legendary'];


  // Pour récupérer les types d'accessoires disponibles
  #[Route('/accessory-types', name: 'accessory_types', methods: ['GET'])]
  public function types(): JsonResponse
  {
    return $this->json(['types' => self::TYPES], 200);
  }


  // Pour lister les accessoires
  #[Route('/accessories', name: 'accessories_list', methods: ['GET'])]
  public function list(AccessoryRepository $repo): JsonResponse
  {
    $accessories = $repo->findBy([], ['createdAt' => 'DESC']);

    return $this->json([
      'accessories' => array_map(
        fn (Accessory $a) => $this->serializeAccessory($a),
        $accessories
      )
    ], 200);
  }


  // Pour créer un accessoire
  #[Route('/accessories', name: 'accessories_create', methods: ['POST'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function create(Request $request, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour créer un accessoire.'
      ], 401);
    }

    $data = json_decode($request->getContent(), true);

    $name = trim($data['name'] ?? '');
    $type = trim($data['type'] ?? '');
    $rarity = trim($data['rarity'] ?? '');
    $description = trim($data['description'] ?? '');
    $image = $data['image'] ?? null;

    if (mb_strlen($name) < 3 || mb_strlen($name) > 20) {
      return $this->json([
        'success' => false,
        'message' => 'Le nom doit contenir entre 3 et 20 caractères.'
      ], 400);
    }

    if (!in_array($type, array_column(self::TYPES, 'value'), true)) {
      return $this->json([
        'success' => false,
        'message' => 'Le type de l\'accessoire est invalide.'
      ], 400);
    }

    if (!in_array($rarity, self::RARITIES, true)) {
      return $this->json([
        'success' => false,
        'message' => 'La rareté de l\'accessoire est invalide.'
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
        'message' => 'L\'image de l\'accessoire est obligatoire.'
      ], 400);
    }

    $accessory = new Accessory();
    $accessory->setName($name);
    $accessory->setType($type);
    $accessory->setRarity($rarity);
    $accessory->setDescription($description);
    $accessory->setImage($image);
    $accessory->setCreator($user);

    $em->persist($accessory);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Votre accessoire a été créé avec succès !',
      'accessory' => $this->serializeAccessory($accessory)
    ], 201);
  }


  // Pour transformer un Accessory en tableau JSON
  private function serializeAccessory(Accessory $a): array
  {
    return [
      'id' => $a->getId(),
      'name' => $a->getName(),
      'type' => $a->getType(),
      'rarity' => $a->getRarity(),
      'description' => $a->getDescription(),
      'image' => $a->getImage(),
      'creator' => $a->getCreator()?->getPseudo(),
      'createdAt' => $a->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
