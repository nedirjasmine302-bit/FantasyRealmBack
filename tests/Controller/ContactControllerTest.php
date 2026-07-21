<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mime\Email;

class ContactControllerTest extends WebTestCase
{
  private $client;

  protected function setUp(): void
  {
    $this->client = static::createClient();

    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
    $connection->executeStatement('TRUNCATE TABLE comment;');
    $connection->executeStatement('TRUNCATE TABLE favorites;');
    $connection->executeStatement('TRUNCATE TABLE characters;');
    $connection->executeStatement('TRUNCATE TABLE user;');
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
  }

  private function post(string $url, array $payload, ?string $token = null)
  {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('POST', $url, [], [], $headers, json_encode($payload));

    return $this->client->getResponse();
  }

  private function get(string $url, ?string $token = null)
  {
    $headers = [];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('GET', $url, [], [], $headers);

    return $this->client->getResponse();
  }

  private function createUser(string $email = 'member@mail.fr', string $pseudo = 'Member'): User
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

    $user = new User();
    $user->setEmail($email);
    $user->setPseudo($pseudo);
    $user->setPassword($passwordHasher->hashPassword($user, 'Test123!'));

    $entityManager->persist($user);
    $entityManager->flush();

    return $user;
  }

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function validMessage(): string
  {
    return 'Bonjour, j\'ai une question assez longue a poser au support FantasyRealm.';
  }


  // Pour tester l'envoi par un visiteur
  public function testAnonymousContactSuccess(): void
  {
    $response = $this->post('/api/contact', [
      'email' => 'visitor@mail.fr',
      'message' => $this->validMessage()
    ]);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEmailCount(1);
  }

  public function testAnonymousContactWithoutPseudoWorks(): void
  {
    $response = $this->post('/api/contact', [
      'email' => 'visitor@mail.fr',
      'message' => $this->validMessage()
    ]);

    $this->assertEquals(200, $response->getStatusCode());
  }

  public function testContactRequiresValidEmail(): void
  {
    $response = $this->post('/api/contact', [
      'email' => 'pas-un-email',
      'message' => $this->validMessage()
    ]);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Un email valide est obligatoire.', $data['message']);
  }

  public function testContactMessageTooShort(): void
  {
    $response = $this->post('/api/contact', [
      'email' => 'visitor@mail.fr',
      'message' => 'Trop court'
    ]);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le message doit contenir au moins 30 caractères.', $data['message']);
  }


  // Pour tester l'envoi par un membre connecté
  public function testLoggedInContactUsesAccountEmail(): void
  {
    $token = $this->tokenFor($this->createUser('member@mail.fr', 'Member'));

    $response = $this->post('/api/contact', [
      'email' => 'usurpateur@mail.fr',
      'message' => $this->validMessage()
    ], $token);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEmailCount(1);

    $email = $this->getMailerMessage(0);
    $this->assertInstanceOf(Email::class, $email);
    $this->assertStringContainsString('member@mail.fr', $email->getTextBody());
    $this->assertStringNotContainsString('usurpateur@mail.fr', $email->getTextBody());
  }


  // Pour tester la récupération de l'utilisateur connecté
  public function testMeReturnsCurrentUser(): void
  {
    $token = $this->tokenFor($this->createUser('member@mail.fr', 'Member'));

    $response = $this->get('/api/me', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('member@mail.fr', $data['email']);
    $this->assertEquals('Member', $data['pseudo']);
  }

  public function testMeRequiresAuth(): void
  {
    $response = $this->get('/api/me');

    $this->assertEquals(401, $response->getStatusCode());
  }
}
