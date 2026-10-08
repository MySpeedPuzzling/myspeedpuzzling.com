<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Your events" with followed organizations (docs/features/organizations/README.md "Follow"): PLAYER_REGULAR follows
 * the Riverbend Jigsaw Association, its Lantern nights directly and the Quiet Pines series (now a draft) - the next
 * date of each Riverbend series and its upcoming one-time event are listed as Following, the Lantern nights once,
 * Quiet Pines never.
 */
final class YourEventsOrganizationsTest extends WebTestCase
{
    public function testTheFollowedOrganizationsItemsAreFollowing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $cards = $this->cards($browser);

        self::assertSame(['Lantern Night One'], $this->editionsOf($cards, OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME), 'the next Lantern night, once - not the draft one');
        self::assertSame(['Virtual Contest 2'], $this->editionsOf($cards, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_NAME), 'through the organization');
        self::assertSame([''], $this->editionsOf($cards, OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME), 'its one-time event');

        foreach ([OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_NAME, OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME] as $name) {
            self::assertCount(1, $this->card($cards, $name)->filter('.ev-mark-following'), $name);
        }

        self::assertSame([], $this->editionsOf($cards, OrganizationFixture::SERIES_QUIET_PINES_DRAFT_NAME), 'a draft series never');
    }

    public function testADraftOrganizationAddsNothing(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('followed_competition', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'organization_id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT,
            'created_at' => '2026-01-01 10:00:00',
        ]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Its series is public, but the follow is through the organization - which is a draft
        self::assertSame([], $this->editionsOf($this->cards($browser), OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_NAME));

        $connection->update('organization', ['is_draft' => 'false'], ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);

        // Canary: once the organization is published, the follow lists its series
        self::assertSame(['Harbor Club Meet 1'], $this->editionsOf($this->cards($browser), OrganizationFixture::SERIES_HARBOR_CLUB_MEETS_NAME));
    }

    private function cards(KernelBrowser $browser): Crawler
    {
        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();

        return $crawler->filter('.ev-your-events .ev-your-event');
    }

    /**
     * The edition names of the cards titled $name ('' for a one-time event)
     *
     * @return list<string>
     */
    private function editionsOf(Crawler $cards, string $name): array
    {
        $editions = [];

        $cards->each(static function (Crawler $card) use ($name, &$editions): void {
            if (trim($card->filter('.ev-your-name')->text()) === $name) {
                $edition = $card->filter('.ev-your-edition');
                $editions[] = $edition->count() > 0 ? trim($edition->text()) : '';
            }
        });

        return $editions;
    }

    private function card(Crawler $cards, string $name): Crawler
    {
        return $cards->reduce(static fn (Crawler $card): bool => trim($card->filter('.ev-your-name')->text()) === $name);
    }
}
