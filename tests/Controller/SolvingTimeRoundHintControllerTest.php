<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The round hint of the add/edit-time form (docs/features/competitions-management/unfinished-results-plan.md, "The hint
 * endpoint"): the round with a time limit an unfinished result would be recorded against - by the save's rule - or null.
 */
final class SolvingTimeRoundHintControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testARoundOfTheCompetitionPuzzleAndCategoryAnswersItsNameAndLimit(): void
    {
        $browser = $this->signedIn();

        $answer = $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '1');

        self::assertSame(['round' => ['name' => 'Qualification Round', 'minutesLimit' => 60, 'category' => 'solo']], $answer);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testAnotherCategoryHasNoRound(): void
    {
        $browser = $this->signedIn();

        // The qualification is a solo round - a pair or a team on its puzzle belongs to no round
        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '2'));
        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '4'));
    }

    public function testAPuzzleOutsideTheRoundsHasNoRound(): void
    {
        $browser = $this->signedIn();

        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_05, '1'));
    }

    public function testACompetitionWithoutRoundsHasNoRound(): void
    {
        $browser = $this->signedIn();
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM competition_round_puzzle WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '1'));
    }

    public function testARoundWithoutATimeLimitHasNoQuestion(): void
    {
        $browser = $this->signedIn();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET minutes_limit = NULL WHERE id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );

        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '1'));
    }

    public function testASeriesPickFindsTheEditionByItsPuzzle(): void
    {
        $browser = $this->signedIn();

        // Sprint 1 of Season One holds the puzzle (revealed) - a series pick is found by it
        $answer = $this->hint($browser, 'series:' . EventsPageFixture::SERIES_SPRINT_LEAGUE, PuzzleFixture::PUZZLE_500_01, '1');

        self::assertIsArray($answer['round']);
        self::assertSame('Sprint 1', $answer['round']['name']);
    }

    public function testAPuzzleACompetitionKeepsSecretAnswersNothing(): void
    {
        $browser = $this->signedIn();

        // PUZZLE_500_03 is the hidden puzzle of the next sprint round - the answer must not confirm where it is
        self::assertSame(['round' => null], $this->hint($browser, 'edition:' . EventsPageFixture::EDITION_SPRINT_SEASON, PuzzleFixture::PUZZLE_500_03, '1'));
        self::assertSame(['round' => null], $this->hint($browser, 'series:' . EventsPageFixture::SERIES_SPRINT_LEAGUE, PuzzleFixture::PUZZLE_500_03, '1'));
    }

    public function testAPairRoundWhosePictureIsHiddenStillAnswers(): void
    {
        $browser = $this->signedIn();

        // Saturday Pairs hides only the picture of its puzzle until it starts - its name is public on the event page
        $answer = $this->hint($browser, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, PuzzleFixture::PUZZLE_500_04, '2');

        self::assertSame(['name' => 'Saturday Pairs', 'minutesLimit' => 90, 'category' => 'duo'], $answer['round']);
        self::assertSame(['round' => null], $this->hint($browser, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, PuzzleFixture::PUZZLE_500_04, '1'));
    }

    public function testADraftEventAnswersNothing(): void
    {
        // Its organiser included - the form offers nobody a draft
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(['round' => null], $this->hint($browser, OrganizationFixture::COMPETITION_DRAFT_NIGHT, PuzzleFixture::PUZZLE_3000, '1'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideGarbage(): iterable
    {
        yield 'nothing' => ['', '', ''];
        yield 'not a uuid' => ['nope', 'nope', 'x'];
        yield 'a malformed series pick' => ['series:123', PuzzleFixture::PUZZLE_500_02, '1'];
        yield 'an unknown competition' => ['018d0000-0000-0000-0000-00000000dead', PuzzleFixture::PUZZLE_500_02, '1'];
        yield 'an unknown series' => ['series:018d0000-0000-0000-0000-00000000dead', PuzzleFixture::PUZZLE_500_02, '1'];
        yield 'an unknown puzzle' => [CompetitionFixture::COMPETITION_WJPC_2024, '018d0000-0000-0000-0000-00000000dead', '1'];
    }

    #[DataProvider('provideGarbage')]
    public function testGarbageAnswersNull(string $competition, string $puzzle, string $puzzlers): void
    {
        $browser = $this->signedIn();

        self::assertSame(['round' => null], $this->hint($browser, $competition, $puzzle, $puzzlers));
    }

    public function testACountThatIsNoNumberCountsAsSolo(): void
    {
        $browser = $this->signedIn();

        foreach (['-3', '99999999999999999999', 'two'] as $puzzlers) {
            $answer = $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, $puzzlers);
            self::assertIsArray($answer['round'], $puzzlers);
        }
    }

    public function testTheKillSwitchAnswersNull(): void
    {
        $this->overrideFeatureFlagEnv('UNFINISHED_RESULTS_FORM', false);
        $browser = $this->signedIn();

        self::assertSame(['round' => null], $this->hint($browser, CompetitionFixture::COMPETITION_WJPC_2024, PuzzleFixture::PUZZLE_500_02, '1'));
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/solving-time-round-hint?competition=' . CompetitionFixture::COMPETITION_WJPC_2024 . '&puzzle=' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('Qualification', (string) $browser->getResponse()->getContent());
    }

    private function signedIn(string $playerId = PlayerFixture::PLAYER_REGULAR): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * @return array<mixed>
     */
    private function hint(KernelBrowser $browser, string $competition, string $puzzle, string $puzzlers): array
    {
        $browser->request('GET', '/en/solving-time-round-hint?' . http_build_query(['competition' => $competition, 'puzzle' => $puzzle, 'puzzlers' => $puzzlers]));

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($answer);

        return $answer;
    }
}
