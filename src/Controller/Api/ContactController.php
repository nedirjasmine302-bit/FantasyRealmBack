<?php

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class ContactController extends AbstractController
{
  // Pour envoyer un message au support
  #[Route('/contact', name: 'contact_send', methods: ['POST'])]
  public function send(Request $request, MailerInterface $mailer): JsonResponse
  {
    $data = json_decode($request->getContent(), true) ?? [];
    $user = $this->getUser();

    if ($user instanceof User) {
      $email = $user->getEmail();
      $pseudo = $user->getPseudo();
    } else {
      $email = trim($data['email'] ?? '');
      $pseudo = trim($data['pseudo'] ?? '');
    }

    $message = trim($data['message'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->json([
        'success' => false,
        'message' => 'Un email valide est obligatoire.'
      ], 400);
    }

    if (mb_strlen($message) < 30) {
      return $this->json([
        'success' => false,
        'message' => 'Le message doit contenir au moins 30 caractères.'
      ], 400);
    }

    $mail = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to('support@fantasyrealm-online.com')
      ->replyTo($email)
      ->subject('Nouveau message de contact')
      ->text(sprintf(
        "Email : %s\nPseudo : %s\n\nMessage :\n%s",
        $email,
        $pseudo !== '' ? $pseudo : '(non renseigné)',
        $message
      ));

    $mailer->send($mail);

    return $this->json([
      'success' => true,
      'message' => 'Votre message a bien été envoyé. Nous vous répondrons rapidement.'
    ], 200);
  }
}
