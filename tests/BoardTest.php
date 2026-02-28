<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class BoardTest extends WebTestCase
{
    private string $token = '';
    private int $userId   = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $client = static::createClient();
        $email  = 'board_test_' . uniqid() . '@example.com';

        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => $email,
            'username' => 'btest_' . uniqid(),
            'password' => 'password123',
        ]));

        $data        = json_decode($client->getResponse()->getContent(), true);
        $this->token  = $data['token'];
        $this->userId = $data['user']['id'];
    }

    private function authHeaders(): array
    {
        return [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ];
    }

    public function testCreateBoard(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title'       => 'My Test Board',
            'description' => 'A test board',
            'color'       => '#0079BF',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('My Test Board', $data['title']);
        $this->assertEquals('#0079BF', $data['color']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testListBoards(): void
    {
        $client = static::createClient();

        // Create a board first
        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title' => 'List Test Board',
        ]));
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // List boards
        $client->request('GET', '/api/boards', [], [], $this->authHeaders());
        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));
    }

    public function testGetBoard(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title' => 'Detail Board',
        ]));

        $board = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/boards/' . $board['id'], [], [], $this->authHeaders());
        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($board['id'], $data['id']);
        $this->assertArrayHasKey('lists', $data);
        $this->assertArrayHasKey('members', $data);
    }

    public function testUpdateBoard(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title' => 'Old Title',
        ]));

        $board = json_decode($client->getResponse()->getContent(), true);

        $client->request('PATCH', '/api/boards/' . $board['id'], [], [], $this->authHeaders(), json_encode([
            'title' => 'New Title',
            'color' => '#61BD4F',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('New Title', $data['title']);
    }

    public function testDeleteBoard(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title' => 'To Delete',
        ]));

        $board = json_decode($client->getResponse()->getContent(), true);

        $client->request('DELETE', '/api/boards/' . $board['id'], [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Verify it's gone
        $client->request('GET', '/api/boards/' . $board['id'], [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUnauthorizedBoardAccess(): void
    {
        $client = static::createClient();

        // Create a board with user 1
        $client->request('POST', '/api/boards', [], [], $this->authHeaders(), json_encode([
            'title' => 'Private Board',
        ]));
        $board = json_decode($client->getResponse()->getContent(), true);

        // Create a second user
        $client->request('POST', '/api/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'other_' . uniqid() . '@example.com',
            'username' => 'other_' . uniqid(),
            'password' => 'password123',
        ]));
        $otherData  = json_decode($client->getResponse()->getContent(), true);
        $otherToken = $otherData['token'];

        // Try to access with user 2
        $client->request('GET', '/api/boards/' . $board['id'], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
