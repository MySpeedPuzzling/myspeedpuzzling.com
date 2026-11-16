<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The edit form of an unfinished result (docs/features/competitions-management/unfinished-results-plan.md, step 4):
 * it opens as a speed puzzling result with "I didn't finish within the time limit" on - never as a relax solve - and
 * every transition goes through the right message: unfinished → unfinished (EditUnfinishedPuzzleSolvingTime),
 * unfinished → finished / relax (EditPuzzleSolvingTime ending it), finished → unfinished. WJPC 2024's Qualification
 * Round: 60 min, solo, PUZZLE_500_02 (500 pieces).
 */
final class EditTimeUnfinishedResultTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testItOpensWithTheSwitchOnAndItsPiecesAndTotal(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult(totalTime: '1:12:30');

        foreach ([[], ['HTTP_TURBO_FRAME' => 'modal-frame']] as $server) {
            $crawler = $browser->request('GET', '/en/edit-time/' . $timeId, server: $server);

            self::assertResponseIsSuccessful();
            self::assertSame('speed_puzzling', $crawler->filter('select[name="edit_puzzle_solving_time_form[mode]"] option[selected]')->attr('value'));
            self::assertSame('checked', $crawler->filter('input[name="edit_puzzle_solving_time_form[unfinished]"]')->attr('checked'));
            self::assertSame('479', $crawler->filter('input[name="edit_puzzle_solving_time_form[piecesPlaced]"]')->attr('value'));
            self::assertSame(['1', '12', '30'], [
                $crawler->filter('input[name="edit_puzzle_solving_time_form[timeHours]"]')->attr('value'),
                $crawler->filter('input[name="edit_puzzle_solving_time_form[timeMinutes]"]')->attr('value'),
                $crawler->filter('input[name="edit_puzzle_solving_time_form[timeSeconds]"]')->attr('value'),
            ]);
            self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-details"]')->attr('class'));
            self::assertStringContainsString('/ 500', $crawler->filter('[data-testid="unfinished-details"]')->text());
            self::assertStringContainsString('21 pieces left', $crawler->filter('[data-testid="unfinished-details"]')->text());
            self::assertSame('Total time, if you finished it afterwards (optional)', trim($crawler->filter('[data-unfinished-result-target="timeLabel"]')->text()));
        }
    }

    public function testTheRoundTripUnfinishedFinishedUnfinished(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult();

        // Other pieces, still unfinished
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler), 'unfinished' => '1', 'piecesPlaced' => '450', 'comment' => 'fewer']]);
        self::assertResponseRedirects();
        self::assertSame(['pieces_placed' => 450, 'seconds_to_solve' => null, 'finished_later_seconds' => null, 'seconds_spent' => 3600, 'comment' => 'fewer'], $this->stored($timeId));

        // The switch off with a time: a finished result
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler, ['0', '59', '0']), 'piecesPlaced' => '450']]);
        self::assertResponseRedirects();
        self::assertSame(['pieces_placed' => null, 'seconds_to_solve' => 3540, 'finished_later_seconds' => null, 'seconds_spent' => null, 'comment' => null], $this->stored($timeId));

        // ...and unfinished again, finished afterwards in 1:10:00
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        self::assertNull($crawler->filter('input[name="edit_puzzle_solving_time_form[unfinished]"]')->attr('checked'));
        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler, ['1', '10', '0']), 'unfinished' => '1', 'piecesPlaced' => '480']]);
        self::assertResponseRedirects();
        self::assertSame(['pieces_placed' => 480, 'seconds_to_solve' => null, 'finished_later_seconds' => 4200, 'seconds_spent' => 4200, 'comment' => null], $this->stored($timeId));
    }

    public function testTheModalSavesThroughItsStream(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult();
        $modal = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler), 'unfinished' => '1', 'piecesPlaced' => '300']], server: $modal);

        self::assertResponseIsSuccessful();
        self::assertSame(300, $this->stored($timeId)['pieces_placed']);
    }

    public function testRelaxEndsIt(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult();

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        // A relax form still posts the hidden switch - it means nothing there
        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler), 'mode' => 'relax', 'unfinished' => '1', 'piecesPlaced' => '479']]);

        self::assertResponseRedirects();
        self::assertSame(['pieces_placed' => null, 'seconds_to_solve' => null, 'finished_later_seconds' => null, 'seconds_spent' => null, 'comment' => null], $this->stored($timeId));
    }

    public function testRefusalsAre422OnTheirField(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult();

        $cases = [
            'all pieces' => [['unfinished' => '1', 'piecesPlaced' => '500'], ['0', '0', '0'], 'All 500 pieces placed means you finished'],
            'no pieces' => [['unfinished' => '1', 'piecesPlaced' => ''], ['0', '0', '0'], 'How many pieces did you place'],
            'total within the limit' => [['unfinished' => '1', 'piecesPlaced' => '450'], ['0', '45', '0'], '0:45:00 is within the 60 min limit'],
            'no time and the switch off' => [['piecesPlaced' => '450'], ['0', '0', '0'], 'Please enter the time'],
        ];

        foreach ($cases as $case => [$fields, $time, $message]) {
            $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
            $crawler = $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler, $time), ...$fields]]);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertStringContainsString($message, $crawler->filter('form')->text(), $case);
            self::assertSame(479, $this->stored($timeId)['pieces_placed'], $case);
        }
    }

    public function testAFinishedTimeOverTheLimitSuggestsTheSwitch(): void
    {
        $browser = $this->signedIn();
        $timeId = $this->finishedResult('1:05:00');

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);

        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-question"]')->attr('class'));
        self::assertStringContainsString('1:05:00 is over the 60 min limit of Qualification Round', $crawler->filter('[data-testid="unfinished-nudge"]')->text());

        // Within the limit nothing is asked
        $timeId = $this->finishedResult('0:55:00');
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-question"]')->attr('class'));
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-testid="unfinished-nudge"]')->attr('class'));
    }

    public function testWithTheKillSwitchOffAnUnfinishedResultIsStillEditable(): void
    {
        $this->overrideFeatureFlagEnv('UNFINISHED_RESULTS_FORM', false);
        $browser = $this->signedIn();
        $timeId = $this->unfinishedResult();

        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        self::assertSame('checked', $crawler->filter('input[name="edit_puzzle_solving_time_form[unfinished]"]')->attr('checked'));

        $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => [...$this->submission($crawler), 'unfinished' => '1', 'piecesPlaced' => '470']]);
        self::assertResponseRedirects();
        self::assertSame(470, $this->stored($timeId)['pieces_placed']);
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @param array{string, string, string} $time
     * @return array<string, string>
     */
    private function submission(Crawler $crawler, array $time = ['0', '0', '0']): array
    {
        return [
            '_token' => (string) $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_02,
            'timeHours' => $time[0],
            'timeMinutes' => $time[1],
            'timeSeconds' => $time[2],
            'finishedAt' => '02.03.2026',
            'competition' => CompetitionFixture::COMPETITION_WJPC_2024,
        ];
    }

    private function unfinishedResult(null|string $totalTime = null): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            piecesPlaced: 479,
            totalTime: $totalTime,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function finishedResult(string $time): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-0' . (str_starts_with($time, '1') ? '3' : '4')),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT pieces_placed, seconds_to_solve, finished_later_seconds, seconds_spent, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
