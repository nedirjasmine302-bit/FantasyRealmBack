<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[Route('/api', name: 'api_')]
class AuthController extends AbstractController
{
  // Pour s'inscrire
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


  // Pour se connecter
  #[Route('/auth/sign-in', name: 'auth_sign_in', methods: ['POST'])]
  public function signIn(
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    PasswordHasherFactoryInterface $passwordHasherFactory,
    EntityManagerInterface $em,
    JWTTokenManagerInterface $jwtManager,
    RateLimiterFactory $loginLimiter
  ): JsonResponse {
    $limiter = $loginLimiter->create($request->getClientIp());
    $limit = $limiter->consume(1);

    if (!$limit->isAccepted()) {
      return $this->json([
        'status' => 429,
        'data' => [
          'success' => false,
          'message' => 'Trop de tentatives de connexion. Réessayez dans quelques instants.'
        ]
      ], 429);
    }

    $data = json_decode($request->getContent(), true);

    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;

    if (!$email || !$password) {
      return $this->json([
        'status' => 400,
        'data' => [
          'success' => false,
          'message' => 'Email et mot de passe sont obligatoires.'
        ]
      ], 400);
    }

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user) {
      return $this->json([
        'status' => 401,
        'data' => [
          'success' => false,
          'message' => 'Identifiants incorrects.'
        ]
      ], 401);
    }

    $temporaryPassword = false;

    if ($passwordHasher->isPasswordValid($user, $password)) {
      if ($user->getTemporaryPassword() !== null) {
        $user->setTemporaryPassword(null);
        $user->setTemporaryPasswordExpiresAt(null);
        $em->flush();
      }
    } else {
      $tempHash = $user->getTemporaryPassword();
      $expiresAt = $user->getTemporaryPasswordExpiresAt();

      $tempValid =
        $tempHash !== null &&
        $expiresAt !== null &&
        $expiresAt > new \DateTimeImmutable() &&
        $passwordHasherFactory->getPasswordHasher($user)->verify($tempHash, $password);

      if (!$tempValid) {
        return $this->json([
          'status' => 401,
          'data' => [
            'success' => false,
            'message' => 'Identifiants incorrects.'
          ]
        ], 401);
      }

      $temporaryPassword = true;
    }

    $token = $jwtManager->create($user);

    return $this->json([
      'status' => 200,
      'data' => [
        'success' => true,
        'token' => $token,
        'user' => [
          'id' => $user->getId(),
          'email' => $user->getEmail(),
          'pseudo' => $user->getPseudo(),
          'temporaryPassword' => $temporaryPassword
        ]
      ]
    ], 200);
  }


  // Pour vérifier si l'email existe
  #[Route('/users/check-email', name: 'users_check_email', methods: ['GET'])]
  public function usersCheckEmail(Request $request, UserRepository $userRepository): JsonResponse
  {
    $email = $request->query->get('value');

    if (!$email) {
      return $this->json([
        'exists' => false,
        'message' => 'Paramètre "value" (email) manquant.'
      ], 400);
    }

    $exists = $userRepository->existsByEmail($email);

    return $this->json([
      'exists' => $exists
    ], 200);
  }


  // Pour vérifier si email et pseudo correspondent
  #[Route('/users/check-identity', name: 'users_check_identity', methods: ['GET'])]
  public function usersCheckIdentity(Request $request, UserRepository $userRepository): JsonResponse
  {
    $email = $request->query->get('email');
    $pseudo = $request->query->get('pseudo');

    if (!$email || !$pseudo) {
      return $this->json([
        'match' => false,
        'message' => 'Paramètres "email" et "pseudo" sont obligatoires.'
      ], 400);
    }

    $match = $userRepository->matchEmailAndPseudo($email, $pseudo);

    return $this->json([
      'match' => $match
    ], 200);
  }


  // Pour envoyer un mot de passe temporaire par email
  #[Route('/auth/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
  public function forgotPassword(
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    EntityManagerInterface $em,
    MailerInterface $mailer,
    RateLimiterFactory $loginLimiter
  ): JsonResponse {
    $limiter = $loginLimiter->create($request->getClientIp());
    $limit = $limiter->consume(1);
    
    if (!$limit->isAccepted()) {
        return $this->json([
            'status' => 429,
            'data' => [
                'success' => false,
                'message' => 'Trop de tentatives. Réessayez dans quelques instants.'
            ]
        ], 429);
    }

    $data = json_decode($request->getContent(), true);

    $email = $data['email'] ?? null;
    $pseudo = $data['pseudo'] ?? null;

    if (!$email || !$pseudo) {
      return $this->json([
        'success' => false,
        'message' => 'Email et pseudo sont obligatoires.'
      ], 400);
    }

    $user = $userRepository->findOneBy([
      'email' => $email,
      'pseudo' => $pseudo
    ]);

    if (!$user) {
      return $this->json([
        'success' => false,
        'message' => 'Cet email et ce pseudo ne correspondent à aucun compte.'
      ], 404);
    }

    $temporaryPasswordPlain = $this->generateTemporaryPassword(10);
    $temporaryPasswordHashed = $passwordHasher->hashPassword($user, $temporaryPasswordPlain);
    $expiresAt = (new \DateTimeImmutable())->modify('+15 minutes');

    $user->setTemporaryPassword($temporaryPasswordHashed);
    $user->setTemporaryPasswordExpiresAt($expiresAt);

    $em->persist($user);
    $em->flush();

    $emailMessage = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to($user->getEmail())
      ->subject('Votre mot de passe temporaire')
      ->text(sprintf(
        "Bonjour %s,\n\nVoici votre mot de passe temporaire : %s\nIl expirera dans 15 minutes.\n\nÀ bientôt sur FantasyRealm Online !",
        $user->getPseudo(),
        $temporaryPasswordPlain
      ));

    $mailer->send($emailMessage);

    return $this->json([
      'success' => true,
      'message' => 'Temporary password sent.'
    ], 200);
  }

  private function generateTemporaryPassword(int $length = 10): string
  {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';

    for ($i = 0; $i < $length; $i++) {
      $password .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $password;
  }

    // Pour vérifier si le mot de passe temporaire
    #[Route('/auth/verify-temp-password', name: 'auth_verify_temp_password', methods: ['POST'])]
    public function verifyTemporaryPassword(
      Request $request,
      UserRepository $userRepository,
      PasswordHasherFactoryInterface $passwordHasherFactory
      ): JsonResponse {
      $data = json_decode($request->getContent(), true);
  
      $email = $data['email'] ?? null;
      $temporaryPassword = $data['temporaryPassword'] ?? null;
  
      if (empty($email) || empty($temporaryPassword)) {
          return $this->json([
              'valid' => false,
              'message' => 'Email et mot de passe temporaire sont obligatoires.'
          ], 400);
      }
  
      $user = $userRepository->findOneBy(['email' => $email]);
  
      if (!$user) {
          return $this->json([
              'valid' => false,
              'message' => 'Aucun compte trouvé pour cet email.'
          ], 404);
      }
  
      if (!$user->getTemporaryPassword() || !$user->getTemporaryPasswordExpiresAt()) {
          return $this->json([
              'valid' => false,
              'message' => 'Aucun mot de passe temporaire actif pour ce compte.'
          ], 400);
      }
  
      $now = new \DateTimeImmutable();
      if ($now > $user->getTemporaryPasswordExpiresAt()) {
          return $this->json([
              'valid' => false,
              'message' => 'Mot de passe temporaire expiré.'
          ], 400);
      }
  
      $tempHash = $user->getTemporaryPassword();
      $tempValid = $passwordHasherFactory->getPasswordHasher($user)->verify($tempHash, $temporaryPassword);
  
      if (!$tempValid) {
          return $this->json([
              'valid' => false,
              'message' => 'Mot de passe temporaire incorrect.'
          ], 401);
      }
  
      return $this->json([
          'valid' => true
      ], 200);
    }


  // Pour vérifie si le mot de passe est sécurisé
  private function isStrongPassword(string $password): bool
  {
    if (strlen($password) < 8) {
      return false;
    }

    if (!preg_match('/[A-Z]/', $password)) {
      return false;
    }

    if (!preg_match('/[a-z]/', $password)) {
      return false;
    }

    if (!preg_match('/\d/', $password)) {
      return false;
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
      return false;
    }

    return true;
  }

  // Pour changer le mot de passe via le mot de passe temporaire
  #[Route('/auth/reset-password', name: 'auth_reset_password', methods: ['POST'])]
  public function resetPassword(
      Request $request,
      UserRepository $userRepository,
      UserPasswordHasherInterface $passwordHasher,
      EntityManagerInterface $em
  ): JsonResponse
  {
      $data = json_decode($request->getContent(), true);
  
      $email = $data['email'] ?? null;
      $temporaryPassword = $data['temporaryPassword'] ?? null;
      $newPassword = $data['newPassword'] ?? null;
  
      if (empty($email) || empty($temporaryPassword) || empty($newPassword)) {
          return $this->json([
              'success' => false,
              'message' => 'Email, mot de passe temporaire et nouveau mot de passe sont obligatoires.'
          ], 400);
      }
  
      if (!$this->isStrongPassword($newPassword)) {
          return $this->json([
              'success' => false,
              'message' => 'Mot de passe non conforme.'
          ], 400);
      }
  
      $user = $userRepository->findOneBy(['email' => $email]);
  
      if (!$user) {
          return $this->json([
              'success' => false,
              'message' => 'Aucun compte trouvé pour cet email.'
          ], 404);
      }
  
      if (!$user->getTemporaryPassword() || !$user->getTemporaryPasswordExpiresAt()) {
          return $this->json([
              'success' => false,
              'message' => 'Aucun mot de passe temporaire actif pour ce compte.'
          ], 400);
      }
  
      $now = new \DateTimeImmutable();
      if ($now > $user->getTemporaryPasswordExpiresAt()) {
          return $this->json([
              'success' => false,
              'message' => 'Mot de passe temporaire expiré.'
          ], 400);
      }
  
      if (!password_verify($temporaryPassword, $user->getTemporaryPassword())) {
          return $this->json([
              'success' => false,
              'message' => 'Mot de passe temporaire incorrect.'
          ], 401);
      }
  
      $hashedNewPassword = $passwordHasher->hashPassword($user, $newPassword);
      $user->setPassword($hashedNewPassword);
  
      $user->setTemporaryPassword(null);
      $user->setTemporaryPasswordExpiresAt(null);
  
      $em->flush();
  
      return $this->json([
          'success' => true
      ], 200);
  }
}
