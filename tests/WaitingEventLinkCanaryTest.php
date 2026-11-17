<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\ApplyWaitingEventLinks;
use SpeedPuzzling\Web\Message\LinkSolvingTimesToEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Link my results" to the player's own event that is not approved yet (docs/features/competitions-management/
 * creation-wizard.md "Backfill") never reveals that event: the link waits (WaitingEventLink) and the time stays
 * unlinked, so no reader of puzzle_solving_time - none of them checks the event's visibility before naming it - has
 * anything to show. For every surface that names a time's event: while it waits, the event's name is not there (a guest
 * and another player alike); once the event is approved and the cron applied the link, it is - or the surface proves
 * nothing. (The puzzle page names no event of a plain solo row - it shows the competition's badge only for a tagged
 * event - so it would prove nothing here; SeriesPickCanaryTest covers its event badges.)
 */
final class WaitingEventLinkCanaryTest extends WebTestCase
{
    private const string EVENT_NAME = 'Unapproved Puzzle Event';

    #[DataProvider('provideSurfaces')]
    public function testAWaitingLinkNamesTheEventNowhere(string $url, null|string $viewer): void
    {
        $browser = self::createClient();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // TIME_06: PLAYER_REGULAR's solo PUZZLE_500_02, linked to his own pending event
        $bus->dispatch(new LinkSolvingTimesToEvent(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_UNAPPROVED, null, [PuzzleSolvingTimeFixture::TIME_06]));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::EVENT_NAME, (string) $browser->getResponse()->getContent(), 'A waiting link names the event that is not public yet');

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET approved_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_UNAPPROVED]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $bus->dispatch(new ApplyWaitingEventLinks());

        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::EVENT_NAME, (string) $browser->getResponse()->getContent(), 'The surface does not name the event once the link is applied - it is no canary');
    }

    /**
     * @return iterable<string, array{0: string, 1: null|string}>
     */
    public static function provideSurfaces(): iterable
    {
        foreach (['guest' => null, 'another player' => PlayerFixture::PLAYER_WITH_FAVORITES] as $who => $viewer) {
            yield "player profile ({$who})" => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $viewer];
            yield "result detail ({$who})" => ['/en/result/' . PuzzleSolvingTimeFixture::TIME_06, $viewer];
        }
    }
}
