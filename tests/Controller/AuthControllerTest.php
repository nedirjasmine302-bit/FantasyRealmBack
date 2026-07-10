<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AuthControllerTest extends WebTestCase
{
  public function testSignUpSuccess(): void
  {
    $client = static::createClient();

    $client->request(
      'POST',
      '/api/sign-up',
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'email' => 'test-ci@mail.fr',
        'pseudo' => 'TestCI',
        'password' => 'Test123!',
        'password2' => 'Test123!'
      ])
    );

    $response = $client->getResponse();
    $this->assertEquals(201, $response->getStatusCode());

    $data = json_decode($response->getContent(), true);

    $this->assertTrue($data['success']);
    $this->assertEquals('test-ci@mail.fr', $data['user']['email']);
    $this->assertEquals('TestCI', $data['user']['pseudo']);
  }
}
