<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Confirming an email address (issue #147). The page is deliberately open to
 * anonymous visitors - the token proves control of the mailbox on its own, and
 * the link gets opened wherever the mail is read - so the thing to hold onto is
 * that it confirms the address and grants nothing else.
 */
final class VerifyEmailControllerTest extends WebTestCase
{
    public function testAValidLinkConfirmsTheAddressWithoutSigningAnyoneIn(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedAccount($browser);

        $browser->request('GET', '/verify-email?token=' . $this->tokenFor($browser, $userAccount));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('confirmed', (string) $browser->getResponse()->getContent());

        $reloaded = $browser->getContainer()->get(UserAccountRepository::class)->findByUserId($userAccount->userId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->emailVerifiedAt);

        // Proving you can read the mailbox confirms the address; it is not a login
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    /**
     * D18 accepts that verification links are replayable inside their lifetime:
     * the handler is idempotent, so a mail client prefetching the link - or a
     * second click - must read as success, not as an error.
     */
    public function testReplayingALiveLinkStillReadsAsSuccess(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedAccount($browser);
        $token = $this->tokenFor($browser, $userAccount);

        $browser->request('GET', '/verify-email?token=' . $token);
        $firstVerifiedAt = $this->reload($browser, $userAccount)?->emailVerifiedAt;
        self::assertNotNull($firstVerifiedAt);

        $browser->request('GET', '/verify-email?token=' . $token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('confirmed', (string) $browser->getResponse()->getContent());

        // ... and the original timestamp is not moved by the replay. Compared to the
        // second: the first read is the in-memory entity, the second comes back from a
        // column that does not carry microseconds
        $secondVerifiedAt = $this->reload($browser, $userAccount)?->emailVerifiedAt;
        self::assertNotNull($secondVerifiedAt);
        self::assertSame(
            $firstVerifiedAt->format('Y-m-d H:i:s'),
            $secondVerifiedAt->format('Y-m-d H:i:s'),
        );
    }

    public function testAForgedTokenIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/verify-email?token=' . base64_encode('{"userId":"msp|forged"}') . '.forged');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not work', (string) $browser->getResponse()->getContent());
    }

    public function testAMissingTokenIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/verify-email');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not work', (string) $browser->getResponse()->getContent());
    }

    /**
     * The token binds the address it was issued for, so a link stops working the
     * moment the account moves to a different address - the old inbox must not be
     * able to confirm the new one.
     */
    public function testALinkDiesWhenTheAddressItWasIssuedForChanges(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedAccount($browser);
        $token = $this->tokenFor($browser, $userAccount);

        $userAccount->changeEmail(sprintf('moved+%s@example.com', bin2hex(random_bytes(4))));
        $browser->getContainer()->get(EntityManagerInterface::class)->flush();

        $browser->request('GET', '/verify-email?token=' . $token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not work', (string) $browser->getResponse()->getContent());
        self::assertNull($this->reload($browser, $userAccount)?->emailVerifiedAt);
    }

    public function testAnExpiredLinkSaysSoRatherThanLookingBroken(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedAccount($browser);

        $signer = $browser->getContainer()->get(EmailVerificationTokenSigner::class);
        $expired = $signer->generate($userAccount, new DateTimeImmutable('-1 minute'));

        $browser->request('GET', '/verify-email?token=' . $expired);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('expired', (string) $browser->getResponse()->getContent());
    }

    public function testThePageStartsNoSessionAndStaysOutOfSharedCaches(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedAccount($browser);

        $browser->request('GET', '/verify-email?token=' . $this->tokenFor($browser, $userAccount));

        // #164: even a successful confirmation must not put a cookie on the visitor
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    private function tokenFor(KernelBrowser $browser, UserAccount $userAccount): string
    {
        return $browser->getContainer()->get(EmailVerificationTokenSigner::class)
            ->generate($userAccount, new DateTimeImmutable('+24 hours'));
    }

    private function reload(KernelBrowser $browser, UserAccount $userAccount): null|UserAccount
    {
        return $browser->getContainer()->get(UserAccountRepository::class)->findByUserId($userAccount->userId);
    }

    private function seedAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('verify.page+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(
            Uuid::uuid7(),
            'msp|' . bin2hex(random_bytes(4)),
            $email,
            new DateTimeImmutable(),
        );

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $userAccount;
    }
}
