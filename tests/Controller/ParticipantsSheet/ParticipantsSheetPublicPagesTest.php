<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the participants spreadsheet stores - a round's expected team size, the receipts of its change sets - never
 * shows on the public pages: an event page and its round pages render byte for byte the same and run the same
 * statements as without them (the OfficialRoundResultsPageTest rule for untouched pages).
 */
final class ParticipantsSheetPublicPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function providePages(): iterable
    {
        $pages = [
            'event page' => '/en/events/results-cup',
            'published round' => '/en/events/results-cup/results/group-a',
            'unpublished round' => '/en/events/results-cup/results/group-b',
            'pairs round' => '/en/events/results-cup/results/pairs',
            'edition with a team round' => '/en/series/puzzle-meetup-prague/puzzle-meetup-1',
        ];

        foreach ($pages as $name => $url) {
            yield $name . ', guest' => [$url, null];
            yield $name . ', organiser' => [$url, PlayerFixture::PLAYER_WITH_STRIPE];
        }
    }

    #[DataProvider('providePages')]
    public function testThePageIsTheSameWithTeamSizesAndReceipts(string $url, null|string $viewer): void
    {
        $browser = self::createClient();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        [$before, $statementsBefore] = $this->render($browser, $url);

        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE competition_round SET team_size = 4 WHERE competition_id IN (:results, :edition)',
            ['results' => OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'edition' => CompetitionSeriesFixture::EDITION_OFFLINE_1],
        );

        foreach ([OfficialResultsFixture::COMPETITION_RESULTS_CUP, CompetitionSeriesFixture::EDITION_OFFLINE_1] as $competitionId) {
            $database->insert('participant_sheet_change_receipt', [
                'id' => Uuid::uuid7()->toString(),
                'competition_id' => $competitionId,
                'received_at' => '2026-10-07 10:00:00',
                'outcomes' => '[{"id": "g1", "status": "applied", "changes": [], "warnings": [], "deletedTeams": []}]',
                'version_before' => str_repeat('a', 64),
                'version_after' => str_repeat('b', 64),
            ]);
        }

        [$after, $statementsAfter] = $this->render($browser, $url);

        self::assertSame($statementsBefore, $statementsAfter, 'The page runs the same statements.');
        self::assertSame($before, $after, 'The page is the same, byte for byte.');
    }

    /**
     * @return array{string, int}
     */
    private function render(KernelBrowser $browser, string $url): array
    {
        // Warm-up: whatever the page caches, the counted request finds it cached
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        // The moment of rendering is in the page (stale-document telemetry) - the only thing allowed to differ
        $html = (string) preg_replace('~<meta name="msp-rendered-at" content="\d+">~', '', (string) $browser->getResponse()->getContent());

        return [$html, $this->queryCount($browser)];
    }
}
