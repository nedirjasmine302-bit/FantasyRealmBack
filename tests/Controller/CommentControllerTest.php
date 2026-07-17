<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\Comment;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CommentControllerTest extends WebTestCase
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

  private function get(string $url)
  {
    $this->client->request('GET', $url);

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

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function validPayload(): array
  {
    return [
      'message' => 'Un commentaire assez long pour être valide et lisible.',
      'rating' => 5
    ];
  }


  // Pour tester la récupération des commentaires validés
  public function testListReturnsOnlyValidatedComments(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $this->createComment($user, $character, 'valid');
    $this->createComment($user, $character, 'pending');

    $response = $this->get('/api/characters/' . $character->getId() . '/comments');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['comments']);
    $this->assertEquals('Player', $data['comments'][0]['author']);
    $this->assertEquals(4, $data['comments'][0]['rating']);
  }

  public function testListCharacterNotFound(): void
  {
    $response = $this->get('/api/characters/999999/comments');

    $this->assertEquals(404, $response->getStatusCode());
  }


  // Pour tester la publication d'un commentaire
  public function testCreateCommentSuccess(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $response = $this->post('/api/characters/' . $character->getId() . '/comments', $this->validPayload(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(201, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('pending', $data['comment']['status']);
    $this->assertEquals('Player', $data['comment']['author']);
  }

  public function testCreateCommentRequiresAuth(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);

    $response = $this->post('/api/characters/' . $character->getId() . '/comments', $this->validPayload());

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testCreateCommentMessageTooShort(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $payload = $this->validPayload();
    $payload['message'] = 'Trop court';

    $response = $this->post('/api/characters/' . $character->getId() . '/comments', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le commentaire doit contenir au moins 30 caractères.', $data['message']);
  }

  public function testCreateCommentInvalidRating(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $payload = $this->validPayload();
    $payload['rating'] = 0;

    $response = $this->post('/api/characters/' . $character->getId() . '/comments', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('La note doit être comprise entre 1 et 5.', $data['message']);
  }
}
