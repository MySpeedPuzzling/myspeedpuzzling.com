<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The two native credential-settings pages (issue #147), at the HTTP layer:
 * who may open them at all, and that the current-password gate is really in
 * front of them rather than only inside the handler.
 *
 * The window-A guard matters as much as the auth one: through the migration
 * window a session may hold an Auth0 user instead of a UserAccount, and these
 * pages have nothing to offer it - they must redirect, not blow up on the type.
 */
final class ChangeAccountCredentialsControllerTest extends WebTestCase
{
    private const string CURRENT_PASSWORD = 'the-current-passphrase';

    public function testChangePasswordPageIsClosedToAnonymousVisitors(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/edit-profile/change-password');

        self::assertResponseStatusCodeSame(302);
        self::assertSame(
            [],
            array_filter(
                $browser->getResponse()->headers->getCookies(),
                static fn ($cookie): bool => $cookie->getName() === 'PHPSESSID',
            ),
            'An anonymous bounce must not hand out a session cookie of its own',
        );
    }

    public function testChangeEmailPageIsClosedToAnonymousVisitors(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/edit-profile/change-email');

        self::assertResponseStatusCodeSame(302);
    }

    public function testCorrectCurrentPasswordChangesThePassword(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedSignedInAccount($browser);

        $crawler = $browser->request('GET', '/en/edit-profile/change-password');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Change password')->form();
        $browser->submit($form, [
            $form->getName() . '[currentPassword]' => self::CURRENT_PASSWORD,
            $form->getName() . '[newPassword]' => 'a-brand-new-passphrase',
        ]);

        self::assertResponseRedirects('/en/edit-profile');

        $hasher = $browser->getContainer()->get(UserPasswordHasherInterface::class);
        $reloaded = $browser->getContainer()->get(UserAccountRepository::class)
            ->findByUserId($userAccount->userId);

        self::assertNotNull($reloaded);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'a-brand-new-passphrase'));
        self::assertFalse($hasher->isPasswordValid($reloaded, self::CURRENT_PASSWORD));
    }

    public function testWrongCurrentPasswordIsRefusedAtTheForm(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedSignedInAccount($browser);

        $crawler = $browser->request('GET', '/en/edit-profile/change-password');
        $form = $crawler->selectButton('Change password')->form();

        $crawler = $browser->submit($form, [
            $form->getName() . '[currentPassword]' => 'not-the-current-passphrase',
            $form->getName() . '[newPassword]' => 'a-brand-new-passphrase',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not your current password', $crawler->filter('form')->text());

        $hasher = $browser->getContainer()->get(UserPasswordHasherInterface::class);
        $reloaded = $browser->getContainer()->get(UserAccountRepository::class)
            ->findByUserId($userAccount->userId);

        self::assertNotNull($reloaded);
        self::assertTrue($hasher->isPasswordValid($reloaded, self::CURRENT_PASSWORD));
    }

    public function testChangingTheEmailNeedsTheCurrentPasswordAndReVerifies(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedSignedInAccount($browser);
        $newEmail = sprintf('changed+%s@example.com', bin2hex(random_bytes(4)));

        $crawler = $browser->request('GET', '/en/edit-profile/change-email');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Change email address')->form();
        $browser->submit($form, [
            $form->getName() . '[newEmail]' => $newEmail,
            $form->getName() . '[currentPassword]' => self::CURRENT_PASSWORD,
        ]);

        self::assertResponseRedirects('/en/edit-profile');

        $reloaded = $browser->getContainer()->get(UserAccountRepository::class)
            ->findByUserId($userAccount->userId);

        self::assertNotNull($reloaded);
        self::assertSame($newEmail, $reloaded->email);
        // The new address is unproven until its own link is clicked
        self::assertNull($reloaded->emailVerifiedAt);

        // ... and that link goes to the NEW inbox
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame($newEmail, $messages[0]->getTo()[0]->getAddress());
    }

    public function testWrongCurrentPasswordDoesNotChangeTheEmail(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedSignedInAccount($browser);
        $originalEmail = $userAccount->email;

        $crawler = $browser->request('GET', '/en/edit-profile/change-email');
        $form = $crawler->selectButton('Change email address')->form();

        $crawler = $browser->submit($form, [
            $form->getName() . '[newEmail]' => sprintf('nope+%s@example.com', bin2hex(random_bytes(4))),
            $form->getName() . '[currentPassword]' => 'not-the-current-passphrase',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not your current password', $crawler->filter('form')->text());

        $reloaded = $browser->getContainer()->get(UserAccountRepository::class)
            ->findByUserId($userAccount->userId);

        self::assertNotNull($reloaded);
        self::assertSame($originalEmail, $reloaded->email);
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * Window A: the session may hold an Auth0 bundle user, which has no
     * user_account row behind it. These pages must show it the door rather than
     * fail on the #[CurrentUser] type.
     */
    public function testLegacyAuth0SessionIsRedirectedInsteadOfCrashing(): void
    {
        $browser = self::createClient();
        $browser->loginUser(new \Auth0\Symfony\Models\User(['sub' => 'auth0|legacy-credentials']), 'main');

        $browser->request('GET', '/en/edit-profile/change-password');
        self::assertResponseRedirects('/en/edit-profile');

        $browser->request('GET', '/en/edit-profile/change-email');
        self::assertResponseRedirects('/en/edit-profile');
    }

    private function seedSignedInAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('credentials+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash(self::CURRENT_PASSWORD, PASSWORD_ARGON2ID));
        $userAccount->markEmailVerified(new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist(
            new Player(Uuid::uuid7(), 'CRED' . bin2hex(random_bytes(2)), $userId, $email, null, new DateTimeImmutable()),
        );
        $entityManager->flush();

        $browser->loginUser($userAccount, 'main');

        return $userAccount;
    }
}
