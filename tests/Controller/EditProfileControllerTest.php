<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Profile settings: the credential cards (issue #147). The #161 Auth0
 * "send password change email" button is gone with the Auth0 stack (Phase 6).
 */
final class EditProfileControllerTest extends WebTestCase
{
    public function testAccountGetsTheCredentialCards(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-password"]'));
        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-email"]'));
        self::assertCount(1, $crawler->filter('a[href$="/account/recent-activity"]'));

        // The danger zone names the address the deletion link goes to
        self::assertStringContainsString($userAccount->email, $crawler->filter('#danger-zone')->text());
    }

    /**
     * Fixture players carry auth0|... ids - accounts imported from Auth0. Their
     * settings page must render just the same.
     */
    public function testImportedAuth0AccountGetsTheSameCards(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/edit-profile/change-email"]'));
        self::assertCount(1, $crawler->filter('a[href$="/account/recent-activity"]'));
    }

    /**
     * user_account.email is the single source of truth and the verified change-e-mail flow
     * (current password + link to the new inbox) is the only way to change it: the profile
     * form has no e-mail field any more, and smuggling one in changes nothing.
     */
    public function testProfileFormHasNoEmailFieldAndIgnoresASmuggledOne(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $originalEmail = $userAccount->email;
        $browser->loginUser($userAccount, 'main');

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="edit_profile_form[email]"]'));

        $form = $crawler->filter('form[name="edit_profile_form"]')->form();
        $formValues = $form->getPhpValues()['edit_profile_form'] ?? null;
        self::assertIsArray($formValues);
        $formValues['name'] = 'Renamed Puzzler';
        $formValues['email'] = 'hijacked@example.com';

        $browser->request('POST', '/en/edit-profile', ['edit_profile_form' => $formValues]);

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reloadedAccount = $entityManager->getRepository(UserAccount::class)->findOneBy(['userId' => $userAccount->userId]);
        self::assertNotNull($reloadedAccount);
        self::assertSame($originalEmail, $reloadedAccount->email);
    }

    public function testAnSvgAvatarIsRefused(): void
    {
        $browser = self::createClient();
        $userAccount = $this->seedNativeAccount($browser);
        $browser->loginUser($userAccount, 'main');

        $crawler = $browser->request('GET', '/en/edit-profile');
        $form = $crawler->filter('form[name="edit_profile_form"]')->form();
        $formValues = $form->getPhpValues()['edit_profile_form'] ?? null;
        self::assertIsArray($formValues);

        $svg = tempnam(sys_get_temp_dir(), 'avatar');
        self::assertIsString($svg);
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

        $browser->request(
            'POST',
            '/en/edit-profile',
            ['edit_profile_form' => $formValues],
            ['edit_profile_form' => ['avatar' => new UploadedFile($svg, 'avatar.svg', 'image/svg+xml', null, true)]],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="edit_profile_form"]', 'Supported are: jpg, jpeg, gif, png, webp, heic, heif, avif');

        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($userAccount->userId);
        self::assertNotNull($player);
        self::assertNull($player->avatar);
    }

    public function testResultEmailsSwitchIsSavedWithTheMessagingSettings(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        $form = $crawler->filter('form[name="messaging_settings_form"]')->form();
        $resultEmailsField = $form['messaging_settings_form[resultEmailsEnabled]'];
        assert($resultEmailsField instanceof ChoiceFormField);
        self::assertSame('1', $resultEmailsField->getValue(), 'On by default');
        $resultEmailsField->untick();
        $browser->submit($form);

        self::assertResponseRedirects();

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->resultEmailsEnabled);
        // It is neither the newsletter nor the chat digest
        self::assertTrue($player->newsletterEnabled);
        self::assertTrue($player->emailNotificationsEnabled);
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
            new Player(Uuid::uuid7(), 'EDPR' . bin2hex(random_bytes(2)), $userId, null, new DateTimeImmutable()),
        );
        $entityManager->flush();

        return $userAccount;
    }
}
