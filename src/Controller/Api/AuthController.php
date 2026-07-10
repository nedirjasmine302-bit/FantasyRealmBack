<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class AuthController extends AbstractController
{
  //Pour s'inscrire
  #[Route('/sign-up', name: 'sign_up', methods: ['POST'])]
  public function signUp(
    Request $request,
    EntityManagerInterface $em,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher
  ): JsonResponse {

    $data = json_decode($request->getContent(), true);

    $email = $data['email'] ?? null;
    $pseudo = $data['pseudo'] ?? null;
    $password = $data['password'] ?? null;
    $password2 = $data['password2'] ?? null;

    $response = [
      'success' => false,
      'message' => ''
    ];
    $status = 400;

    if (!$email || !$pseudo || !$password || !$password2) {
      $response['message'] = 'Tous les champs sont obligatoires.';
    } elseif ($password !== $password2) {
      $response['message'] = 'La confirmation n\'est pas identique au mot de passe.';
    } elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
      $response['message'] = 'Email invalide.';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $pseudo)) {
      $response['message'] = 'Pseudo invalide (3 à 20 caractères, lettres, chiffres, _ -).';
    } elseif (
      strlen($password) < 8 ||
      !preg_match('/[A-Z]/', $password) ||
      !preg_match('/[a-z]/', $password) ||
      !preg_match('/\d/', $password) ||
      !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
      $response['message'] = 'Mot de passe non conforme.';
    } elseif ($userRepository->findOneBy(['email' => $email])) {
      $response['message'] = 'Cet email est déjà utilisé.';
    } elseif ($userRepository->findOneBy(['pseudo' => $pseudo])) {
      $response['message'] = 'Ce pseudo est déjà utilisé.';
    } else {
      $user = new User();
      $user->setEmail($email);
      $user->setPseudo($pseudo);

      $hashedPassword = $passwordHasher->hashPassword($user, $password);
      $user->setPassword($hashedPassword);

      $em->persist($user);
      $em->flush();

      $response = [
        'success' => true,
        'message' => 'Votre compte a été créé avec succès !',
        'user' => [
          'id' => $user->getId(),
          'email' => $user->getEmail(),
          'pseudo' => $user->getPseudo(),
        ]
      ];
      $status = 201;
    }

    return $this->json($response, $status);
  }

  // Pour vérifier si l'email est unique
  #[Route('/check-email', name: 'check_email', methods: ['GET'])]
  public function checkEmail(Request $request, UserRepository $repo): JsonResponse
  {
    $email = $request->query->get('email');
    
    $exists = $repo->findOneBy(['email' => $email]) !== null;
    
    return $this->json(['unique' => !$exists]);
  }

  // Pour vérifier si le pseudo est unique
  #[Route('/check-pseudo', name: 'check_pseudo', methods: ['GET'])]
  public function checkPseudo(Request $request, UserRepository $repo): JsonResponse
  {
    $pseudo = $request->query->get('pseudo');
  
    $exists = $repo->findOneBy(['pseudo' => $pseudo]) !== null;
  
    return $this->json(['unique' => !$exists]);
  }
}
