<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A round's reveal delay through the one write path, the round edit: never an early reveal without a yes for exactly
 * what comes out (the net automatic moment, start + delay, decides), a longer delay hides longer everywhere, and a reveal
 * that happened stays.
 *
 * Every "changes nothing" check flushes first - a refused handler must not leave a change behind for a later flush in
 * the same request - and then reads the round, its rows and the puzzles from the database.
 */
final class EditCompetitionRoundRevealDelayTest extends KernelTestCase
{
    private const string ROUND = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;
    private const string OTHER_ROUND = CompetitionRoundFixture::ROUND_WJPC_FINAL;

    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAShorterDelayWithoutAYesChangesNothing(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $before = $this->snapshot();

        try {
            $this->editDelay(5);
            self::fail('A shorter delay reveals earlier - not without a yes');
        } catch (SecretPuzzlesWouldBeRevealed $refused) {
            self::assertSame([PuzzleFixture::PUZZLE_500_03], array_column($refused->puzzles, 'id'));
            self::assertSame($this->round()->startsAt->modify('+5 minutes')->getTimestamp(), $refused->puzzles[0]['revealsAt']?->getTimestamp());
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame(RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, $this->round()->revealDelayMinutes);
    }

    public function testAStaleConfirmationChangesNothing(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round();
        $yesForSix = SecretRevealPreview::hash($this->preview()->byChangingRound($round, $round->startsAt, 6));
        $before = $this->snapshot();

        foreach ([$yesForSix, SecretRevealPreview::hash([])] as $staleHash) {
            try {
                $this->editDelay(5, refuseToReveal: false, confirmedRevealHash: $staleHash);
                self::fail('A yes for another list never confirms this one');
            } catch (SecretPuzzlesWouldBeRevealed) {
            }

            self::assertSame($before, $this->snapshot());
        }
    }

    /**
     * A yes for 25 -> 5 minutes never confirms 60 -> 5: the same puzzle out at the same moment, but moved from another
     * one (the delay was lengthened meanwhile) - refused after the locks, nothing changed.
     */
    public function testAConfirmationForAMoveFromAnotherMomentChangesNothing(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $this->editDelay(25);
        $round = $this->round();
        $start = $round->startsAt;
        $yesFrom25 = SecretRevealPreview::hash($this->preview()->byChangingRound($round, $start, 5));

        // Meanwhile the delay becomes 60 - a longer one needs no yes
        $this->editDelay(60);
        $before = $this->snapshot();

        try {
            $this->editDelay(5, refuseToReveal: false, confirmedRevealHash: $yesFrom25);
            self::fail('A yes for a move from 25 minutes never confirms one from 60');
        } catch (SecretPuzzlesWouldBeRevealed $refused) {
            self::assertSame($start->modify('+5 minutes')->getTimestamp(), $refused->puzzles[0]['revealsAt']?->getTimestamp());
            self::assertSame($start->modify('+60 minutes')->getTimestamp(), $refused->puzzles[0]['previousRevealsAt']?->getTimestamp());
            $previousInTheAnswer = $refused->toArray()[0]['previousRevealsAt'];
            self::assertIsString($previousInTheAnswer);
            self::assertSame($start->modify('+60 minutes')->getTimestamp(), new DateTimeImmutable($previousInTheAnswer)->getTimestamp());
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame(60, $this->round()->revealDelayMinutes);
        self::assertSame($start->modify('+60 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
    }

    public function testAConfirmationForAListThatBecameEmptyGoesAhead(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round();
        $ownMoment = $round->startsAt->modify('+2 hours');
        $yes = SecretRevealPreview::hash($this->preview()->byChangingRound($round, $round->startsAt, 5));

        // Meanwhile the puzzle got its own reveal time: the shorter delay lets nothing out earlier any more
        $row = $this->rowOf(PuzzleFixture::PUZZLE_500_03);
        $row->changeReveal(PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, $ownMoment);
        $this->entityManager->flush();
        $this->resync(PuzzleFixture::PUZZLE_500_03);

        $this->editDelay(5, refuseToReveal: false, confirmedRevealHash: $yes);

        self::assertSame(5, $this->round()->revealDelayMinutes);
        self::assertSame($ownMoment->getTimestamp(), $this->rowOf(PuzzleFixture::PUZZLE_500_03)->revealsAt()?->getTimestamp());
        self::assertSame($ownMoment->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
    }

    public function testAConfirmedShorterDelayRevealsEarlier(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $round = $this->round();
        $start = $round->startsAt;
        $yes = SecretRevealPreview::hash($this->preview()->byChangingRound($round, $start, 5));

        $this->editDelay(5, refuseToReveal: false, confirmedRevealHash: $yes);

        self::assertSame(5, $this->round()->revealDelayMinutes);
        self::assertSame(RoundPuzzleReveal::Automatic, $this->rowOf(PuzzleFixture::PUZZLE_500_03)->revealMode);
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideImageUntil?->getTimestamp());
    }

    public function testAShorterDelayIntoThePastIsRightAway(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        // The round started 7 minutes ago: the puzzle comes out in 3 minutes
        $start = self::wholeMinute(new DateTimeImmutable('-7 minutes'));
        $this->moveRoundStart(self::ROUND, $start);
        $before = $this->snapshot();

        try {
            $this->editDelay(5);
            self::fail('Out right away - not without a yes');
        } catch (SecretPuzzlesWouldBeRevealed $refused) {
            self::assertNull($refused->puzzles[0]['revealsAt'], 'Right away');
            self::assertTrue($refused->toArray()[0]['rightAway']);
        }

        self::assertSame($before, $this->snapshot());

        $yes = SecretRevealPreview::hash($this->preview()->byChangingRound($this->round(), $start, 5));
        $this->editDelay(5, refuseToReveal: false, confirmedRevealHash: $yes);

        $puzzle = $this->puzzle(PuzzleFixture::PUZZLE_500_03);
        self::assertSame($start->modify('+5 minutes')->getTimestamp(), $puzzle->hideUntil?->getTimestamp());
        self::assertFalse($puzzle->isImageHiddenAt(new DateTimeImmutable()));
    }

    public function testAnEarlierStartStillInTheFutureNeedsAYes(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $before = $this->snapshot();
        $earlier = $this->round()->startsAt->modify('-1 day');

        try {
            $this->editStart($earlier, delay: null);
            self::fail('An earlier start reveals earlier - not without a yes');
        } catch (SecretPuzzlesWouldBeRevealed $refused) {
            self::assertSame($earlier->modify('+10 minutes')->getTimestamp(), $refused->puzzles[0]['revealsAt']?->getTimestamp());
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testAnEarlierStartWithALongerDelayKeepingTheMomentNeedsNoYes(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $start = $this->round()->startsAt;
        $hiddenUntil = $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil;

        $this->editStart($start->modify('-15 minutes'), delay: 25);

        $round = $this->round();
        self::assertSame($start->modify('-15 minutes')->getTimestamp(), $round->startsAt->getTimestamp());
        self::assertSame(25, $round->revealDelayMinutes);
        self::assertSame($hiddenUntil?->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $hiddenUntil?->getTimestamp());
    }

    public function testALongerDelayHidesThePuzzleLongerEverywhere(): void
    {
        $start = $this->round()->startsAt;
        $ownMoment = $start->modify('+2 hours');

        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_04, revealMode: RoundPuzzleReveal::Scheduled, scheduledAt: $ownMoment);
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_05, PuzzleHideMode::ImageOnly, RoundPuzzleReveal::Manual);
        // A catalogue puzzle - public already, the round hides it on its event pages only
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_1000_03, hidesEverywhere: false);

        // A later moment needs no yes
        $this->editDelay(45);

        self::assertSame(45, $this->round()->revealDelayMinutes);
        self::assertSame(RoundPuzzleReveal::Automatic, $this->rowOf(PuzzleFixture::PUZZLE_500_03)->revealMode);
        self::assertSame($start->modify('+45 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+45 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideImageUntil?->getTimestamp());

        self::assertSame(RoundPuzzleReveal::Scheduled, $this->rowOf(PuzzleFixture::PUZZLE_500_04)->revealMode);
        self::assertSame($ownMoment->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil?->getTimestamp());

        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideUntil);
        self::assertEquals(new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED), $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideImageUntil);

        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_1000_03)->hideUntil);
        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_1000_03)->hideImageUntil);
    }

    public function testARevealedPuzzleStaysPinnedWhenTheDelayGrows(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        // The round started 30 minutes ago: revealed 20 minutes ago
        $start = self::wholeMinute(new DateTimeImmutable('-30 minutes'));
        $this->moveRoundStart(self::ROUND, $start);
        $revealedAt = $start->modify('+10 minutes');
        self::assertSame($revealedAt->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());

        // 60 minutes would hide it again for half an hour - it stays out where it came out
        $this->editDelay(60);

        self::assertSame(60, $this->round()->revealDelayMinutes);
        $row = $this->rowOf(PuzzleFixture::PUZZLE_500_03);
        self::assertSame(RoundPuzzleReveal::Scheduled, $row->revealMode);
        self::assertSame($revealedAt->getTimestamp(), $row->revealsAt()?->getTimestamp());
        self::assertSame($revealedAt->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertFalse($this->puzzle(PuzzleFixture::PUZZLE_500_03)->isImageHiddenAt(new DateTimeImmutable()));
    }

    public function testANullDelayKeepsTheRoundsValue(): void
    {
        $this->editDelay(25);
        self::assertSame(25, $this->round()->revealDelayMinutes);

        // A caller that does not set it (a rename, an older form) keeps it
        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: self::ROUND,
            name: 'Renamed Qualification',
            minutesLimit: 60,
            startsAt: $this->round()->startsAt,
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        ));
        $this->entityManager->clear();

        self::assertSame('Renamed Qualification', $this->round()->name);
        self::assertSame(25, $this->round()->revealDelayMinutes);
    }

    public function testTheDelayIsBounded(): void
    {
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $before = $this->snapshot();

        foreach ([-1, RoundPuzzleReveal::MAX_DELAY_MINUTES + 1] as $invalid) {
            try {
                $this->editDelay($invalid, refuseToReveal: false);
                self::fail(sprintf('%d minutes is no reveal delay', $invalid));
            } catch (HandlerFailedException $failed) {
                self::assertInstanceOf(\InvalidArgumentException::class, $failed->getPrevious());
            }

            self::assertSame($before, $this->snapshot());
        }

        $round = $this->round();

        foreach ([-1, RoundPuzzleReveal::MAX_DELAY_MINUTES + 1] as $invalid) {
            try {
                $round->changeRevealDelay($invalid);
                self::fail(sprintf('%d minutes is no reveal delay', $invalid));
            } catch (\InvalidArgumentException) {
            }
        }

        $round->changeRevealDelay(0);
        $round->changeRevealDelay(RoundPuzzleReveal::MAX_DELAY_MINUTES);
        self::assertSame(RoundPuzzleReveal::MAX_DELAY_MINUTES, $round->revealDelayMinutes);
    }

    /**
     * One puzzle secret in two rounds: the site-wide hide is the latest reveal of both, never an earlier one.
     */
    public function testLengtheningTheEarlierRoundPastTheLaterOneRaisesTheSiteWideHide(): void
    {
        $start = $this->round()->startsAt;
        // The other round reveals an hour later (start + 1 hour + its 10 minutes)
        $this->moveRoundStart(self::OTHER_ROUND, $start->modify('+1 hour'));
        $otherReveal = $start->modify('+70 minutes');

        // Entirely in both rounds
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_03);
        // Only the picture in the other round
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_04);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_04, PuzzleHideMode::ImageOnly);
        // A manual reveal in the other round - never before "Reveal now"
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_05);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_05, revealMode: RoundPuzzleReveal::Manual);

        self::assertSame($otherReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil?->getTimestamp());
        self::assertSame($otherReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideImageUntil?->getTimestamp());

        // Two hours: past the other round's reveal
        $this->editDelay(120);
        $newReveal = $start->modify('+120 minutes');

        self::assertSame($newReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($newReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideImageUntil?->getTimestamp());
        self::assertSame($newReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil?->getTimestamp());
        self::assertSame($newReveal->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideImageUntil?->getTimestamp());

        $never = new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED);
        self::assertEquals($never, $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideUntil);
        self::assertEquals($never, $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideImageUntil);
    }

    public function testShorteningTheLaterRoundBelowTheEarlierOneIsListedAsFarAsItGoes(): void
    {
        $start = $this->round()->startsAt;
        // The other round starts 5 minutes after this one: it reveals at start + 15, this one at start + 10
        $this->moveRoundStart(self::OTHER_ROUND, $start->modify('+5 minutes'));

        // Entirely in both: the other round's reveal moved before this one's - out on its event only, elsewhere this
        // round keeps it until start + 10
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_03);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_03);
        // This round keeps only the picture: the name comes out everywhere, the picture waits for start + 10
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_04, PuzzleHideMode::ImageOnly);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_04);
        // This round reveals by hand
        $this->secretRow(self::ROUND, PuzzleFixture::PUZZLE_500_05, revealMode: RoundPuzzleReveal::Manual);
        $this->secretRow(self::OTHER_ROUND, PuzzleFixture::PUZZLE_500_05);
        $before = $this->snapshot();

        try {
            $this->editDelay(0, roundId: self::OTHER_ROUND);
            self::fail('Earlier on the other round\'s event - not without a yes');
        } catch (SecretPuzzlesWouldBeRevealed $refused) {
            $revealed = array_column($refused->puzzles, null, 'id');
        }

        self::assertSame($before, $this->snapshot());
        self::assertCount(3, $revealed);
        $outAt = $start->modify('+5 minutes')->getTimestamp();
        $never = new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED);

        self::assertSame($outAt, $revealed[PuzzleFixture::PUZZLE_500_03]['revealsAt']?->getTimestamp());
        self::assertSame(SecretRevealPreview::SCOPE_EVENT, $revealed[PuzzleFixture::PUZZLE_500_03]['scope']);
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $revealed[PuzzleFixture::PUZZLE_500_03]['hiddenElsewhereUntil']?->getTimestamp());

        self::assertSame(SecretRevealPreview::SCOPE_NAME_EVERYWHERE, $revealed[PuzzleFixture::PUZZLE_500_04]['scope']);
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $revealed[PuzzleFixture::PUZZLE_500_04]['hiddenElsewhereUntil']?->getTimestamp());

        self::assertSame(SecretRevealPreview::SCOPE_EVENT, $revealed[PuzzleFixture::PUZZLE_500_05]['scope']);
        self::assertEquals($never, $revealed[PuzzleFixture::PUZZLE_500_05]['hiddenElsewhereUntil']);

        // Said yes to exactly that
        $this->editDelay(0, roundId: self::OTHER_ROUND, refuseToReveal: false, confirmedRevealHash: SecretRevealPreview::hash($refused->puzzles));

        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideImageUntil?->getTimestamp());
        self::assertSame($outAt, $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil?->getTimestamp());
        self::assertSame($start->modify('+10 minutes')->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideImageUntil?->getTimestamp());
        self::assertEquals($never, $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideUntil);
        self::assertEquals($never, $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideImageUntil);
    }

    private function editDelay(
        null|int $delay,
        bool $refuseToReveal = true,
        null|string $confirmedRevealHash = null,
        string $roundId = self::ROUND,
    ): void {
        // Everything else as the round has it under the handler's lock
        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            name: 'kept',
            minutesLimit: 1,
            startsAt: new DateTimeImmutable(),
            timezone: 'UTC',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            refuseToReveal: $refuseToReveal,
            confirmedRevealHash: $confirmedRevealHash,
            keepFields: EditCompetitionRound::FIELDS,
            revealDelayMinutes: $delay,
        ));
        $this->entityManager->clear();
    }

    private function editStart(DateTimeImmutable $startsAt, null|int $delay): void
    {
        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: self::ROUND,
            name: 'kept',
            minutesLimit: 1,
            startsAt: $startsAt,
            timezone: 'UTC',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            keepFields: array_values(array_diff(EditCompetitionRound::FIELDS, ['startsAt'])),
            revealDelayMinutes: $delay,
        ));
        $this->entityManager->clear();
    }

    /**
     * A secret row keeping the puzzle hidden (on the whole site unless said otherwise), the hide re-synced.
     */
    private function secretRow(
        string $roundId,
        string $puzzleId,
        PuzzleHideMode $hideMode = PuzzleHideMode::Entirely,
        RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic,
        null|DateTimeImmutable $scheduledAt = null,
        bool $hidesEverywhere = true,
    ): void {
        $this->entityManager->persist(new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $this->round($roundId),
            puzzle: $this->puzzle($puzzleId),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
            revealMode: $revealMode,
            revealAt: $scheduledAt,
            hidesEverywhere: $hidesEverywhere,
        ));
        $this->resync($puzzleId);
    }

    private function moveRoundStart(string $roundId, DateTimeImmutable $startsAt): void
    {
        $round = $this->round($roundId);
        $round->startsAt = $startsAt;
        $puzzleIds = array_map(static fn (CompetitionRoundPuzzle $row): string => $row->puzzle->id->toString(), $round->roundPuzzles->toArray());
        $this->entityManager->flush();

        foreach ($puzzleIds as $puzzleId) {
            $this->resync($puzzleId);
        }

        $this->entityManager->clear();
    }

    private function resync(string $puzzleId): void
    {
        self::getContainer()->get(SecretPuzzleHides::class)->resync($this->puzzle($puzzleId));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * What the database holds for the rounds, their rows and the puzzles - after a flush, so a change a refused handler
     * left in the entity manager would show.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $connection = $this->entityManager->getConnection();

        return [
            'rounds' => $connection->fetchAllAssociative(
                'SELECT id, name, starts_at, reveal_delay_minutes, minutes_limit FROM competition_round WHERE id IN (:a, :b) ORDER BY id',
                ['a' => self::ROUND, 'b' => self::OTHER_ROUND],
            ),
            'rows' => $connection->fetchAllAssociative(
                'SELECT id, reveal_mode, reveal_at, hide_mode, hides_everywhere FROM competition_round_puzzle WHERE round_id IN (:a, :b) ORDER BY id',
                ['a' => self::ROUND, 'b' => self::OTHER_ROUND],
            ),
            'puzzles' => $connection->fetchAllAssociative(
                'SELECT id, hide_until, hide_image_until FROM puzzle WHERE id IN (:p1, :p2, :p3, :p4) ORDER BY id',
                ['p1' => PuzzleFixture::PUZZLE_500_03, 'p2' => PuzzleFixture::PUZZLE_500_04, 'p3' => PuzzleFixture::PUZZLE_500_05, 'p4' => PuzzleFixture::PUZZLE_1000_03],
            ),
        ];
    }

    private static function wholeMinute(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTime((int) $moment->format('H'), (int) $moment->format('i'));
    }

    private function preview(): SecretRevealPreview
    {
        return self::getContainer()->get(SecretRevealPreview::class);
    }

    private function rowOf(string $puzzleId, string $roundId = self::ROUND): CompetitionRoundPuzzle
    {
        $row = $this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findOneBy(['round' => $roundId, 'puzzle' => $puzzleId]);
        self::assertInstanceOf(CompetitionRoundPuzzle::class, $row);

        return $row;
    }

    private function round(string $roundId = self::ROUND): CompetitionRound
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
