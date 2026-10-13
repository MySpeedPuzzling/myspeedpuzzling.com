<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every reader of the round stopwatch handles a scheduled start (RoundStopwatchStatus): before its moment the round has
 * not started - no "running" anywhere, the seating step still recommended - and from its moment it runs, although
 * nothing was written then.
 */
final class ScheduledRoundStopwatchReadersTest extends WebTestCase
{
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_A;
    // The Results Cup's organiser
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        // The round is planned three hours from now - only the stopwatch can have started it
        $this->database->executeStatement('UPDATE competition_round SET starts_at = :startsAt WHERE id = :id', [
            'startsAt' => $this->now()->modify('+3 hours')->format('Y-m-d H:i:s'),
            'id' => self::ROUND,
        ]);
    }

    public function testBeforeItsMomentTheRoundHasNotStarted(): void
    {
        $this->scheduleStart('+10 minutes');

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(self::ROUND);
        self::assertSame('scheduled', $overview->stopwatchStatus);
        $stopwatch = $overview->jsonSerialize()['stopwatch'];
        self::assertIsArray($stopwatch);
        self::assertSame('scheduled', $stopwatch['status']);
        // Still the time to seat people
        self::assertTrue($overview->showsTablesReadiness);

        self::assertFalse($this->sheetRoundStarted());

        $manage = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertResponseIsSuccessful();
        self::assertSame(['scheduled'], self::shownBadges($manage));
        self::assertCount(1, $manage->filter('[data-seating-readiness]'));
        self::assertStringContainsString('Scheduled for', $manage->filter('[data-stopwatch-starts-at]')->text());
        self::assertSame('scheduled', $manage->filter('[data-controller="round-stopwatch"]')->attr('data-stopwatch-phase'));
        // A countdown is called off before it ends - it is neither started again nor stopped
        self::assertCount(0, self::shown($manage, 'form[action$="/en/start-round-stopwatch/' . self::ROUND . '"]'));
        self::assertCount(0, self::shown($manage, 'form[action$="/en/stop-round-stopwatch/' . self::ROUND . '"]'));

        $projector = $this->browser->request('GET', '/en/round-stopwatch/' . self::ROUND);
        self::assertResponseIsSuccessful();
        self::assertSame('scheduled', $projector->filter('[data-controller="round-stopwatch"]')->attr('data-stopwatch-phase'));
        self::assertStringContainsString('Start scheduled', $projector->filter('[data-round-stopwatch-target="status"]')->text());
        self::assertCount(0, self::shown($projector, 'form[action$="/en/start-round-stopwatch/' . self::ROUND . '"]'));

        $state = json_decode((string) $projector->filter('[data-controller="round-stopwatch"]')->attr('data-round-stopwatch-state-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSame('scheduled', $state['status']);
    }

    public function testFromItsMomentTheRoundRunsWithoutAnyWrite(): void
    {
        $this->scheduleStart('-90 seconds');

        $overview = self::getContainer()->get(GetRoundResultsOverview::class)->forRound(self::ROUND);
        self::assertSame('running', $overview->stopwatchStatus);
        self::assertFalse($overview->showsTablesReadiness, 'a round under way never nags');

        self::assertTrue($this->sheetRoundStarted());

        $manage = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertSame(['running'], self::shownBadges($manage));
        self::assertCount(1, self::shown($manage, 'form[action$="/en/stop-round-stopwatch/' . self::ROUND . '"]'));
        self::assertCount(0, $manage->filter('[data-seating-readiness]'));

        $overviewPage = $this->browser->request('GET', '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertResponseIsSuccessful();
        $row = $overviewPage->filter('[data-overview-round="' . self::ROUND . '"]');
        self::assertNull($row->filter('[data-overview-stopwatch="running"]')->attr('hidden'));
        self::assertNotNull($row->filter('[data-overview-stopwatch="scheduled"]')->attr('hidden'));

        self::assertSame('scheduled', $this->database->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    public function testTheResultsOverviewSaysAStartIsScheduled(): void
    {
        $this->scheduleStart('+10 minutes');

        $page = $this->browser->request('GET', '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
        $row = $page->filter('[data-overview-round="' . self::ROUND . '"]');
        self::assertNull($row->filter('[data-overview-stopwatch="scheduled"]')->attr('hidden'));
        self::assertNotNull($row->filter('[data-overview-stopwatch="running"]')->attr('hidden'));
    }

    public function testTheLiveEntryCountsDownInsteadOfRunning(): void
    {
        $this->scheduleStart('+10 minutes');

        $page = $this->browser->request('GET', '/en/live-results/' . self::ROUND);

        self::assertResponseIsSuccessful();
        // The round picker names only a running round so
        self::assertStringNotContainsString('Running', $page->filter('.lr-round-select option[value="' . self::ROUND . '"]')->text());
        $messages = json_decode((string) $page->filter('[data-controller~="live-results"]')->attr('data-live-results-messages-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertSame('Starts in %time%', $messages['stopwatchStartsIn']);
    }

    private function scheduleStart(string $modifier): void
    {
        $this->database->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'scheduled', stopwatch_started_at = :startsAt, stopwatch_stopped_at = NULL WHERE id = :id",
            ['startsAt' => $this->now()->modify($modifier)->format('Y-m-d H:i:s'), 'id' => self::ROUND],
        );
    }

    private function sheetRoundStarted(): bool
    {
        $state = self::getContainer()->get(GetParticipantsSheetState::class)->forCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, self::ORGANISER);
        $round = $state->round(self::ROUND);
        self::assertNotNull($round);

        return $round->started;
    }

    /**
     * @return list<string>
     */
    private static function shownBadges(Crawler $page): array
    {
        return array_values(array_map(
            static fn (\DOMNode $badge): string => $badge instanceof \DOMElement ? $badge->getAttribute('data-stopwatch-when') : '',
            iterator_to_array(self::shown($page, '[data-stopwatch-status] [data-stopwatch-when]')),
        ));
    }

    /**
     * The elements matching the selector that are shown - neither they nor a phase group around them are hidden.
     */
    private static function shown(Crawler $page, string $selector): Crawler
    {
        return $page->filter($selector)->reduce(static function (Crawler $element): bool {
            $node = $element->getNode(0);

            while ($node instanceof \DOMElement) {
                if ($node->hasAttribute('hidden')) {
                    return false;
                }

                $node = $node->parentNode;
            }

            return true;
        });
    }

    private function now(): DateTimeImmutable
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();

        return $now->setTimestamp($now->getTimestamp());
    }
}
