<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthControllerTest extends WebTestCase
{
  private $client;

  protected function setUp(): void
  {
    $this->client = static::createClient();

    $entityManager = static::getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
    $connection->executeStatement('TRUNCATE TABLE user;');
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');

    $loginLimiter = static::getContainer()->get('limiter.login');
    $loginLimiter->create('127.0.0.1')->reset();
  }

  private function post(string $url, array $payload)
  {
    $this->client->request(
      'POST',
      $url,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($payload)
    );

    return $this->client->getResponse();
  }

  private function get(string $url)
  {
    $this->client->request('GET', $url);
    return $this->client->getResponse();
  }

  private function createUser()
  {
    return $this->post('/api/sign-up', [
      'email' => 'existing@mail.fr',
      'pseudo' => 'ExistingUser',
      'password' => 'Test123!',
      'password2' => 'Test123!'
    ]);
  }


  // Pour tester sign-up
  public function testSignUpSuccess(): void
  {
    $response = $this->post('/api/sign-up', [
      'email' => 'success@mail.fr',
      'pseudo' => 'SuccessUser',
      'password' => 'Test123!',
      'password2' => 'Test123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(201, $response->getStatusCode());
    $this->assertTrue($data['success']);
  }

  public function testEmailAlreadyUsed(): void
  {
    $this->createUser();

    $response = $this->post('/api/sign-up', [
      'email' => 'existing@mail.fr',
      'pseudo' => 'AnotherUser',
      'password' => 'Test123!',
      'password2' => 'Test123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Cet email est déjà utilisé.', $data['message']);
  }

  public function testPseudoAlreadyUsed(): void
  {
    $this->createUser();

    $response = $this->post('/api/sign-up', [
      'email' => 'new@mail.fr',
      'pseudo' => 'ExistingUser',
      'password' => 'Test123!',
      'password2' => 'Test123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Ce pseudo est déjà utilisé.', $data['message']);
  }

  public function testInvalidEmail(): void
  {
    $response = $this->post('/api/sign-up', [
      'email' => 'invalid-email',
      'pseudo' => 'UserTest',
      'password' => 'Test123!',
      'password2' => 'Test123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Email invalide.', $data['message']);
  }

  public function testPasswordNotStrong(): void
  {
    $response = $this->post('/api/sign-up', [
      'email' => 'valid@mail.fr',
      'pseudo' => 'UserTest',
      'password' => 'weak',
      'password2' => 'weak'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Mot de passe non conforme.', $data['message']);
  }

  public function testPasswordConfirmationMismatch(): void
  {
    $response = $this->post('/api/sign-up', [
      'email' => 'valid@mail.fr',
      'pseudo' => 'UserTest',
      'password' => 'Test123!',
      'password2' => 'Different123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('La confirmation n\'est pas identique au mot de passe.', $data['message']);
  }


  // Pour tester sign-in
  public function testSignInSuccess(): void
  {
    $this->createUser();

    $response = $this->post('/api/auth/sign-in', [
      'email' => 'existing@mail.fr',
      'password' => 'Test123!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['data']['success']);
    $this->assertArrayHasKey('token', $data['data']);
  }

  public function testSignInWrongPassword(): void
  {
    $this->createUser();

    $response = $this->post('/api/auth/sign-in', [
      'email' => 'existing@mail.fr',
      'password' => 'WrongPass!'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(401, $response->getStatusCode());
    $this->assertEquals('Identifiants incorrects.', $data['data']['message']);
  }

  public function testSignInMissingFields(): void
  {
    $response = $this->post('/api/auth/sign-in', [
      'email' => '',
      'password' => ''
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Email et mot de passe sont obligatoires.', $data['data']['message']);
  }


  // Pour tester l'unicité email/pseudo
  public function testCheckEmailUniqueFalse(): void
  {
    $this->createUser();

    $response = $this->get('/api/check-email?email=existing@mail.fr');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($data['unique']);
  }

  public function testCheckEmailUniqueTrue(): void
  {
    $response = $this->get('/api/check-email?email=new@mail.fr');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['unique']);
  }

  public function testCheckPseudoUniqueFalse(): void
  {
    $this->createUser();

    $response = $this->get('/api/check-pseudo?pseudo=ExistingUser');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($data['unique']);
  }

  public function testCheckPseudoUniqueTrue(): void
  {
    $response = $this->get('/api/check-pseudo?pseudo=NewUser');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['unique']);
  }


  // Pour tester l'existence d'un email et si email et pseudo correspondent
  public function testUsersCheckEmailExists(): void
  {
    $this->createUser();

    $response = $this->get('/api/users/check-email?value=existing@mail.fr');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['exists']);
  }

  public function testUsersCheckEmailNotExists(): void
  {
    $response = $this->get('/api/users/check-email?value=unknown@mail.fr');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($data['exists']);
  }

  public function testUsersCheckEmailMissingParam(): void
  {
    $response = $this->get('/api/users/check-email');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Paramètre "value" (email) manquant.', $data['message']);
  }

  public function testUsersCheckIdentityMatch(): void
  {
    $this->createUser();

    $response = $this->get('/api/users/check-identity?email=existing@mail.fr&pseudo=ExistingUser');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['match']);
  }

  public function testUsersCheckIdentityNotMatch(): void
  {
    $this->createUser();

    $response = $this->get('/api/users/check-identity?email=existing@mail.fr&pseudo=WrongUser');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($data['match']);
  }

  public function testUsersCheckIdentityMissingParams(): void
  {
    $response = $this->get('/api/users/check-identity?email=existing@mail.fr');
    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Paramètres "email" et "pseudo" sont obligatoires.', $data['message']);
  }


  // Pour tester l'envoi d'un mot de passe temporaire par email
  public function testForgotPasswordSuccess(): void
  {
    $this->createUser();

    $response = $this->post('/api/auth/forgot-password', [
      'email' => 'existing@mail.fr',
      'pseudo' => 'ExistingUser'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($data['success']);
  }

  public function testForgotPasswordWrongIdentity(): void
  {
    $this->createUser();

    $response = $this->post('/api/auth/forgot-password', [
      'email' => 'existing@mail.fr',
      'pseudo' => 'WrongUser'
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(404, $response->getStatusCode());
    $this->assertEquals('Cet email et ce pseudo ne correspondent à aucun compte.', $data['message']);
  }

  public function testForgotPasswordMissingFields(): void
  {
    $response = $this->post('/api/auth/forgot-password', [
      'email' => '',
      'pseudo' => ''
    ]);

    $data = json_decode($response->getContent(), true);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Email et pseudo sont obligatoires.', $data['message']);
  }


  // Pour tester le changement du mot de passe via le mot de passe temporaire
  public function testVerifyTempPasswordMissingFields(): void
  {
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => '',
          'temporaryPassword' => ''
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(400, $response->getStatusCode());
      $this->assertEquals('Email et mot de passe temporaire sont obligatoires.', $data['message']);
  }
  
  public function testVerifyTempPasswordEmailNotFound(): void
  {
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => 'unknown@mail.fr',
          'temporaryPassword' => 'Temp123!'
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(404, $response->getStatusCode());
      $this->assertEquals('Aucun compte trouvé pour cet email.', $data['message']);
  }
  
  public function testVerifyTempPasswordNoActiveTempPassword(): void
  {
      $this->createUser();
  
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => 'existing@mail.fr',
          'temporaryPassword' => 'Temp123!'
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(400, $response->getStatusCode());
      $this->assertEquals('Aucun mot de passe temporaire actif pour ce compte.', $data['message']);
  }
  
  public function testVerifyTempPasswordExpired(): void
  {
      $this->createUser();
  
      $em = static::getContainer()->get('doctrine')->getManager();
      $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'existing@mail.fr']);
  
      $user->setTemporaryPassword(password_hash('Temp123!', PASSWORD_DEFAULT));
      $user->setTemporaryPasswordExpiresAt((new \DateTimeImmutable())->modify('-1 minute'));
      $em->flush();
  
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => 'existing@mail.fr',
          'temporaryPassword' => 'Temp123!'
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(400, $response->getStatusCode());
      $this->assertEquals('Mot de passe temporaire expiré.', $data['message']);
  }
  
  public function testVerifyTempPasswordIncorrect(): void
  {
      $this->createUser();
  
      $em = static::getContainer()->get('doctrine')->getManager();
      $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'existing@mail.fr']);
  
      $user->setTemporaryPassword(password_hash('CorrectTemp!', PASSWORD_DEFAULT));
      $user->setTemporaryPasswordExpiresAt((new \DateTimeImmutable())->modify('+10 minutes'));
      $em->flush();
  
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => 'existing@mail.fr',
          'temporaryPassword' => 'WrongTemp!'
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(401, $response->getStatusCode());
      $this->assertEquals('Mot de passe temporaire incorrect.', $data['message']);
  }
  
  public function testVerifyTempPasswordSuccess(): void
  {
      $this->createUser();
  
      $em = static::getContainer()->get('doctrine')->getManager();
      $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'existing@mail.fr']);
  
      $user->setTemporaryPassword(password_hash('CorrectTemp!', PASSWORD_DEFAULT));
      $user->setTemporaryPasswordExpiresAt((new \DateTimeImmutable())->modify('+10 minutes'));
      $em->flush();
  
      $response = $this->post('/api/auth/verify-temp-password', [
          'email' => 'existing@mail.fr',
          'temporaryPassword' => 'CorrectTemp!'
      ]);
  
      $data = json_decode($response->getContent(), true);
  
      $this->assertEquals(200, $response->getStatusCode());
      $this->assertTrue($data['valid']);
  }
}
