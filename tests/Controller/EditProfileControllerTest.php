<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Profile settings across the sign-in migration (issue #147). This is the one
 * page real users meet that the 2c-II slice changed, so both sides of the
 * credential-card branch are pinned here:
 *
 * - a legacy Auth0 session must still get the #161 "send password change email"
 *   button, byte-for-byte the same offer as before the migration started;
 * - a native UserAccount session must get the native cards instead.
 *
 * The branch is on the class in the session, not on a feature flag, so this
 * behaviour is live from the day the slice merges - which is exactly why it is
 * tested rather than reasoned about.
 */
final class EditProfileControllerTest extends WebTestCase
{
    public function testLegacyAuth0SessionStillGetsTheAuth0PasswordCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();

        // The #161 flow, untouched: a POST form to the Auth0 reset-email endpoint
        self::assertCount(1, $crawler->filter('form[action="/en/change-password"]'));
        self::assertStringContainsString(
            'Send password change email',
            $crawler->filter('form[action="/en/change-password"]')->text(),
        );

        // ... and none of the native cards, which have nothing to act on here
        self::assertCount(0, $crawler->filter('a[href$="/edit-profile/change-password"]'));
        self::assertCount(0, $crawler->filter('a[href$="/edit-profile/change-email"]'));
    }

    /**
     * Pins the known 2c-II → 2d dependency rather than the end state.
     *
     * `RetrieveLoggedUserProfile::getProfile()` still tests only for the Auth0 user
     * class, so a native session gets a null profile and this controller bounces it
     * on its `$player === null` guard - which means the native credential cards
     * built in 2c-II cannot be reached yet. Harmless today (both flags are OFF, so
     * no native account exists in production), and the first task of slice 2d.
     *
     * **When 2d teaches RetrieveLoggedUserProfile about UserAccount, this test must
     * flip**: assert the page renders with the two native cards and the resend
     * button, and without the Auth0 form.
     */
    public function testNativeAccountIsStillBouncedUntilSlice2dLands(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $browser->request('GET', '/en/edit-profile');

        self::assertResponseRedirects('/en/my-profile');
    }

    private function seedNativeAccount(KernelBrowser $browser): UserAccount
    {
        $email = sprintf('editprofile+%s@example.com', bin2hex(random_bytes(4)));
        $userId = 'msp|' . bin2hex(random_bytes(4));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('a-properly-long-passphrase', PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist(
            new Player(Uuid::uuid7(), 'EDPR' . bin2hex(random_bytes(2)), $userId, $email, null, new DateTimeImmutable()),
        );
        $entityManager->flush();

        return $userAccount;
    }
}
