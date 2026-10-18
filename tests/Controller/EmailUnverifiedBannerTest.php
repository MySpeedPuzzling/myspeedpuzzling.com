<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The verification link was never clicked: a few days after registering the player gets a reminder with a new link,
 * on every page, until the address is verified
 */
final class EmailUnverifiedBannerTest extends WebTestCase
{
    public function testAnAddressStillUnverifiedAfterTwoDaysGetsTheBanner(): void
    {
        $browser = self::createClient();
        $this->unverified($browser, registeredDaysAgo: PlayerProfile::EMAIL_VERIFICATION_REMINDER_AFTER_DAYS + 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-email-unverified]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('Please confirm your e-mail address ' . PlayerFixture::PLAYER_REGULAR_EMAIL, $banner->text());
        self::assertCount(1, $banner->filter('form[action="/resend-email-verification"] input[name="return"][value="/en/hub"]'));
        self::assertCount(1, $banner->filter('a[href="/en/edit-profile/change-email"]'));
    }

    public function testAFreshSignUpGetsNoBannerYet(): void
    {
        $browser = self::createClient();
        $this->unverified($browser, registeredDaysAgo: 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-email-unverified]'));
    }

    public function testAVerifiedAddressGetsNoBanner(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-email-unverified]'));
    }

    public function testABouncingAddressGetsTheUndeliverableBannerInstead(): void
    {
        $browser = self::createClient();
        $this->unverified($browser, registeredDaysAgo: 10);
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new EmailSuppression(
            Uuid::uuid7(),
            PlayerFixture::PLAYER_REGULAR_EMAIL,
            new DateTimeImmutable('2026-10-12'),
            'NoEmail',
            'smtp; 550 5.1.1 user unknown',
            'verify_email',
        ));
        $entityManager->flush();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertCount(0, $crawler->filter('[data-email-unverified]'));
        self::assertCount(1, $crawler->filter('[data-email-undeliverable]'));
    }

    public function testSendingTheLinkAgainFromTheBannerReturnsToThePage(): void
    {
        $browser = self::createClient();
        $this->unverified($browser, registeredDaysAgo: 5);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');

        $browser->submit($crawler->filter('[data-email-unverified] form')->form());

        self::assertResponseRedirects('/en/hub');
        self::assertQueuedEmailCount(1);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Confirmation email sent', $crawler->filter('.alert-success')->text());
    }

    public function testAForeignReturnAddressFallsBackToEditProfile(): void
    {
        $browser = self::createClient();
        $this->unverified($browser, registeredDaysAgo: 5);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');
        $form = $crawler->filter('[data-email-unverified] form')->form();
        $form['return'] = '//evil.example.com/';

        $browser->submit($form);

        self::assertResponseRedirects('/en/edit-profile');
    }

    private function unverified(KernelBrowser $browser, int $registeredDaysAgo): void
    {
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE user_account SET email_verified_at = NULL WHERE user_id = (SELECT user_id FROM player WHERE id = :id)',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        $database->executeStatement(
            'UPDATE player SET registered_at = :registeredAt WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR, 'registeredAt' => new DateTimeImmutable("-{$registeredDaysAgo} days")->format('Y-m-d H:i:s')],
        );
    }
}
