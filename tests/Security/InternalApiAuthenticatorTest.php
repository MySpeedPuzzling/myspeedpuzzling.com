<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class InternalApiAuthenticatorTest extends TestCase
{
    public function testSupportsReturnsTrueForBearerHeaderWhenTokenConfigured(): void
    {
        $authenticator = new InternalApiAuthenticator('secret-token');
        $request = $this->createRequestWithAuthHeader('Bearer secret-token');

        self::assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseForOtherSchemes(): void
    {
        $authenticator = new InternalApiAuthenticator('secret-token');

        self::assertFalse($authenticator->supports($this->createRequestWithAuthHeader('Token msp_pat_xxx')));
        self::assertFalse($authenticator->supports($this->createRequestWithAuthHeader('Basic dXNlcjpwYXNz')));
        self::assertFalse($authenticator->supports($this->createRequestWithAuthHeader('')));
    }

    public function testSupportsReturnsFalseWhenTokenEnvIsEmpty(): void
    {
        $authenticator = new InternalApiAuthenticator('');
        $request = $this->createRequestWithAuthHeader('Bearer anything');

        // Closed-by-default: empty env must never authenticate, even with a Bearer header present.
        self::assertFalse($authenticator->supports($request));
    }

    public function testAuthenticateReturnsPassportWithInternalApiRoleOnTokenMatch(): void
    {
        $authenticator = new InternalApiAuthenticator('secret-token');
        $request = $this->createRequestWithAuthHeader('Bearer secret-token');

        $passport = $authenticator->authenticate($request);

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);

        $user = $passport->getUser();
        self::assertInstanceOf(InMemoryUser::class, $user);
        self::assertSame(InternalApiAuthenticator::USER_IDENTIFIER, $user->getUserIdentifier());
        self::assertSame([InternalApiAuthenticator::ROLE], $user->getRoles());
    }

    public function testAuthenticateThrowsOnTokenMismatch(): void
    {
        $authenticator = new InternalApiAuthenticator('secret-token');
        $request = $this->createRequestWithAuthHeader('Bearer wrong-token');

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($request);
    }

    public function testOnAuthenticationFailureReturns401Json(): void
    {
        $authenticator = new InternalApiAuthenticator('secret-token');
        $exception = new CustomUserMessageAuthenticationException('Invalid internal API token.');

        $response = $authenticator->onAuthenticationFailure(Request::create('/internal-api/whatever'), $exception);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":"Invalid internal API token."}',
            (string) $response->getContent(),
        );
    }

    private function createRequestWithAuthHeader(string $authorization): Request
    {
        $request = Request::create('/internal-api/whatever');
        $request->headers->set('Authorization', $authorization);

        return $request;
    }
}
