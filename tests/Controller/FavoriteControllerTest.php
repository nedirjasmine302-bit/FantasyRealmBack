<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FavoriteControllerTest extends WebTestCase
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

  private function request(string $method, string $url, ?string $token = null)
  {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request($method, $url, [], [], $headers);

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

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }


  // Pour tester l'ajout aux favoris
  public function testAddFavorite(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $response = $this->request('POST', '/api/characters/' . $character->getId() . '/favorite', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertTrue($data['favorite']);

    $list = json_decode($this->request('GET', '/api/favorites', $token)->getContent(), true);
    $this->assertCount(1, $list['favorites']);
    $this->assertEquals('Aelyra', $list['favorites'][0]['name']);
    $this->assertEquals('mage', $list['favorites'][0]['type']);
  }

  public function testAddFavoriteIsIdempotent(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $this->request('POST', '/api/characters/' . $character->getId() . '/favorite', $token);
    $this->request('POST', '/api/characters/' . $character->getId() . '/favorite', $token);

    $list = json_decode($this->request('GET', '/api/favorites', $token)->getContent(), true);
    $this->assertCount(1, $list['favorites']);
  }


  // Pour tester le retrait des favoris
  public function testRemoveFavorite(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);
    $token = $this->tokenFor($user);

    $this->request('POST', '/api/characters/' . $character->getId() . '/favorite', $token);

    $response = $this->request('DELETE', '/api/characters/' . $character->getId() . '/favorite', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertFalse($data['favorite']);

    $list = json_decode($this->request('GET', '/api/favorites', $token)->getContent(), true);
    $this->assertCount(0, $list['favorites']);
  }


  // Pour tester que les favoris nécessitent une authentification
  public function testAddFavoriteRequiresAuth(): void
  {
    $user = $this->createUser();
    $character = $this->createCharacter($user);

    $response = $this->request('POST', '/api/characters/' . $character->getId() . '/favorite');

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testListFavoritesRequiresAuth(): void
  {
    $response = $this->request('GET', '/api/favorites');

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testAddFavoriteCharacterNotFound(): void
  {
    $token = $this->tokenFor($this->createUser());

    $response = $this->request('POST', '/api/characters/999999/favorite', $token);

    $this->assertEquals(404, $response->getStatusCode());
  }
}
