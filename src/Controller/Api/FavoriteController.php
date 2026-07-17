<?php

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class FavoriteController extends AbstractController
{
  // Pour lister les personnages favoris de l'utilisateur connecté
  #[Route('/favorites', name: 'favorites_list', methods: ['GET'])]
  public function list(): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Utilisateur non authentifié.'
      ], 401);
    }

    $favorites = array_map(
      fn (Character $c) => [
        'id' => $c->getId(),
        'name' => $c->getName(),
        'image' => $c->getImage(),
        'creator' => $c->getCreator()?->getPseudo()
      ],
      $user->getFavorites()->toArray()
    );

    return $this->json(['favorites' => $favorites], 200);
  }


  // Pour ajouter un personnage aux favoris
  #[Route('/characters/{id}/favorite', name: 'favorites_add', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function add(int $id, CharacterRepository $characterRepo, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour gérer vos favoris.'
      ], 401);
    }

    $character = $characterRepo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $user->addFavorite($character);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Personnage ajouté à vos favoris.',
      'favorite' => true
    ], 200);
  }


  // Pour retirer un personnage des favoris
  #[Route('/characters/{id}/favorite', name: 'favorites_remove', methods: ['DELETE'], requirements: ['id' => '\d+'])]
  public function remove(int $id, CharacterRepository $characterRepo, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour gérer vos favoris.'
      ], 401);
    }

    $character = $characterRepo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $user->removeFavorite($character);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Personnage retiré de vos favoris.',
      'favorite' => false
    ], 200);
  }
}
