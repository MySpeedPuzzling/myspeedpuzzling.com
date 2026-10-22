<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The partner finder page, the listing form and closing a listing (docs/features/partner-finder.md, "Part A as built").
 */
final class PartnerFinderPagesTest extends WebTestCase
{
    private const string FINDER = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG . '/partners';
    private const string FORM = '/en/partners/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN . '/list-me';

    public function testAGuestSeesTheTeaserWithoutNamesOrNotes(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::FINDER);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pf-teaser');
        self::assertSelectorTextContains('.pf-teaser-title', '2 puzzlers are looking for a partner here');
        self::assertSelectorExists('a[href^="/login"][href*="return="]');
        self::assertSame('noindex', $browser->getResponse()->headers->get('X-Robots-Tag'));

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $content);
        self::assertStringNotContainsString('John Doe', $content);
        self::assertStringNotContainsString('Michael Johnson', $content);
        self::assertStringNotContainsString('Fast on edges', $content);
        self::assertStringNotContainsString('/player-profile/', $content);
    }

    public function testASignedInPlayerSeesEveryListingWithTheirOwnFirst(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::FINDER);

        self::assertResponseIsSuccessful();

        // Own listing: above the list, no contact button, Edit / Found my partner / Remove
        $own = $crawler->filter('.pf-own .pf-listing');
        self::assertCount(1, $own);
        self::assertStringContainsString('1 got in touch', $own->text());
        self::assertCount(0, $own->filter('form[action$="/request"]'));
        self::assertCount(2, $own->filter('form[action$="/close"]'));

        // Everybody else: a filterable row with a contact form in its own frame
        $row = $crawler->filter('.pf-list .pf-listing');
        self::assertCount(1, $row);
        self::assertSame('group', $row->attr('data-kinds'));
        self::assertSame('de', $row->attr('data-country'));
        self::assertStringContainsString('Michael Johnson', $row->text());
        self::assertStringContainsString('Group of 2 · needs 2 more', $row->text());
        self::assertCount(1, $row->filter('turbo-frame#partner-listing-' . PartnerFinderFixture::LISTING_FAVORITES_TEAM . ' form[action="/en/partners/listing/' . PartnerFinderFixture::LISTING_FAVORITES_TEAM . '/request"]'));
        self::assertCount(1, $crawler->filter('.pf-seg-option[data-kind="group"]'));
    }

    public function testTheSenderSeesSent(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::FINDER);

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#partner-listing-' . PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertStringContainsString('Sent', $frame->text());
        self::assertCount(0, $frame->filter('form'));
    }

    public function testAnEventWithoutAFinderIs404AndAnEditionSlugRedirects(): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET date_from = CURRENT_DATE - 10, date_to = CURRENT_DATE - 10 WHERE id = :id',
            ['id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN],
        );

        $browser->request('GET', self::FINDER);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::FINDER);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/events/season-one/partners');
        self::assertResponseRedirects('/en/series/moonlight-sprint-league/season-one/partners', 301);

        // An online edition has no finder
        $browser->request('GET', '/en/series/moonlight-sprint-league/season-one/partners');
        self::assertResponseStatusCodeSame(404);
    }

    public function testListingYourselfThroughTheForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::FORM, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame form[action="' . self::FORM . '"]');
        self::assertStringContainsString("You'll be marked as going.", $crawler->text());

        $crawler = $browser->request('GET', self::FORM);
        self::assertResponseIsSuccessful();

        // Nothing looked for: refused, the form comes back with 422 and keeps its listing id
        $form = $crawler->selectButton('List me')->form();
        $listingId = self::field($form, 'partner_listing_form[listingId]')->getValue();
        self::assertIsString($listingId);
        $crawler = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Pick what you are looking for.');
        self::assertSame($listingId, self::field($crawler->selectButton('List me')->form(), 'partner_listing_form[listingId]')->getValue());

        $form = $crawler->selectButton('List me')->form();
        $pair = self::field($form, 'partner_listing_form[lookingFor][0]');
        self::assertInstanceOf(ChoiceFormField::class, $pair);
        $pair->tick();
        $form['partner_listing_form[customStrengths]'] = 'Wasgij, sorting';
        $form['partner_listing_form[note]'] = 'Happy to practise online first.';
        $browser->submit($form);

        self::assertResponseRedirects(self::FINDER, 303);

        /** @var array{player_id: string, looking_for: string, strengths: string, custom_strengths: string, languages: string, note: string} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT player_id, looking_for, strengths, custom_strengths, languages, note FROM partner_listing WHERE id = :id',
            ['id' => $listingId],
        );
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['player_id']);
        self::assertSame('["pair"]', $row['looking_for']);
        self::assertSame('["sorting"]', $row['strengths']);
        self::assertSame('["Wasgij"]', $row['custom_strengths']);
        self::assertSame('["cs", "en"]', $row['languages'], 'pre-filled from the country, plus English');
        self::assertSame('Happy to practise online first.', $row['note']);

        // The page now shows it as the own listing, and the form edits it
        $crawler = $browser->request('GET', self::FINDER);
        self::assertCount(1, $crawler->filter('.pf-own #partner-listing-row-' . $listingId));
        $crawler = $browser->request('GET', self::FORM);
        self::assertSame($listingId, self::field($crawler->selectButton('Save')->form(), 'partner_listing_form[listingId]')->getValue());
    }

    public function testFoundMyPartnerClosesTheListing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/partners/listing/' . PartnerFinderFixture::LISTING_REGULAR_PAIR . '/close', [
            '_token' => 'csrf-token',
            'reason' => 'found',
            'return' => self::FINDER,
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::FINDER, 303);
        self::assertSame('found', self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT closed_reason FROM partner_listing WHERE id = :id',
            ['id' => PartnerFinderFixture::LISTING_REGULAR_PAIR],
        ));

        // Somebody else's listing is not there for them
        $browser->request('POST', '/en/partners/listing/' . PartnerFinderFixture::LISTING_FAVORITES_TEAM . '/close', [
            '_token' => 'csrf-token',
            'reason' => 'withdrawn',
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
    }

    private static function field(Form $form, string $name): FormField
    {
        $field = $form[$name];
        self::assertInstanceOf(FormField::class, $field);

        return $field;
    }
}
