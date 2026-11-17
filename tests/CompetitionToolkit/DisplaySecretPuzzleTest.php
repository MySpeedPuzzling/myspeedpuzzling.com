<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A display's state never carries anything of a round puzzle before everybody may see it (D-displays-overlays.md §9.3,
 * R-review-and-plan.md §1.5) - for every reveal mode, image-only hiding, the site-wide embargo and pick rounds. The
 * raw JSON is searched for the hidden puzzle's name, picture file and id.
 *
 * CompetitionApiFixture's future round (+5 days): "API Hidden Entirely" (secret), "API Hidden Image" (image only),
 * "API Visible", "API Platform Hidden" (hide_until +30 days) and "API Platform Image Hidden" (hide_image_until).
 */
final class DisplaySecretPuzzleTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use ReadsRoundAutomaticReveal;

    private const string HIDDEN_NAME = 'API Hidden Entirely';
    private const array PUZZLE_ELEMENT = [['type' => 'puzzle', 'props' => ['show' => ['image', 'name', 'brand', 'pieces'], 'max' => 6]]];

    public function testBeforeTheAutomaticRevealNothingOfTheSecretIsSent(): void
    {
        $browser = $this->browser();
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, self::PUZZLE_ELEMENT, CompetitionApiFixture::ROUND_FUTURE);

        [$raw, $state] = self::displayState($browser, $token);

        self::assertHiddenSecretIsNowhere($raw);
        self::assertSame(['API Hidden Image', 'API Platform Image Hidden', 'API Visible'], self::names($state));
        // Image only: the name, never the picture
        self::assertNull(self::puzzle($state, 'API Hidden Image')['imageUrl']);
        self::assertStringNotContainsString(CompetitionApiFixture::IMAGE_HIDDEN_IMAGE, $raw);
        self::assertNull(self::puzzle($state, 'API Platform Image Hidden')['imageUrl']);
        self::assertStringNotContainsString(CompetitionApiFixture::IMAGE_PLATFORM_IMAGE_HIDDEN, $raw);
        self::assertStringContainsString(CompetitionApiFixture::IMAGE_VISIBLE, (string) json_encode(self::puzzle($state, 'API Visible')['imageUrl']));
        self::assertTrue($state['anyPuzzleRevealed']);
        // The display flips at the reveal moment by itself - a moment, never the puzzle
        self::assertSame(self::milliseconds(self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE)), $state['refreshAtMs']);

        // At the reveal it is out, with its picture
        self::moveClock(self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE)->format('Y-m-d H:i:s'));
        [$raw, $state] = self::displayState($browser, $token);
        self::assertContains(self::HIDDEN_NAME, self::names($state));
        self::assertStringContainsString(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, $raw);
        self::assertStringContainsString(CompetitionApiFixture::IMAGE_HIDDEN_IMAGE, $raw);
        // The site-wide embargo still holds
        self::assertStringNotContainsString('API Platform Hidden"', $raw);
        self::assertStringNotContainsString(CompetitionApiFixture::IMAGE_PLATFORM_HIDDEN, $raw);
    }

    /**
     * @return iterable<string, array{RoundPuzzleReveal, PuzzleHideMode}>
     */
    public static function manualAndScheduled(): iterable
    {
        yield 'manual, hidden entirely' => [RoundPuzzleReveal::Manual, PuzzleHideMode::Entirely];
        yield 'manual, image only' => [RoundPuzzleReveal::Manual, PuzzleHideMode::ImageOnly];
        yield 'scheduled, hidden entirely' => [RoundPuzzleReveal::Scheduled, PuzzleHideMode::Entirely];
        yield 'scheduled, image only' => [RoundPuzzleReveal::Scheduled, PuzzleHideMode::ImageOnly];
    }

    #[DataProvider('manualAndScheduled')]
    public function testManualAndScheduledRevealsKeepTheSecretUntilTheirMoment(RoundPuzzleReveal $mode, PuzzleHideMode $hideMode): void
    {
        $browser = $this->browser();
        $scheduledAt = TestClock::now()->modify('+2 hours');
        $this->bus()->dispatch(new ChangeRoundPuzzleReveal(
            $this->roundPuzzleId(),
            $hideMode,
            $mode,
            scheduledAt: $mode === RoundPuzzleReveal::Scheduled ? $scheduledAt : null,
            namePublicationConfirmed: $hideMode === PuzzleHideMode::ImageOnly,
        ));
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, self::PUZZLE_ELEMENT, CompetitionApiFixture::ROUND_FUTURE);

        // Long after the round's own start + delay: a manual reveal not made yet keeps it; a scheduled one has no say
        // before its moment
        if ($mode === RoundPuzzleReveal::Manual) {
            self::moveClock('+6 days');
        } else {
            self::moveClock('+1 hour');
        }
        [$raw, $state] = self::displayState($browser, $token);

        if ($hideMode === PuzzleHideMode::Entirely) {
            self::assertHiddenSecretIsNowhere($raw);
        } else {
            self::assertNull(self::puzzle($state, self::HIDDEN_NAME)['imageUrl']);
            self::assertStringNotContainsString(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, $raw);
        }

        if ($mode === RoundPuzzleReveal::Scheduled) {
            self::assertSame(self::milliseconds($scheduledAt), $state['refreshAtMs']);
            self::moveClock($scheduledAt->format('Y-m-d H:i:s'));
        } else {
            $this->bus()->dispatch(new RevealRoundPuzzleNow($this->roundPuzzleId()));
        }

        [$raw, $state] = self::displayState($browser, $token);
        self::assertContains(self::HIDDEN_NAME, self::names($state));
        self::assertStringContainsString(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, $raw);
    }

    /**
     * "When the stopwatch starts" (A1): a test start without the tick reveals nothing; a scheduled start with the tick
     * keeps the secret until the countdown ends - one second before it nothing, at it the puzzle.
     */
    public function testAStopwatchRevealOpensOnlyAtATickedStart(): void
    {
        $browser = $this->browser();
        $this->bus()->dispatch(new ChangeRoundPuzzleReveal($this->roundPuzzleId(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, self::PUZZLE_ELEMENT, CompetitionApiFixture::ROUND_FUTURE);

        // Days after the round's start + delay: the stopwatch mode does not care
        self::moveClock('+6 days');
        [$raw] = self::displayState($browser, $token);
        self::assertHiddenSecretIsNowhere($raw);

        // A rehearsal start without the tick
        $this->bus()->dispatch(new StartRoundStopwatch(CompetitionApiFixture::ROUND_FUTURE));
        [$raw, $state] = self::displayState($browser, $token);
        self::assertHiddenSecretIsNowhere($raw);
        self::assertSame('running', self::at($state, 'stopwatch.status'));

        // Reset after the rehearsal - still nothing
        $this->bus()->dispatch(new ResetRoundStopwatch(CompetitionApiFixture::ROUND_FUTURE, CompetitionApiFixture::COMPETITION_API));
        [$raw] = self::displayState($browser, $token);
        self::assertHiddenSecretIsNowhere($raw, 'after the reset');

        // The real start: scheduled one second ahead, ticked
        $startsAt = self::getContainer()->get(ClockInterface::class)->now()->modify('+1 second');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, CompetitionApiFixture::ROUND_FUTURE);
        self::assertNotNull($round);
        $preview = self::getContainer()->get(SecretRevealPreview::class);
        $hash = SecretRevealPreview::stopwatchStartHash($preview->byStopwatchStart($round), $round);
        $this->bus()->dispatch(new ScheduleRoundStart(CompetitionApiFixture::ROUND_FUTURE, $startsAt, PlayerFixture::PLAYER_ADMIN, revealPuzzles: true, revealListHash: $hash));

        [$raw, $state] = self::displayState($browser, $token);
        self::assertHiddenSecretIsNowhere($raw, 'at scheduled - 1 s');
        self::assertSame('scheduled', self::at($state, 'stopwatch.status'));
        self::assertSame(self::milliseconds($startsAt), $state['refreshAtMs']);

        self::moveClock('+1 second');
        [$raw, $state] = self::displayState($browser, $token);
        self::assertContains(self::HIDDEN_NAME, self::names($state), 'at the start');
        self::assertStringContainsString(CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, $raw);
    }

    /**
     * A pick round (A2): before the reveal its slots are neither listed nor counted - no label, no position, no
     * "3 puzzles"; only what everybody may see, by name.
     */
    public function testAPickRoundSendsOnlyItsRevealedPuzzles(): void
    {
        $browser = $this->browser();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 1 WHERE id = :round",
            ['round' => CompetitionApiFixture::ROUND_FUTURE],
        );
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, self::PUZZLE_ELEMENT, CompetitionApiFixture::ROUND_FUTURE);

        [$raw, $state] = self::displayState($browser, $token);

        self::assertHiddenSecretIsNowhere($raw);
        $puzzles = $state['puzzles'];
        self::assertIsArray($puzzles);
        self::assertCount(3, $puzzles);
        foreach ($puzzles as $puzzle) {
            self::assertIsArray($puzzle);
            self::assertSame(['name', 'brand', 'pieces', 'imageUrl'], array_keys($puzzle));
        }
        self::assertStringNotContainsString('"label"', $raw);
        self::assertStringNotContainsString('"position"', $raw);
        self::assertStringNotContainsString('slots', $raw);
        self::assertStringNotContainsString('puzzleFormat', $raw);
        self::assertStringNotContainsString('puzzlesCount', $raw);
    }

    /**
     * A display that follows the current round reads the round's puzzles the same way
     */
    public function testFollowingTheCurrentRoundKeepsTheSecretToo(): void
    {
        $browser = $this->browser();
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, self::PUZZLE_ELEMENT, followRoundIds: [CompetitionApiFixture::ROUND_FUTURE]);

        [$raw, $state] = self::displayState($browser, $token);

        self::assertSame(CompetitionApiFixture::ROUND_FUTURE, self::at($state, 'round.id'));
        self::assertHiddenSecretIsNowhere($raw);
    }

    private static function assertHiddenSecretIsNowhere(string $raw, string $when = ''): void
    {
        foreach ([self::HIDDEN_NAME, CompetitionApiFixture::IMAGE_HIDDEN_ENTIRELY, CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, 'API Platform Hidden"', CompetitionApiFixture::IMAGE_PLATFORM_HIDDEN, CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN] as $secret) {
            self::assertStringNotContainsString($secret, $raw, trim('A hidden puzzle leaks into the display state ' . $when));
        }

        // No puzzle id of any kind - the display never needs one
        foreach ([CompetitionApiFixture::PUZZLE_HIDDEN_IMAGE, CompetitionApiFixture::PUZZLE_VISIBLE, CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN] as $puzzleId) {
            self::assertStringNotContainsString($puzzleId, $raw);
        }
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    /**
     * The test container's clock is a MockClock (config/services_test.php) - it moves on at once
     */
    private static function moveClock(string $modifier): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $now = $clock->now();
        $clock->sleep($now->modify($modifier)->getTimestamp() - $now->getTimestamp());
    }

    private function roundPuzzleId(): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id = :puzzle',
            ['round' => CompetitionApiFixture::ROUND_FUTURE, 'puzzle' => CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY],
        );
        assert(is_string($id));

        return $id;
    }

    private static function milliseconds(\DateTimeImmutable $moment): int
    {
        return $moment->getTimestamp() * 1000;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private static function names(array $state): array
    {
        $puzzles = $state['puzzles'];
        assert(is_array($puzzles));

        return array_values(array_map(static fn (mixed $puzzle): string => is_array($puzzle) && is_string($puzzle['name'] ?? null) ? $puzzle['name'] : '', $puzzles));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function puzzle(array $state, string $name): array
    {
        foreach (is_array($state['puzzles']) ? $state['puzzles'] : [] as $puzzle) {
            if (is_array($puzzle) && ($puzzle['name'] ?? null) === $name) {
                /** @var array<string, mixed> $puzzle */
                return $puzzle;
            }
        }

        self::fail('No puzzle ' . $name . ' in the display state.');
    }
}
