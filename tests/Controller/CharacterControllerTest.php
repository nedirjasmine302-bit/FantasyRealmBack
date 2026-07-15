<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CharacterControllerTest extends WebTestCase
{
  private $client;

  protected function setUp(): void
  {
    $this->client = static::createClient();

    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
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

  private function patch(string $url, array $payload, ?string $token = null)
  {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('PATCH', $url, [], [], $headers, json_encode($payload));

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

  private function createCharacter(User $creator, string $status = 'draft'): Character
  {
    $entityManager = static::getContainer()->get('doctrine')->getManager();

    $character = new Character();
    $character->setName('Aelyra');
    $character->setType('mage');
    $character->setDescription('Une puissante magicienne des glaces venue du grand nord.');
    $character->setImage('data:image/png;base64,AAAA');
    $character->setAppearance(['hairColor' => 'blond']);
    $character->setStatus($status);
    $character->setCreator($creator);

    $entityManager->persist($character);
    $entityManager->flush();

    return $character;
  }

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function validPayload(): array
  {
    return [
      'name' => 'Aelyra',
      'type' => 'mage',
      'description' => 'Une puissante magicienne des glaces venue du grand nord.',
      'image' => 'data:image/png;base64,AAAA',
      'appearance' => ['hairColor' => 'blond']
    ];
  }


  // Pour tester la création d'un personnage
  public function testCreateCharacterSuccess(): void
  {
    $token = $this->tokenFor($this->createUser());

    $response = $this->post('/api/characters', $this->validPayload(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(201, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('draft', $data['character']['status']);
    $this->assertEquals('Player', $data['character']['creator']);
  }

  public function testCreateCharacterRequiresAuth(): void
  {
    $response = $this->post('/api/characters', $this->validPayload());

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testCreateCharacterNameTooShort(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['name'] = 'Ae';

    $response = $this->post('/api/characters', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le nom doit contenir au moins 3 caractères.', $data['message']);
  }

  public function testCreateCharacterDescriptionTooShort(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['description'] = 'Trop court';

    $response = $this->post('/api/characters', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('La description doit contenir au moins 30 caractères.', $data['message']);
  }

  public function testCreateCharacterMissingImage(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    unset($payload['image']);

    $response = $this->post('/api/characters', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('L\'image du personnage est obligatoire.', $data['message']);
  }


  // Pour tester la liste et le détail
  public function testListCharactersIsPublic(): void
  {
    $this->createCharacter($this->createUser());

    $response = $this->get('/api/characters');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['characters']);
  }

  public function testShowCharacterSuccess(): void
  {
    $character = $this->createCharacter($this->createUser());

    $response = $this->get('/api/characters/' . $character->getId());
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('Aelyra', $data['character']['name']);
  }

  public function testShowCharacterNotFound(): void
  {
    $response = $this->get('/api/characters/999999');

    $this->assertEquals(404, $response->getStatusCode());
  }


  // Pour tester la modification
  public function testUpdateCharacterByOwner(): void
  {
    $owner = $this->createUser();
    $character = $this->createCharacter($owner);
    $token = $this->tokenFor($owner);

    $response = $this->patch('/api/characters/' . $character->getId(), [
      'name' => 'Aelyra la Givrée'
    ], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('Aelyra la Givrée', $data['character']['name']);
  }

  public function testUpdateCharacterNotOwner(): void
  {
    $owner = $this->createUser('owner@mail.fr', 'Owner');
    $character = $this->createCharacter($owner);

    $intruder = $this->createUser('intruder@mail.fr', 'Intruder');
    $token = $this->tokenFor($intruder);

    $response = $this->patch('/api/characters/' . $character->getId(), [
      'name' => 'Piraté'
    ], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(403, $response->getStatusCode());
    $this->assertEquals('Ce personnage ne vous appartient pas.', $data['message']);
  }

  public function testAccessoriesIgnoredWhenNotValid(): void
  {
    $owner = $this->createUser();
    $character = $this->createCharacter($owner, 'draft');
    $token = $this->tokenFor($owner);

    $response = $this->patch('/api/characters/' . $character->getId(), [
      'armor' => 'runic-armor'
    ], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertNull($data['character']['armor']);
  }

  public function testAccessoriesAppliedWhenValid(): void
  {
    $owner = $this->createUser();
    $character = $this->createCharacter($owner, 'valid');
    $token = $this->tokenFor($owner);

    $response = $this->patch('/api/characters/' . $character->getId(), [
      'armor' => 'runic-armor',
      'weapon' => 'blade-of-fate',
      'relique' => 'time-relic'
    ], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('runic-armor', $data['character']['armor']);
    $this->assertEquals('blade-of-fate', $data['character']['weapon']);
    $this->assertEquals('time-relic', $data['character']['relique']);
  }


  // Pour tester le changement de statut
  public function testUpdateStatusRequiresEmployer(): void
  {
    $owner = $this->createUser();
    $character = $this->createCharacter($owner);
    $token = $this->tokenFor($owner);

    $response = $this->patch('/api/characters/' . $character->getId() . '/status', [
      'status' => 'valid'
    ], $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testUpdateStatusByEmployer(): void
  {
    $owner = $this->createUser('owner@mail.fr', 'Owner');
    $character = $this->createCharacter($owner);

    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($employer);

    $response = $this->patch('/api/characters/' . $character->getId() . '/status', [
      'status' => 'valid'
    ], $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('valid', $data['character']['status']);
  }

  public function testUpdateStatusInvalidValue(): void
  {
    $owner = $this->createUser('owner@mail.fr', 'Owner');
    $character = $this->createCharacter($owner);

    $employer = $this->createUser('employer@mail.fr', 'Employer', ['ROLE_EMPLOYER']);
    $token = $this->tokenFor($employer);

    $response = $this->patch('/api/characters/' . $character->getId() . '/status', [
      'status' => 'n_importe_quoi'
    ], $token);

    $this->assertEquals(400, $response->getStatusCode());
  }
}
