<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The partner card and "Find a partner (N)" on an event page (docs/features/partner-finder.md "Part B"), on Partner
 * Finder Open (.claude/fixtures.md "Partner finder"): PLAYER_REGULAR going + listed (pair, one request),
 * PLAYER_WITH_FAVORITES going + listed (a group of 2 needing 2 more), PLAYER_ADMIN not going.
 *
 * The button and the card's links need the finder page and the listing form (part A): while those routes do not exist
 * the links are left out (PartnerFinderCardResolver) and the assertions on them are skipped - see
 * docs/features/partner-finder.md "Part B as built".
 */
final class PartnerFinderCardTest extends WebTestCase
{
    private const string PAGE = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG;

    public function testAGuestGetsTheLinkWithoutACountAndNoCard(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="partner-card"]'));

        $button = $crawler->filter('#taking-part [data-partner-finder-button]');
        self::assertCount(1, $button);
        self::assertCount(0, $button->filter('.pf-find-count'), 'no count for a guest');
    }

    public function testNotGoingWithListingsBrowses(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::PAGE);

        $card = $this->card($crawler, 'browse');
        self::assertSame('2 puzzlers are looking for a pair or a team here', $card->filter('.pf-card-title')->text());
        self::assertCount(2, $card->filter('.pf-card-face'));
        self::assertCount(1, $card->filter('.pf-card-close[aria-label="Don\'t show this again"]'));
        self::assertStringNotContainsString('pf-card-accent', (string) $card->attr('class'), 'plain, no gradient');

        self::assertSame('2', $crawler->filter('#taking-part [data-partner-finder-button] .pf-find-count')->text());
        self::assertCount(1, $card->filter('a.btn-primary'), 'See who\'s looking');
    }

    public function testAListedPlayerGetsTheStatusLine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::PAGE);

        $card = $this->card($crawler, 'listed');
        self::assertStringContainsString("You're listed as looking for a pair partner", $card->text());
        self::assertStringContainsString('one puzzler got in touch', $card->text());
        self::assertCount(0, $card->filter('.pf-card-close'), 'never closable');
    }

    public function testAListedGroupSaysHowManyMoreItNeeds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', self::PAGE);

        self::assertStringContainsString('Your group of 2 is listed, looking for 2 more', $this->card($crawler, 'listed')->text());
    }

    public function testRightAfterImGoingTheInvitationIsHighlightedAndClosingItKeepsTheButton(): void
    {
        $browser = self::createClient();
        // Not a member: "I'm going" leads back to the event page with the EventJustJoined flash
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/join-event/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertResponseRedirects(self::PAGE);

        $card = $this->card($browser->followRedirect(), 'invite');
        self::assertStringContainsString('pf-card-highlighted', (string) $card->attr('class'));
        self::assertStringContainsString('pf-card-accent', (string) $card->attr('class'));
        self::assertSame('Need a pair or a team for this one?', $card->filter('.pf-card-title')->text());
        self::assertSame('partner_finder_card', $card->attr('data-dismiss-hint-type-value'));

        // The flash is taken: the next visit is the plain invitation
        $card = $this->card($browser->request('GET', self::PAGE), 'invite');
        self::assertStringNotContainsString('pf-card-highlighted', (string) $card->attr('class'));
        self::assertSame('Going without a partner yet?', $card->filter('.pf-card-title')->text());

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO dismissed_hint (id, player_id, type, dismissed_at) VALUES (:id, :player, :type, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_PRIVATE, 'type' => HintType::PartnerFinderCard->value],
        );

        $crawler = $browser->request('GET', self::PAGE);
        self::assertCount(0, $crawler->filter('[data-testid="partner-card"]'), 'closed for good');

        self::assertCount(1, $crawler->filter('#taking-part [data-partner-finder-button]'), 'the button stays');
    }

    private function card(Crawler $crawler, string $state): Crawler
    {
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#taking-part [data-testid="partner-card"]');
        self::assertCount(1, $card);
        self::assertSame($state, $card->attr('data-partner-card-state'));

        return $card;
    }
}
