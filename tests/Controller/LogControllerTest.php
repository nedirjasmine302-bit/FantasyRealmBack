<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use MongoDB\Client;
use MongoDB\Collection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LogControllerTest extends WebTestCase
{
  private $client;
  private ?Collection $logs = null;

  protected function setUp(): void
  {
    $this->client = static::createClient();

    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
    $connection->executeStatement('TRUNCATE TABLE comment;');
    $connection->executeStatement('TRUNCATE TABLE favorites;');
    $connection->executeStatement('TRUNCATE TABLE accessory;');
    $connection->executeStatement('TRUNCATE TABLE characters;');
    $connection->executeStatement('TRUNCATE TABLE user;');
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');

    if ($collection = $this->logsCollection()) {
      $collection->deleteMany([]);
    }
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

  private function patch(string $url, array $payload, ?string $token = null)
  {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('PATCH', $url, [], [], $headers, json_encode($payload));

    return $this->client->getResponse();
  }

  private function createUser(string $email, string $pseudo, array $roles = []): User
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

    $user = new User();
    $user->setEmail($email);
    $user->setPseudo($pseudo);
    $user->setPassword($passwordHasher->hashPassword($user, 'Test123!'));
    $user->setRoles($roles);

    $entityManager->persist($user);
    $entityManager->flush();

    return $user;
  }

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function logsCollection(): ?Collection
  {
    if ($this->logs === null) {
      try {
        $url = $_ENV['MONGODB_URL'] ?? getenv('MONGODB_URL') ?: 'mongodb://mongo:27017';
        $db = $_ENV['MONGODB_DB'] ?? getenv('MONGODB_DB') ?: 'fantasyrealm_logs';

        $collection = (new Client($url))->getDatabase($db)->getCollection('activity_logs');
        $collection->countDocuments();

        $this->logs = $collection;
      } catch (\Throwable $e) {
        return null;
      }
    }

    return $this->logs;
  }


  // Pour tester que le journal est réservé aux utilisateurs authentifiés
  public function testLogsRequiresAuth(): void
  {
    $response = $this->get('/api/logs');

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testLogsForbiddenForPlayer(): void
  {
    $token = $this->tokenFor($this->createUser('player@mail.fr', 'Player', []));

    $response = $this->get('/api/logs', $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testLogsForbiddenForEmployer(): void
  {
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->get('/api/logs', $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testLogsAsAdminReturnsArray(): void
  {
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->get('/api/logs', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertArrayHasKey('logs', $data);
    $this->assertIsArray($data['logs']);
  }


  // Pour tester qu'une action de l'admin est bien enregistrée dans le journal
  public function testActionIsLogged(): void
  {
    if (!$this->logsCollection()) {
      $this->markTestSkipped('MongoDB indisponible.');
    }

    $player = $this->createUser('player@mail.fr', 'PlayerToSuspend', []);
    $admin = $this->createUser('admin@mail.fr', 'AdminMaster', ['ROLE_ADMIN']);
    $token = $this->tokenFor($admin);

    $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'suspended'], $token);

    $response = $this->get('/api/logs', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['logs']);

    $log = $data['logs'][0];
    $this->assertEquals('admin', $log['userType']);
    $this->assertEquals('suspend', $log['action']);
    $this->assertEquals('AdminMaster', $log['actorPseudo']);
    $this->assertStringContainsString('PlayerToSuspend', $log['message']);
  }
}
