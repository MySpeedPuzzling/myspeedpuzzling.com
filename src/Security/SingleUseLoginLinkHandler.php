<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LoginLinkRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\LoginLinkRequestRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Makes magic sign-in links single-use (D18, issue #147).
 *
 * Symfony's login_link is signature + expiry only, so a link stays replayable
 * for its whole lifetime — anyone who later reads the mailbox, a forwarded
 * message or a proxy log can sign in again. This decorator books every issued
 * link into `login_link_request` (sha256 of the signature only) and consumes
 * the row on the first successful login. Unknown and already-consumed links are
 * rejected identically to a forged one, so a replay leaks nothing.
 *
 * Symfony's own `max_uses` option is not used: it needs a PSR-6 pool
 * (ExpiredSignatureStorage is final), which would put auth state in a cache
 * instead of the database and cannot express "issued by us" at all.
 */
final readonly class SingleUseLoginLinkHandler implements LoginLinkHandlerInterface
{
    public function __construct(
        private LoginLinkHandlerInterface $inner,
        private LoginLinkRequestRepository $loginLinkRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    public function createLoginLink(UserInterface $user, null|Request $request = null, null|int $lifetime = null): LoginLinkDetails
    {
        if (!$user instanceof UserAccount) {
            throw new LogicException('Sign-in links can only be issued for native user accounts.');
        }

        $loginLinkDetails = $this->inner->createLoginLink($user, $request, $lifetime);

        $now = $this->clock->now();

        // Opportunistic garbage collection, same grace period as the password reset
        // requests: consumed and expired rows are only useful while their link could
        // still be clicked (so the failure can say "expired" rather than "unknown").
        $this->loginLinkRequestRepository->removeExpiredBefore($now->modify('-1 week'));

        $this->loginLinkRequestRepository->save(
            new LoginLinkRequest(
                Uuid::uuid7(),
                $user,
                LoginLinkRequest::hashToken($this->extractSignatureHash($loginLinkDetails->getUrl())),
                $now,
                $loginLinkDetails->getExpiresAt(),
            ),
        );

        return $loginLinkDetails;
    }

    public function consumeLoginLink(Request $request): UserInterface
    {
        // Signature, expiry and user resolution first — an unsigned link never
        // reaches the database
        $user = $this->inner->consumeLoginLink($request);

        $hash = $request->query->get('hash') ?? $request->request->get('hash');

        if (!is_string($hash) || $hash === '') {
            throw new InvalidLoginLinkException('Missing "hash" parameter.');
        }

        $loginLinkRequest = $this->loginLinkRequestRepository->findByHashedToken(
            LoginLinkRequest::hashToken($hash),
        );

        if ($loginLinkRequest === null) {
            // Correctly signed but never issued by us (or garbage collected long ago)
            throw new InvalidLoginLinkException('Login link was not issued or is no longer known.');
        }

        if (!$this->loginLinkRequestRepository->consumeIfOpen($loginLinkRequest, $this->clock->now())) {
            throw new InvalidLoginLinkException('Login link has already been used.');
        }

        return $user;
    }

    private function extractSignatureHash(string $loginLinkUrl): string
    {
        $query = parse_url($loginLinkUrl, PHP_URL_QUERY);

        parse_str(is_string($query) ? $query : '', $parameters);

        $hash = $parameters['hash'] ?? null;

        if (!is_string($hash) || $hash === '') {
            throw new LogicException('Generated login link carries no signature hash.');
        }

        return $hash;
    }
}
