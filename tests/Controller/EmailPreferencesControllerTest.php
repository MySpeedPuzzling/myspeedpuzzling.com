<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class EmailPreferencesControllerTest extends WebTestCase
{
    public function testPageLoadsWithValidToken(): void
    {
        $browser = self::createClient();
        $token = $this->preferencesToken();

        $browser->request('GET', '/en/email-preferences/' . $token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            PlayerFixture::PLAYER_REGULAR_EMAIL,
            (string) $browser->getResponse()->getContent(),
        );

        // Anonymous page reached from e-mail links: no session cookie (#164),
        // and personal data behind a capability URL must never be shared-cached
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testGarbageTokenShowsInvalidTokenPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/email-preferences/not-a-token');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsubscribeTokenIsRejected(): void
    {
        $browser = self::createClient();
        $token = $this->tokenSigner()->generateUnsubscribeToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );

        $browser->request('GET', '/en/email-preferences/' . $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGuestAudienceTokenIsRejected(): void
    {
        $browser = self::createClient();
        $token = $this->tokenSigner()->generatePreferencesToken(
            NewsletterAudience::Guest,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );

        $browser->request('GET', '/en/email-preferences/' . $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenDiesWhenEmailNoLongerMatches(): void
    {
        $browser = self::createClient();

        // Signed for the address the player had when the e-mail was sent
        $token = $this->tokenSigner()->generatePreferencesToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            'previous-address@example.com',
        );

        $browser->request('GET', '/en/email-preferences/' . $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSavingUpdatesPreferences(): void
    {
        $browser = self::createClient();
        $token = $this->preferencesToken();

        $crawler = $browser->request('GET', '/en/email-preferences/' . $token);

        $form = $crawler->selectButton('Save my preferences')->form();
        $newsletterField = $form['newsletter_enabled'];
        assert($newsletterField instanceof ChoiceFormField);
        $newsletterField->untick();
        $frequencyField = $form['email_notification_frequency'];
        assert($frequencyField instanceof ChoiceFormField);
        $frequencyField->select('1_week');
        $browser->submit($form);

        self::assertResponseRedirects('/en/email-preferences/' . $token . '?saved=1');

        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->newsletterEnabled);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertSame(EmailNotificationFrequency::OneWeek, $player->emailNotificationFrequency);

        // Following the redirect shows the success confirmation
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('alert-success', (string) $browser->getResponse()->getContent());
    }

    public function testSavingWithInvalidTokenIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/email-preferences/not-a-token/save', [
            '_token' => 'csrf-token',
            'newsletter_enabled' => '1',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSavingWithoutValidCsrfChangesNothing(): void
    {
        $browser = self::createClient();
        $token = $this->preferencesToken();

        // No Origin/Referer header and no double-submit cookie: stateless CSRF fails
        $browser->request('POST', '/en/email-preferences/' . $token . '/save', [
            '_token' => 'forged',
        ]);

        self::assertResponseRedirects('/en/email-preferences/' . $token);

        $player = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->newsletterEnabled);
    }

    private function preferencesToken(): string
    {
        return $this->tokenSigner()->generatePreferencesToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );
    }

    private function tokenSigner(): NewsletterTokenSigner
    {
        return self::getContainer()->get(NewsletterTokenSigner::class);
    }
}
