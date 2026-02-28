<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AuthTest extends WebTestCase
{
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        return static::createClient();
    }

    public function testRegisterSuccess(): void
    {
        $client = $this->client();

        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'test_' . uniqid() . '@example.com',
            'username' => 'testuser_' . uniqid(),
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('email', $data['user']);
    }

    public function testRegisterValidationFail(): void
    {
        $client = $this->client();

        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'not-an-email',
            'username' => 'u', // too short
            'password' => '123', // too short
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testLoginSuccess(): void
    {
        $client = $this->client();

        // First register
        $email    = 'login_test_' . uniqid() . '@example.com';
        $username = 'loginuser_' . uniqid();
        $password = 'password123';

        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(compact('email', 'username', 'password')));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Then login
        $client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(compact('email', 'password')));

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
    }

    public function testLoginWrongPassword(): void
    {
        $client = $this->client();

        $client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeRequiresAuth(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/auth/me');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeWithValidToken(): void
    {
        $client = $this->client();

        $email    = 'me_test_' . uniqid() . '@example.com';
        $username = 'meuser_' . uniqid();

        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'username' => $username, 'password' => 'password123']));

        $data  = json_decode($client->getResponse()->getContent(), true);
        $token = $data['token'];

        $client->request('GET', '/api/auth/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();

        $me = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($email, $me['user']['email']);
    }
}
