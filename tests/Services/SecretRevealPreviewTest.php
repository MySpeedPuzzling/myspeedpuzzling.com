<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "What would this round change reveal earlier than planned?" - the net automatic moment (start + reveal delay) decides,
 * whichever of the two moved; each puzzle says when and how far it comes out, and the yes is bound to exactly that.
 */
final class SecretRevealPreviewTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SecretRevealPreview $preview;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->preview = self::getContainer()->get(SecretRevealPreview::class);
    }

    public function testALaterOrEqualRevealListsNothing(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $start = $round->startsAt;

        self::assertSame([], $this->preview->byChangingRound($round, $start, RoundPuzzleReveal::DEFAULT_DELAY_MINUTES));
        self::assertSame([], $this->preview->byChangingRound($round, $start, 25));
        self::assertSame([], $this->preview->byChangingRound($round, $start->modify('+3 hours'), 0));
        // The start 15 minutes earlier, the delay 15 minutes longer: the same moment
        self::assertSame([], $this->preview->byChangingRound($round, $start->modify('-15 minutes'), 25));
        self::assertSame([], $this->preview->byChangingRound($round, $start->modify('+5 minutes'), 5));
    }

    public function testAnEarlierFutureRevealListsTheNewMoment(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $start = $round->startsAt;

        // A shorter delay
        $revealed = $this->preview->byChangingRound($round, $start, 5);
        self::assertCount(1, $revealed);
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $revealed[0]['id']);
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $revealed[0]['revealsAt']?->getTimestamp());
        self::assertSame(SecretRevealPreview::SCOPE_EVERYWHERE, $revealed[0]['scope']);
        self::assertTrue($revealed[0]['everywhere']);
        self::assertNull($revealed[0]['hiddenElsewhereUntil']);

        // A start moved earlier, still in the future
        $revealed = $this->preview->byChangingRound($round, $start->modify('-1 day'), RoundPuzzleReveal::DEFAULT_DELAY_MINUTES);
        self::assertCount(1, $revealed);
        self::assertSame($start->modify('-1 day +10 minutes')->getTimestamp(), $revealed[0]['revealsAt']?->getTimestamp());
    }

    public function testARevealInThePastIsRightAway(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $revealed = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), RoundPuzzleReveal::DEFAULT_DELAY_MINUTES);

        self::assertCount(1, $revealed);
        self::assertNull($revealed[0]['revealsAt'], 'Right away');
        self::assertSame(SecretRevealPreview::SCOPE_EVERYWHERE, $revealed[0]['scope']);
    }

    public function testAnotherRoundHoldingLongerMakesItThisEventOnly(): void
    {
        // The same puzzle is secret in the final too (2 days later), entirely
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $finalReveal = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL)->automaticRevealAt();

        $revealed = $this->preview->byChangingRound($round, $round->startsAt, 5);

        self::assertCount(1, $revealed);
        self::assertSame(SecretRevealPreview::SCOPE_EVENT, $revealed[0]['scope']);
        self::assertFalse($revealed[0]['everywhere']);
        self::assertSame($finalReveal->getTimestamp(), $revealed[0]['hiddenElsewhereUntil']?->getTimestamp());
    }

    public function testTheNameComesOutEverywhereWhileAnotherRoundKeepsOnlyThePicture(): void
    {
        // The final keeps only the picture secret (2 days later): the name comes out with this round's reveal
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_03, PuzzleHideMode::ImageOnly);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $finalReveal = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL)->automaticRevealAt();

        $revealed = $this->preview->byChangingRound($round, $round->startsAt, 5);

        self::assertCount(1, $revealed);
        self::assertSame(SecretRevealPreview::SCOPE_NAME_EVERYWHERE, $revealed[0]['scope']);
        self::assertFalse($revealed[0]['everywhere']);
        self::assertSame($finalReveal->getTimestamp(), $revealed[0]['hiddenElsewhereUntil']?->getTimestamp());
        self::assertSame($round->startsAt->modify('+5 minutes')->getTimestamp(), $revealed[0]['revealsAt']?->getTimestamp());
    }

    /**
     * A public catalogue puzzle the round keeps secret on its event pages only: an earlier reveal lets it out on this
     * event - elsewhere it was public all along, so there is no "until" (never "everywhere").
     */
    public function testAPublicPuzzleSecretOnThisEventOnlyComesOutOnThisEventOnly(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03, hidesEverywhere: false);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_04, PuzzleHideMode::ImageOnly, hidesEverywhere: false);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        // Nothing hides them on the rest of the site
        foreach ([PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_04] as $puzzleId) {
            self::assertNull($this->puzzle($puzzleId)->hideUntil);
            self::assertNull($this->puzzle($puzzleId)->hideImageUntil);
        }

        $atTheNewMoment = $this->preview->byChangingRound($round, $round->startsAt, 5);
        $rightAway = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), RoundPuzzleReveal::DEFAULT_DELAY_MINUTES);

        foreach ([$atTheNewMoment, $rightAway] as $revealed) {
            self::assertCount(2, $revealed);

            foreach ($revealed as $item) {
                self::assertSame(SecretRevealPreview::SCOPE_EVENT, $item['scope'], $item['name']);
                self::assertFalse($item['everywhere'], $item['name']);
                self::assertNull($item['hiddenElsewhereUntil'], $item['name']);
            }
        }

        self::assertSame($round->startsAt->modify('+5 minutes')->getTimestamp(), $atTheNewMoment[0]['revealsAt']?->getTimestamp());
        self::assertNull($rightAway[0]['revealsAt']);
        // The yes says how far: a list claiming "everywhere" for them never confirms this one
        $claimedEverywhere = array_map(static fn (array $item): array => ['scope' => SecretRevealPreview::SCOPE_EVERYWHERE, 'everywhere' => true] + $item, $atTheNewMoment);
        self::assertNotSame(SecretRevealPreview::hash($claimedEverywhere), SecretRevealPreview::hash($atTheNewMoment));
    }

    public function testASecretOnThisEventOnlyWhileAnotherRoundHidesItEverywhereSaysUntilWhen(): void
    {
        // The final keeps the puzzle hidden on the whole site; the qualification only on its event pages
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03, hidesEverywhere: false);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $finalReveal = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL)->automaticRevealAt();

        $revealed = $this->preview->byChangingRound($round, $round->startsAt, 5);

        self::assertCount(1, $revealed);
        self::assertSame(SecretRevealPreview::SCOPE_EVENT, $revealed[0]['scope']);
        self::assertSame($finalReveal->getTimestamp(), $revealed[0]['hiddenElsewhereUntil']?->getTimestamp());
    }

    public function testRemovingAPublicPuzzleSecretOnThisEventOnlyRevealsNothing(): void
    {
        // It leaves the event page; elsewhere it was public all along
        $row = $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03, hidesEverywhere: false);
        $row = $this->entityManager->find(CompetitionRoundPuzzle::class, $row);
        self::assertNotNull($row);

        self::assertSame([], $this->preview->byRemoving([$row]));
    }

    public function testScheduledAndManualRowsAreNeverListed(): void
    {
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03, revealMode: RoundPuzzleReveal::Scheduled, scheduledAt: $round->startsAt->modify('+2 hours'));
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_04, revealMode: RoundPuzzleReveal::Manual);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertSame([], $this->preview->byChangingRound($round, $round->startsAt, 0));
        self::assertSame([], $this->preview->byChangingRound($round, new DateTimeImmutable('-1 day'), 0));
    }

    public function testTheHashCoversTheNewMoment(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_04);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $fiveMinutes = $this->preview->byChangingRound($round, $round->startsAt, 5);
        $sixMinutes = $this->preview->byChangingRound($round, $round->startsAt, 6);
        $rightAway = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), 5);

        self::assertCount(2, $fiveMinutes);
        self::assertNotSame(SecretRevealPreview::hash($fiveMinutes), SecretRevealPreview::hash($sixMinutes));
        self::assertNotSame(SecretRevealPreview::hash($fiveMinutes), SecretRevealPreview::hash($rightAway));
        self::assertNotSame(SecretRevealPreview::hash([]), SecretRevealPreview::hash($fiveMinutes));
        // The order of the list does not matter
        self::assertSame(SecretRevealPreview::hash($fiveMinutes), SecretRevealPreview::hash(array_reverse($fiveMinutes)));

        // A yes for 6 minutes never confirms 5, nor a yes for nothing
        self::assertTrue(SecretRevealPreview::refuses($fiveMinutes, false, SecretRevealPreview::hash($sixMinutes)));
        self::assertTrue(SecretRevealPreview::refuses($fiveMinutes, false, SecretRevealPreview::hash([])));
        self::assertFalse(SecretRevealPreview::refuses($fiveMinutes, false, SecretRevealPreview::hash($fiveMinutes)));
        // Refusing any reveal refuses even a confirmed list
        self::assertTrue(SecretRevealPreview::refuses($fiveMinutes, true, SecretRevealPreview::hash($fiveMinutes)));
        self::assertFalse(SecretRevealPreview::refuses([], true, null));
    }

    /**
     * A yes for 25 -> 5 minutes never confirms 60 -> 5 minutes: the same puzzles out at the same moment, but a bigger move
     * than the one the organiser was shown (the delay was lengthened elsewhere meanwhile).
     */
    public function testTheHashCoversTheMomentItMovesFrom(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $start = $round->startsAt;

        $round->changeRevealDelay(25);
        $from25 = $this->preview->byChangingRound($round, $start, 5);
        $round->changeRevealDelay(60);
        $from60 = $this->preview->byChangingRound($round, $start, 5);

        self::assertCount(1, $from25);
        self::assertCount(1, $from60);
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $from25[0]['revealsAt']?->getTimestamp());
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $from60[0]['revealsAt']?->getTimestamp(), 'The same new moment');
        self::assertSame($start->modify('+25 minutes')->getTimestamp(), $from25[0]['previousRevealsAt']?->getTimestamp());
        self::assertSame($start->modify('+60 minutes')->getTimestamp(), $from60[0]['previousRevealsAt']?->getTimestamp());

        self::assertNotSame(SecretRevealPreview::hash($from25), SecretRevealPreview::hash($from60));
        self::assertTrue(SecretRevealPreview::refuses($from60, false, SecretRevealPreview::hash($from25)));

        // Right away too: from where it moves is part of the yes
        $round->changeRevealDelay(25);
        $rightAwayFrom25 = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), 5);
        $round->changeRevealDelay(60);
        $rightAwayFrom60 = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), 5);
        self::assertTrue(SecretRevealPreview::refuses($rightAwayFrom60, false, SecretRevealPreview::hash($rightAwayFrom25)));

        $this->entityManager->clear();
    }

    /**
     * The list the organiser said yes to is empty after the handler's locks (another change already moved the reveal):
     * going ahead lets nothing out earlier, so the yes is not needed - never a refusal.
     */
    public function testNothingLeftToRevealIsNeverRefused(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $confirmed = SecretRevealPreview::hash($this->preview->byChangingRound($round, $round->startsAt, 5));

        self::assertFalse(SecretRevealPreview::refuses([], false, $confirmed), 'A confirmed list that became empty');
        self::assertFalse(SecretRevealPreview::refuses([], false, SecretRevealPreview::hash([])));
        self::assertFalse(SecretRevealPreview::refuses([], false, null));
        // Refusing any reveal: nothing to reveal, nothing refused - with or without a yes
        self::assertFalse(SecretRevealPreview::refuses([], true, null));
        self::assertFalse(SecretRevealPreview::refuses([], true, $confirmed));
    }

    public function testAConfirmationInTheOldFormatIsRefused(): void
    {
        $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $revealed = $this->preview->byChangingRound($round, new DateTimeImmutable('-1 hour'), RoundPuzzleReveal::DEFAULT_DELAY_MINUTES);

        // A page rendered by the previous release (id | everywhere | until) - asks again, never confirms
        $oldHash = hash('sha256', implode(',', [PuzzleFixture::PUZZLE_500_03 . '|everywhere|']));

        self::assertTrue(SecretRevealPreview::refuses($revealed, false, $oldHash));
    }

    public function testRemovalsAreRightAwayAndSayHowFarTheyGo(): void
    {
        $qualification = $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PuzzleFixture::PUZZLE_500_03);
        // The final keeps only the picture, and it reveals earlier than the qualification - without the qualification the
        // name is out at once, the picture stays hidden until the final's moment
        $final = $this->secretRow(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_03, PuzzleHideMode::ImageOnly);
        $finalStart = $this->round(CompetitionRoundFixture::ROUND_WJPC_FINAL)->startsAt;
        $final = $this->entityManager->find(CompetitionRoundPuzzle::class, $final);
        self::assertNotNull($final);
        $final->round->startsAt = new DateTimeImmutable('-1 hour');
        $this->sync(PuzzleFixture::PUZZLE_500_03);

        $row = $this->entityManager->find(CompetitionRoundPuzzle::class, $qualification);
        self::assertNotNull($row);
        $revealed = $this->preview->byRemoving([$row]);

        self::assertCount(1, $revealed);
        self::assertNull($revealed[0]['revealsAt']);
        self::assertNull($revealed[0]['previousRevealsAt'], 'A removal moves no moment - it leaves the round');
        self::assertSame(SecretRevealPreview::SCOPE_EVERYWHERE, $revealed[0]['scope'], 'Nothing hides it any more');

        // The final reveals later again, picture only: the name comes out everywhere, the picture waits for the final
        $final = $this->entityManager->find(CompetitionRoundPuzzle::class, $final->id->toString());
        self::assertNotNull($final);
        $final->round->startsAt = $finalStart;
        $this->sync(PuzzleFixture::PUZZLE_500_03);

        $row = $this->entityManager->find(CompetitionRoundPuzzle::class, $qualification);
        self::assertNotNull($row);
        $revealed = $this->preview->byRemoving([$row]);

        self::assertCount(1, $revealed);
        self::assertSame(SecretRevealPreview::SCOPE_NAME_EVERYWHERE, $revealed[0]['scope']);
        self::assertSame($final->round->automaticRevealAt()->getTimestamp(), $revealed[0]['hiddenElsewhereUntil']?->getTimestamp());
    }

    /**
     * A secret row keeping the puzzle hidden (on the whole site unless said otherwise), its hide re-synced - returns the
     * row id.
     */
    private function secretRow(
        string $roundId,
        string $puzzleId,
        PuzzleHideMode $hideMode = PuzzleHideMode::Entirely,
        RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic,
        null|DateTimeImmutable $scheduledAt = null,
        bool $hidesEverywhere = true,
    ): string {
        $row = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round($roundId),
            puzzle: $this->puzzle($puzzleId),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
            revealMode: $revealMode,
            revealAt: $scheduledAt,
            hidesEverywhere: $hidesEverywhere,
        );
        $this->entityManager->persist($row);
        $this->sync($puzzleId);

        return $row->id->toString();
    }

    private function sync(string $puzzleId): void
    {
        self::getContainer()->get(SecretPuzzleHides::class)->resync($this->puzzle($puzzleId));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function round(string $roundId): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        return $round;
    }

    private function puzzle(string $puzzleId): Puzzle
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        return $puzzle;
    }
}
