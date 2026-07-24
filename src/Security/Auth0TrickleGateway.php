<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Validates credentials against Auth0's Resource Owner Password Grant
 * (password-realm variant) for imported accounts whose hash did not make it
 * into the local database. Stateless - safe under FrankenPHP worker mode.
 * Removed entirely in Phase 6 together with the Auth0 tenant.
 */
final readonly class Auth0TrickleGateway implements TricklePasswordVerifier
{
    private const string PASSWORD_REALM_GRANT = 'http://auth0.com/oauth/grant-type/password-realm';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $auth0Domain,
        private string $auth0ClientId,
        private string $auth0ClientSecret,
        private string $auth0DatabaseConnection,
    ) {
    }

    public function verify(string $email, string $plainPassword, null|string $clientIp): TrickleVerificationResult
    {
        try {
            $headers = [];

            // Attribute brute-force protection to the real client, not our server IP
            // (requires "Trust Token Endpoint IP Header" on the Auth0 application)
            if ($clientIp !== null && $clientIp !== '') {
                $headers['auth0-forwarded-for'] = $clientIp;
            }

            $response = $this->httpClient->request('POST', sprintf('https://%s/oauth/token', $this->auth0Domain), [
                'headers' => $headers,
                'body' => [
                    'grant_type' => self::PASSWORD_REALM_GRANT,
                    'realm' => $this->auth0DatabaseConnection,
                    'username' => $email,
                    'password' => $plainPassword,
                    'client_id' => $this->auth0ClientId,
                    'client_secret' => $this->auth0ClientSecret,
                    'scope' => 'openid',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return TrickleVerificationResult::Verified;
            }

            $payload = $response->toArray(throw: false);
            $error = $payload['error'] ?? null;
            $error = is_string($error) ? $error : null;

            if ($error === 'password_leaked') {
                return TrickleVerificationResult::PasswordLeaked;
            }

            if ($error === 'too_many_attempts') {
                $this->logger->warning('Auth0 trickle login hit brute-force protection.', [
                    'status_code' => $statusCode,
                ]);

                return TrickleVerificationResult::Unavailable;
            }

            if (in_array($error, ['invalid_grant', 'access_denied', 'invalid_user_password', 'unauthorized'], true)) {
                return TrickleVerificationResult::InvalidCredentials;
            }

            $this->logger->error('Auth0 trickle login verification returned an unexpected response.', [
                'status_code' => $statusCode,
                'error' => $error,
            ]);

            return TrickleVerificationResult::Unavailable;
        } catch (Throwable $e) {
            $this->logger->error('Auth0 trickle login verification failed.', [
                'exception' => $e,
            ]);

            return TrickleVerificationResult::Unavailable;
        }
    }
}
