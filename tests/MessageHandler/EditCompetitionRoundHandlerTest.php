<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditCompetitionRoundHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testStoresTheChosenTimezone(): void
    {
        $startsAt = new DateTimeImmutable('2026-10-24 15:05:00', new DateTimeZone('UTC'));

        $this->editRound($startsAt, 'America/Chicago');

        $round = $this->round();
        self::assertSame('America/Chicago', $round->timezone);
        self::assertSame('America/Chicago', $round->displayTimezone());
        self::assertSame($startsAt->getTimestamp(), $round->startsAt->getTimestamp());
    }

    public function testAPartialUpdateKeepsWhatItLeavesOutAsTheRoundHasItUnderTheLock(): void
    {
        $round = $this->round();
        $readBefore = $round->startsAt;
        $categoryBefore = $round->category;

        // Another change committed between the read and the save: the round moved
        $movedTo = new DateTimeImmutable('+45 days')->setTime(9, 30);
        $round->startsAt = $movedTo;
        $this->entityManager->flush();
        $this->entityManager->clear();

        // A rename only (the internal API's PATCH) still carrying the start it read before
        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            name: 'Renamed Qualification',
            minutesLimit: 1,
            startsAt: $readBefore,
            timezone: 'America/Chicago',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $categoryBefore,
            resultsLink: null,
            keepFields: ['minutesLimit', 'startsAt', 'timezone', 'badgeBackgroundColor', 'badgeTextColor', 'category', 'resultsLink'],
        ));
        $this->entityManager->clear();

        $round = $this->round();
        self::assertSame('Renamed Qualification', $round->name);
        self::assertSame($movedTo->getTimestamp(), $round->startsAt->getTimestamp());
        self::assertSame(60, $round->minutesLimit);
        self::assertSame('Europe/Prague', $round->displayTimezone());
        self::assertSame('#007bff', $round->badgeBackgroundColor);
    }

    public function testRoundWithoutStoredTimezoneIsReadInItsCountrysZone(): void
    {
        // Rounds saved before the zone was kept: the form pre-selected the country's zone (WJPC 2024 is in Czechia)
        self::assertNull($this->round()->timezone);
        self::assertSame('Europe/Prague', $this->round()->displayTimezone());
    }

    public function testMovingTheRoundMovesOnlyAutomaticReveals(): void
    {
        $round = $this->round();
        $ownMoment = new DateTimeImmutable('+40 days')->setTime(18, 0);

        $this->secretPuzzle($round, PuzzleFixture::PUZZLE_500_03, hidesEverywhere: true);
        $this->secretPuzzle($round, PuzzleFixture::PUZZLE_500_04, hidesEverywhere: true)
            ->changeReveal(PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, $ownMoment);
        $this->secretPuzzle($round, PuzzleFixture::PUZZLE_500_05, hidesEverywhere: true)
            ->changeReveal(PuzzleHideMode::ImageOnly, RoundPuzzleReveal::Manual, null);
        // A catalogue puzzle - public already, the round hides it on its event pages only
        $this->secretPuzzle($round, PuzzleFixture::PUZZLE_1000_03, hidesEverywhere: false);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $newStart = $this->round()->startsAt->modify('+5 hours');
        $this->editRound($newStart, 'America/Chicago');

        $automatic = $newStart->modify('+10 minutes');
        self::assertSame($automatic->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideUntil?->getTimestamp());
        self::assertSame($automatic->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_03)->hideImageUntil?->getTimestamp());

        self::assertSame($ownMoment->getTimestamp(), $this->puzzle(PuzzleFixture::PUZZLE_500_04)->hideUntil?->getTimestamp());

        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideUntil);
        self::assertEquals(
            new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED),
            $this->puzzle(PuzzleFixture::PUZZLE_500_05)->hideImageUntil,
        );

        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_1000_03)->hideUntil);
        self::assertNull($this->puzzle(PuzzleFixture::PUZZLE_1000_03)->hideImageUntil);
    }

    private function secretPuzzle(CompetitionRound $round, string $puzzleId, bool $hidesEverywhere): CompetitionRoundPuzzle
    {
        $puzzle = $this->puzzle($puzzleId);

        $roundPuzzle = new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $round,
            puzzle: $puzzle,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            hidesEverywhere: $hidesEverywhere,
        );
        $this->entityManager->persist($roundPuzzle);

        return $roundPuzzle;
    }

    private function editRound(DateTimeImmutable $startsAt, string $timezone): void
    {
        $round = $this->round();

        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            name: $round->name,
            minutesLimit: $round->minutesLimit,
            startsAt: $startsAt,
            timezone: $timezone,
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
        ));

        $this->entityManager->clear();
    }

    private function round(): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
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
