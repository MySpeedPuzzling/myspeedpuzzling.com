<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Add to my profile" of an official result that did not finish within the round's time limit
 * (docs/features/competitions-management/unfinished-results-plan.md "Refined 2026-11-16" rule 8): the round page
 * offers it and the add form opens with "I didn't finish within the time limit" on and the pieces filled in. Results
 * Cup Group A (solo, 90 min, PUZZLE_1000_05): Dan placed 850 pieces - renamed to PLAYER_WITH_FAVORITES' name, so the
 * entry nobody is linked to is theirs to take.
 */
final class PuzzleAddOfficialUnfinishedEntryTest extends WebTestCase
{
    private const string URL = '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05 . '?competition=' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '&official_entry=participant_round:' . OfficialResultsFixture::ENTRY_A_DAN;

    public function testTheFormOpensWithTheSwitchOnAndTheEntrysPiecesAndSavesIt(): void
    {
        $browser = $this->viewerOfDansName();

        $crawler = $browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-official-entry-notice]'));
        self::assertSame('checked', $crawler->filter('input[name="puzzle_add_form[unfinished]"]')->attr('checked'));
        self::assertSame('850', $crawler->filter('input[name="puzzle_add_form[piecesPlaced]"]')->attr('value'));
        self::assertStringContainsString('/ 1000', $crawler->filter('[data-testid="unfinished-details"]')->text());
        self::assertSame(['0', '0', '0'], [
            $crawler->filter('input[name="puzzle_add_form[timeHours]"]')->attr('value'),
            $crawler->filter('input[name="puzzle_add_form[timeMinutes]"]')->attr('value'),
            $crawler->filter('input[name="puzzle_add_form[timeSeconds]"]')->attr('value'),
        ]);

        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $browser->request('POST', self::URL, [
            'puzzle_add_form' => $this->submissionOf($crawler),
            'time_id' => $timeId,
            'new_puzzle_id' => (string) $crawler->filter('input[name="new_puzzle_id"]')->attr('value'),
            'official_entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN,
        ]);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(
            ['pieces_placed' => 850, 'seconds_to_solve' => null, 'competition_round_id' => OfficialResultsFixture::ROUND_GROUP_A],
            self::getContainer()->get(Connection::class)->fetchAssociative('SELECT pieces_placed, seconds_to_solve, competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]),
        );

        // On the profile now: the round page offers it no more
        $crawler = $browser->request('GET', self::URL);
        self::assertCount(0, $crawler->filter('[data-official-entry-notice]'));
    }

    public function testARoundWithoutATimeLimitOffersNothing(): void
    {
        $browser = $this->viewerOfDansName();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET minutes_limit = NULL WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_A],
        );

        $crawler = $browser->request('GET', self::URL);

        self::assertCount(0, $crawler->filter('[data-official-entry-notice]'));
        self::assertNull($crawler->filter('input[name="puzzle_add_form[unfinished]"]')->attr('checked'));
    }

    private function viewerOfDansName(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_participant SET name = 'Michael Johnson', country = NULL WHERE id = :id",
            ['id' => OfficialResultsFixture::PARTICIPANT_DAN],
        );

        return $browser;
    }

    /**
     * @return array<string, string>
     */
    private function submissionOf(Crawler $crawler): array
    {
        $value = static fn (string $field): string => (string) $crawler->filter(sprintf('[name="puzzle_add_form[%s]"]', $field))->attr('value');

        return [
            '_token' => $value('_token'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_1000_05,
            'timeHours' => '0',
            'timeMinutes' => '0',
            'timeSeconds' => '0',
            'finishedAt' => $value('finishedAt'),
            'competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'unfinished' => '1',
            'piecesPlaced' => $value('piecesPlaced'),
            'collection' => '__system_collection__',
        ];
    }
}
