<?php

namespace App\Tests\Controller;

use App\Entity\Accessory;
use App\Entity\Character;
use App\Entity\Comment;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EmployerControllerTest extends WebTestCase
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
    $connection->executeStatement('TRUNCATE TABLE accessory;');
    $connection->executeStatement('TRUNCATE TABLE characters;');
    $connection->executeStatement('TRUNCATE TABLE user;');
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
  }

  private function get(string $url)
  {
    $this->client->request('GET', $url);

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

  private function delete(string $url, ?string $token = null)
  {
    $headers = [];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('DELETE', $url, [], [], $headers);

    return $this->client->getResponse();
  }

  private function createUser(string $email = 'employer@mail.fr', string $pseudo = 'Employer', array $roles = ['ROLE_EMPLOYER']): User
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

  private function createCharacter(User $creator): Character
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();

    $character = new Character();
    $character->setName('Aelyra');
    $character->setType('mage');
    $character->setDescription('Une puissante magicienne des glaces venue du grand nord.');
    $character->setImage('data:image/png;base64,AAAA');
    $character->setAppearance(['hairColor' => 'blond']);
    $character->setStatus('valid');
    $character->setCreator($creator);

    $entityManager->persist($character);
    $entityManager->flush();

    return $character;
  }

  private function createComment(User $author, Character $character, string $status = 'valid'): Comment
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();

    $comment = new Comment();
    $comment->setMessage('Un commentaire assez long pour être valide et lisible.');
    $comment->setRating(4);
    $comment->setStatus($status);
    $comment->setAuthor($author);
    $comment->setCharacter($character);

    $entityManager->persist($comment);
    $entityManager->flush();

    return $comment;
  }

  private function createAccessory(User $creator): Accessory
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();

    $accessory = new Accessory();
    $accessory->setName('Épée de givre');
    $accessory->setType('weapon');
    $accessory->setRarity('rare');
    $accessory->setDescription('Une lame forgée dans la glace éternelle.');
    $accessory->setImage('data:image/png;base64,AAAA');
    $accessory->setCreator($creator);

    $entityManager->persist($accessory);
    $entityManager->flush();

    return $accessory;
  }

  private function addFavorite(User $user, Character $character): void
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();

    $user->addFavorite($character);
    $entityManager->flush();
  }

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function countRows(string $table, string $where = '', array $params = []): int
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $sql = 'SELECT COUNT(*) FROM ' . $table . ($where ? ' WHERE ' . $where : '');

    return (int) $entityManager->getConnection()->fetchOne($sql, $params);
  }


  // Pour tester la liste des employés
  public function testListEmployersIsPublic(): void
  {
    $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $this->createUser('player@mail.fr', 'Player', []);

    $response = $this->get('/api/employers');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['employers']);
    $this->assertEquals('Employer', $data['employers'][0]['pseudo']);
    $this->assertEquals('active', $data['employers'][0]['status']);
    $this->assertArrayNotHasKey('email', $data['employers'][0]);
  }

  public function testListEmployersExcludesPlayersAndAdmins(): void
  {
    $this->createUser('employer1@mail.fr', 'Employer1', ['ROLE_EMPLOYER']);
    $this->createUser('employer2@mail.fr', 'Employer2', ['ROLE_EMPLOYER']);
    $this->createUser('player@mail.fr', 'Player', []);
    $this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']);

    $response = $this->get('/api/employers');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(2, $data['employers']);
  }


  // Pour tester la suspension / réactivation d'un employé
  public function testUpdateStatusAsAdmin(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->patch('/api/employers/' . $employer->getId() . '/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('suspended', $data['employer']['status']);

    $response = $this->patch('/api/employers/' . $employer->getId() . '/status', ['status' => 'active'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals('active', $data['employer']['status']);
  }

  public function testUpdateStatusRequiresAuth(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);

    $response = $this->patch('/api/employers/' . $employer->getId() . '/status', ['status' => 'suspended']);

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testUpdateStatusForbiddenForEmployer(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('other@mail.fr', 'OtherEmployer', ['ROLE_EMPLOYER']));

    $response = $this->patch('/api/employers/' . $employer->getId() . '/status', ['status' => 'suspended'], $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testUpdateStatusInvalid(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->patch('/api/employers/' . $employer->getId() . '/status', ['status' => 'unknown'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Statut invalide (active ou suspended).', $data['message']);
  }

  public function testUpdateStatusNotFound(): void
  {
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->patch('/api/employers/999999/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Employé introuvable.', $data['message']);
  }

  public function testUpdateStatusRejectsNonEmployer(): void
  {
    $target = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->patch('/api/employers/' . $target->getId() . '/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('Cet utilisateur n\'est pas un employé.', $data['message']);
  }


  // Pour tester la suppression définitive d'un employé
  public function testDeleteEmployerAsAdmin(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->delete('/api/employers/' . $employer->getId(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('Employé supprimé.', $data['message']);

    $list = json_decode($this->get('/api/employers')->getContent(), true);
    $this->assertCount(0, $list['employers']);
  }

  public function testDeleteEmployerRequiresAuth(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);

    $response = $this->delete('/api/employers/' . $employer->getId());

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testDeleteEmployerForbiddenForEmployer(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('other@mail.fr', 'OtherEmployer', ['ROLE_EMPLOYER']));

    $response = $this->delete('/api/employers/' . $employer->getId(), $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testDeleteEmployerNotFound(): void
  {
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->delete('/api/employers/999999', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Employé introuvable.', $data['message']);
  }

  public function testDeleteEmployerRejectsNonEmployer(): void
  {
    $target = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->delete('/api/employers/' . $target->getId(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('Cet utilisateur n\'est pas un employé.', $data['message']);
  }


  // Pour tester que la suppression réattribue les accessoires et purge le contenu personnel
  public function testDeleteEmployerReassignsAccessoriesAndPurgesPersonalData(): void
  {
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $admin = $this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']);
  
    $accessory = $this->createAccessory($employer);

    $ownCharacter = $this->createCharacter($employer);
    $otherCharacter = $this->createCharacter($admin);
    $this->createComment($employer, $otherCharacter, 'valid');
    $this->addFavorite($employer, $otherCharacter);

    $employerId = $employer->getId();
    $adminId = $admin->getId();
    $accessoryId = $accessory->getId();
    $ownCharacterId = $ownCharacter->getId();

    $token = $this->tokenFor($admin);
    $response = $this->delete('/api/employers/' . $employerId, $token);

    $this->assertEquals(200, $response->getStatusCode());

    $this->assertEquals(0, $this->countRows('user', 'id = :id', ['id' => $employerId]));
    $this->assertEquals(0, $this->countRows('characters', 'id = :id', ['id' => $ownCharacterId]));
    $this->assertEquals(0, $this->countRows('comment', 'author_id = :id', ['id' => $employerId]));
    $this->assertEquals(0, $this->countRows('favorites', 'user_id = :id', ['id' => $employerId]));

    $this->assertEquals(1, $this->countRows('accessory', 'id = :id', ['id' => $accessoryId]));
    $this->assertEquals(1, $this->countRows('accessory', 'id = :id AND creator_id = :creator', [
      'id' => $accessoryId,
      'creator' => $adminId
    ]));
  }
}
