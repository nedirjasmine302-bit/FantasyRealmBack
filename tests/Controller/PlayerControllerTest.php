<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\Comment;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PlayerControllerTest extends WebTestCase
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

  private function createUser(string $email = 'player@mail.fr', string $pseudo = 'Player', array $roles = []): User
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


  // Pour tester la liste des joueurs
  public function testListPlayersIsPublic(): void
  {
    $this->createUser('player@mail.fr', 'Player', []);
    $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);

    $response = $this->get('/api/players');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['players']);
    $this->assertEquals('Player', $data['players'][0]['pseudo']);
    $this->assertEquals('active', $data['players'][0]['status']);
    $this->assertArrayNotHasKey('email', $data['players'][0]);
  }

  public function testListPlayersExcludesEmployersAndAdmins(): void
  {
    $this->createUser('player1@mail.fr', 'Player1', []);
    $this->createUser('player2@mail.fr', 'Player2', []);
    $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']);

    $response = $this->get('/api/players');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(2, $data['players']);
  }


  // Pour tester la suspension / réactivation d'un joueur
  public function testUpdateStatusAsEmployer(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('suspended', $data['player']['status']);

    $response = $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'active'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals('active', $data['player']['status']);
  }

  public function testUpdateStatusRequiresAuth(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);

    $response = $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'suspended']);

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testUpdateStatusForbiddenForRegularUser(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('other@mail.fr', 'Other', []));

    $response = $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'suspended'], $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testUpdateStatusInvalid(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->patch('/api/players/' . $player->getId() . '/status', ['status' => 'unknown'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Statut invalide (active ou suspended).', $data['message']);
  }

  public function testUpdateStatusNotFound(): void
  {
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->patch('/api/players/999999/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Joueur introuvable.', $data['message']);
  }

  public function testUpdateStatusRejectsNonPlayer(): void
  {
    $target = $this->createUser('other-employer@mail.fr', 'OtherEmployer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->patch('/api/players/' . $target->getId() . '/status', ['status' => 'suspended'], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('Cet utilisateur n\'est pas un joueur.', $data['message']);
  }


  // Pour tester la suppression définitive d'un joueur
  public function testDeletePlayerAsEmployer(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->delete('/api/players/' . $player->getId(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('Joueur supprimé.', $data['message']);

    $list = json_decode($this->get('/api/players')->getContent(), true);
    $this->assertCount(0, $list['players']);
  }

  public function testDeletePlayerRequiresAuth(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);

    $response = $this->delete('/api/players/' . $player->getId());

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testDeletePlayerForbiddenForRegularUser(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $token = $this->tokenFor($this->createUser('other@mail.fr', 'Other', []));

    $response = $this->delete('/api/players/' . $player->getId(), $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testDeletePlayerNotFound(): void
  {
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->delete('/api/players/999999', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Joueur introuvable.', $data['message']);
  }

  public function testDeletePlayerRejectsNonPlayer(): void
  {
    $target = $this->createUser('other-employer@mail.fr', 'OtherEmployer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']));

    $response = $this->delete('/api/players/' . $target->getId(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('Cet utilisateur n\'est pas un joueur.', $data['message']);
  }


  // Pour tester que la suppression purge bien les données liées au joueur
  public function testDeletePlayerPurgesRelatedData(): void
  {
    $player = $this->createUser('player@mail.fr', 'Player', []);
    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);

    $ownCharacter = $this->createCharacter($player);
    $this->createComment($employer, $ownCharacter, 'valid');

    $otherCharacter = $this->createCharacter($employer);
    $this->createComment($player, $otherCharacter, 'valid');

    $this->addFavorite($player, $otherCharacter);

    $playerId = $player->getId();
    $ownCharacterId = $ownCharacter->getId();

    $token = $this->tokenFor($employer);
    $response = $this->delete('/api/players/' . $playerId, $token);

    $this->assertEquals(200, $response->getStatusCode());

    $this->assertEquals(0, $this->countRows('user', 'id = :id', ['id' => $playerId]));
    $this->assertEquals(0, $this->countRows('characters', 'id = :id', ['id' => $ownCharacterId]));
    $this->assertEquals(0, $this->countRows('comment', 'author_id = :id', ['id' => $playerId]));
    $this->assertEquals(0, $this->countRows('favorites', 'user_id = :id', ['id' => $playerId]));

    $this->assertEquals(1, $this->countRows('characters', 'id = :id', ['id' => $otherCharacter->getId()]));
  }
}
