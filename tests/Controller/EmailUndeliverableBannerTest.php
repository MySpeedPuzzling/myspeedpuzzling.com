<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A hard-bounced address (docs/features/mass-mailing/README.md): the player sees why and how to fix it, on every page
 */
final class EmailUndeliverableBannerTest extends WebTestCase
{
    public function testAPlayerWhoseAddressBouncesSeesTheBannerWithTheReason(): void
    {
        $browser = self::createClient();
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new EmailSuppression(
            Uuid::uuid7(),
            PlayerFixture::PLAYER_REGULAR_EMAIL,
            new DateTimeImmutable('2026-10-12'),
            'NoEmail',
            'smtp; 550 5.1.1 user unknown',
            'unread_digest',
        ));
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-email-undeliverable]');
        self::assertCount(1, $banner);
        self::assertStringContainsString("We can't deliver e-mails to " . PlayerFixture::PLAYER_REGULAR_EMAIL, $banner->text());
        self::assertStringContainsString('says this address does not exist', $banner->text());
        self::assertStringContainsString('550 5.1.1 user unknown', $banner->text());
        self::assertCount(1, $banner->filter('a[href="/en/edit-profile/change-email"]'));
    }

    public function testAnAddressBlockedByHandShowsTheTeamsReasonInASlimStripe(): void
    {
        $browser = self::createClient();
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(EmailSuppression::blockedByHand(
            Uuid::uuid7(),
            PlayerFixture::PLAYER_REGULAR_EMAIL,
            new DateTimeImmutable('2026-10-29'),
            'The domain of your address does not exist.',
        ));
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-email-blocked]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('We have stopped sending any e-mails to ' . PlayerFixture::PLAYER_REGULAR_EMAIL, $banner->text());
        self::assertStringContainsString('The domain of your address does not exist.', $banner->text());
        self::assertStringContainsString('Please check your address', $banner->text());
        self::assertCount(1, $banner->filter('a[href="/en/edit-profile/change-email"]'));
    }

    public function testNobodyElseSeesIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-email-undeliverable]'));
    }
}
