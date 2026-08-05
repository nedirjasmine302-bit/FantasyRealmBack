<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccessoryControllerTest extends WebTestCase
{
  private $client;

  protected function setUp(): void
  {
    $this->client = static::createClient();

    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
    $connection->executeStatement('TRUNCATE TABLE accessory;');
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

  private function patch(string $url, ?string $token = null)
  {
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($token) {
      $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $this->client->request('PATCH', $url, [], [], $headers);

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

  private function createAccessory(?string $token = null): array
  {
    $token = $token ?? $this->tokenFor($this->createUser());

    return json_decode($this->post('/api/accessories', $this->validPayload(), $token)->getContent(), true);
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

  private function tokenFor(User $user): string
  {
    return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
  }

  private function validPayload(): array
  {
    return [
      'name' => 'Lame',
      'type' => 'weapon',
      'rarity' => 'rare',
      'description' => 'Une lame runique forgée dans les flammes du volcan ancestral.',
      'image' => 'data:image/png;base64,AAAA'
    ];
  }


  // Pour tester la récupération des types
  public function testGetTypesIsPublic(): void
  {
    $response = $this->get('/api/accessory-types');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(3, $data['types']);
    $this->assertEquals('armor', $data['types'][0]['value']);
  }


  // Pour tester la liste des accessoires
  public function testListAccessoriesIsPublic(): void
  {
    $token = $this->tokenFor($this->createUser());
    $this->post('/api/accessories', $this->validPayload(), $token);

    $response = $this->get('/api/accessories');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCount(1, $data['accessories']);
    $this->assertEquals('weapon', $data['accessories'][0]['type']);
  }


  // Pour tester la récupération des détails d'un accessoire
  public function testShowAccessoryIsPublic(): void
  {
    $token = $this->tokenFor($this->createUser());
    $created = json_decode($this->post('/api/accessories', $this->validPayload(), $token)->getContent(), true);

    $response = $this->get('/api/accessories/' . $created['accessory']['id']);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('Lame', $data['accessory']['name']);
    $this->assertEquals('weapon', $data['accessory']['type']);
    $this->assertEquals('rare', $data['accessory']['rarity']);
    $this->assertEquals('Employer', $data['accessory']['creator']);
  }

  public function testShowAccessoryNotFound(): void
  {
    $response = $this->get('/api/accessories/999');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertFalse($data['success']);
    $this->assertEquals('Accessoire introuvable.', $data['message']);
  }


  // Pour tester la création d'un accessoire
  public function testCreateAccessorySuccess(): void
  {
    $token = $this->tokenFor($this->createUser());

    $response = $this->post('/api/accessories', $this->validPayload(), $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(201, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertEquals('weapon', $data['accessory']['type']);
    $this->assertEquals('Employer', $data['accessory']['creator']);
  }

  public function testCreateAccessoryRequiresAuth(): void
  {
    $response = $this->post('/api/accessories', $this->validPayload());

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testCreateAccessoryForbiddenForRegularUser(): void
  {
    $token = $this->tokenFor($this->createUser('player@mail.fr', 'Player', []));

    $response = $this->post('/api/accessories', $this->validPayload(), $token);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testCreateAccessoryAllowedForAdmin(): void
  {
    $token = $this->tokenFor($this->createUser('admin@mail.fr', 'Admin', ['ROLE_ADMIN']));

    $response = $this->post('/api/accessories', $this->validPayload(), $token);

    $this->assertEquals(201, $response->getStatusCode());
  }

  public function testCreateAccessoryNameTooShort(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['name'] = 'La';

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le nom doit contenir entre 3 et 20 caractères.', $data['message']);
  }

  public function testCreateAccessoryNameTooLong(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['name'] = 'Bouclier magique legendaire';

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le nom doit contenir entre 3 et 20 caractères.', $data['message']);
  }

  public function testCreateAccessoryInvalidType(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['type'] = 'n_importe_quoi';

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Le type de l\'accessoire est invalide.', $data['message']);
  }

  public function testCreateAccessoryInvalidRarity(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['rarity'] = 'ultra';

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('La rareté de l\'accessoire est invalide.', $data['message']);
  }

  public function testCreateAccessoryDescriptionTooShort(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    $payload['description'] = 'Trop court';

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('La description doit contenir au moins 30 caractères.', $data['message']);
  }

  public function testCreateAccessoryMissingImage(): void
  {
    $token = $this->tokenFor($this->createUser());

    $payload = $this->validPayload();
    unset($payload['image']);

    $response = $this->post('/api/accessories', $payload, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('L\'image de l\'accessoire est obligatoire.', $data['message']);
  }


  // Pour tester qu'un accessoire est actif par défaut
  public function testAccessoryIsActiveByDefault(): void
  {
    $created = $this->createAccessory();

    $this->assertTrue($created['accessory']['active']);
  }


  // Pour tester l'activation / désactivation d'un accessoire
  public function testToggleActiveAsEmployer(): void
  {
    $token = $this->tokenFor($this->createUser());
    $created = $this->createAccessory($token);
    $id = $created['accessory']['id'];

    $response = $this->patch('/api/accessories/' . $id . '/active', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
    $this->assertFalse($data['active']);

    $response = $this->patch('/api/accessories/' . $id . '/active', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertTrue($data['active']);
  }

  public function testToggleActiveRequiresAuth(): void
  {
    $created = $this->createAccessory();

    $response = $this->patch('/api/accessories/' . $created['accessory']['id'] . '/active');

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testToggleActiveForbiddenForRegularUser(): void
  {
    $created = $this->createAccessory();
    $playerToken = $this->tokenFor($this->createUser('player@mail.fr', 'Player', []));

    $response = $this->patch('/api/accessories/' . $created['accessory']['id'] . '/active', $playerToken);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testToggleActiveNotFound(): void
  {
    $token = $this->tokenFor($this->createUser());

    $response = $this->patch('/api/accessories/999/active', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Accessoire introuvable.', $data['message']);
  }


  // Pour tester la suppression définitive d'un accessoire
  public function testDeleteAccessoryAsEmployer(): void
  {
    $token = $this->tokenFor($this->createUser());
    $created = $this->createAccessory($token);
    $id = $created['accessory']['id'];

    $response = $this->delete('/api/accessories/' . $id, $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);

    $this->assertEquals(404, $this->get('/api/accessories/' . $id)->getStatusCode());
  }

  public function testDeleteAccessoryRequiresAuth(): void
  {
    $created = $this->createAccessory();

    $response = $this->delete('/api/accessories/' . $created['accessory']['id']);

    $this->assertEquals(401, $response->getStatusCode());
  }

  public function testDeleteAccessoryForbiddenForRegularUser(): void
  {
    $created = $this->createAccessory();
    $playerToken = $this->tokenFor($this->createUser('player@mail.fr', 'Player', []));

    $response = $this->delete('/api/accessories/' . $created['accessory']['id'], $playerToken);

    $this->assertEquals(403, $response->getStatusCode());
  }

  public function testDeleteAccessoryNotFound(): void
  {
    $token = $this->tokenFor($this->createUser());

    $response = $this->delete('/api/accessories/999', $token);
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Accessoire introuvable.', $data['message']);
  }
}
