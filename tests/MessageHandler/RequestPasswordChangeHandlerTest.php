<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\PasswordChangeRequestFailed;
use SpeedPuzzling\Web\Message\RequestPasswordChange;
use SpeedPuzzling\Web\MessageHandler\RequestPasswordChangeHandler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RequestPasswordChangeHandlerTest extends TestCase
{
    public function testItCallsAuth0WithClientIdEmailAndConnection(): void
    {
        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse("We've just sent you an email to reset your password.");
        });

        $this->createHandler($client)(
            new RequestPasswordChange(
                userId: 'auth0|abc123',
                email: 'puzzler@example.com',
            ),
        );

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://tenant.eu.auth0.com/dbconnections/change_password', $requests[0]['url']);

        /** @var string $body */
        $body = $requests[0]['options']['body'];

        self::assertSame(
            [
                'client_id' => 'client-id',
                'email' => 'puzzler@example.com',
                'connection' => 'Username-Password-Authentication',
            ],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testItRefusesSocialLoginUsers(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Auth0 must not be called for a social login user');
        });

        $this->expectException(PasswordChangeRequestFailed::class);

        $this->createHandler($client)(
            new RequestPasswordChange(
                userId: 'google-oauth2|abc123',
                email: 'puzzler@example.com',
            ),
        );
    }

    public function testItFailsWhenAuth0RejectsTheRequest(): void
    {
        $client = new MockHttpClient(new MockResponse('Too Many Requests', ['http_code' => 429]));

        $this->expectException(PasswordChangeRequestFailed::class);

        $this->createHandler($client)(
            new RequestPasswordChange(
                userId: 'auth0|abc123',
                email: 'puzzler@example.com',
            ),
        );
    }

    private function createHandler(HttpClientInterface $client): RequestPasswordChangeHandler
    {
        return new RequestPasswordChangeHandler(
            client: $client,
            logger: new NullLogger(),
            auth0Domain: 'tenant.eu.auth0.com',
            auth0ClientId: 'client-id',
            auth0DatabaseConnection: 'Username-Password-Authentication',
        );
    }
}
