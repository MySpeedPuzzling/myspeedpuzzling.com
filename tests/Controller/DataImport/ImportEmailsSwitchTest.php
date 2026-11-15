<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * `player.import_emails` (docs/features/data-import/adding.md "Telling the player"): on by default, switched in the
 * profile settings where the other e-mail switches live, and by the e-mail's signed one-click unsubscribe.
 */
final class ImportEmailsSwitchTest extends WebTestCase
{
    public function testTheSwitchIsSavedWithTheMessagingSettings(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        $form = $crawler->filter('form[name="messaging_settings_form"]')->form();
        $field = $form['messaging_settings_form[importEmails]'];
        assert($field instanceof ChoiceFormField);
        self::assertSame('1', $field->getValue(), 'On by default');
        $field->untick();
        $browser->submit($form);

        self::assertResponseRedirects();

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->importEmails);
        // Nothing else moved
        self::assertTrue($player->listingRequestEmails);
        self::assertTrue($player->resultEmailsEnabled);
    }

    public function testTheSignedLinkUnsubscribesOnlyOnPost(): void
    {
        $browser = self::createClient();
        $url = $browser->getContainer()->get(ImportEmailsUnsubscribeUrl::class)->forPlayer(PlayerFixture::PLAYER_REGULAR, 'en');

        // A mail scanner opening the link changes nothing
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="import-emails-unsubscribe-form"]');
        self::assertTrue($browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->importEmails);

        // The mail client's one-click POST (RFC 8058): a plain 200, no redirect
        $browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        self::assertFalse($browser->getContainer()->get(Connection::class)->fetchOne('SELECT import_emails FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));

        $browser->request('GET', $url);
        self::assertSelectorExists('[data-testid="import-emails-unsubscribed"]');

        // Without the signature nobody switches anybody's e-mails off
        $browser->request('POST', '/en/import-emails/unsubscribe/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseStatusCodeSame(404);
    }
}
