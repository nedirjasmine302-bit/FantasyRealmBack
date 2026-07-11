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
}
