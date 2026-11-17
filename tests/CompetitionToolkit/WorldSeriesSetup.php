<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * A World-Series-like event (competition toolkit A6, Jan's example): 6 solo rounds and 6 pair rounds, 60 minutes each,
 * no qualification - the winner per category has the lowest sum. Rounds 1-3 of each category are published.
 *
 * Solo:  Alice 1000 s × 6 = 6000 · Cyril 1100 s × 6 = 6600 · Bob 900 s × 5 + round 6 not finished (450 of 500 pieces)
 *        = 4500 + 3600 (+ penalty × 50) · Dora 800 s × 5, did not start round 4 (incomplete).
 * Pairs: Alice + Bob 2000 s × 6 = 12000 · Cyril + Dora 1800 s in rounds 1-5 = 9000 · in round 6 Dora is replaced by
 *        Eve: Cyril + Eve 1700 s - another line-up, so both are incomplete.
 * Solo round 6 has a 500-piece puzzle (the penalty per missing piece needs the pieces).
 */
trait WorldSeriesSetup
{
    private const string WS_COMPETITION = '018d00a6-0000-0000-0000-000000000001';
    private const string WS_ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    /** @var list<string> */
    private array $soloRounds = [];

    /** @var list<string> */
    private array $pairRounds = [];

    /** @var array<string, string> name => participant id */
    private array $people = [];

    private function createWorldSeries(EntityManagerInterface $entityManager): void
    {
        $organiser = $entityManager->find(Player::class, self::WS_ORGANISER);
        assert($organiser instanceof Player);
        $day = new DateTimeImmutable('2026-10-10 09:00:00');

        $competition = new Competition(
            id: Uuid::fromString(self::WS_COMPETITION),
            name: 'World Series Test',
            slug: 'world-series-test',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Prague',
            locationCountryCode: 'cz',
            dateFrom: $day,
            dateTo: $day,
            tag: null,
            isOnline: false,
            approvedAt: $day->modify('-30 days'),
            addedByPlayer: $organiser,
            createdAt: $day->modify('-30 days'),
        );
        $entityManager->persist($competition);

        $participants = [];
        foreach (['Alice', 'Bob', 'Cyril', 'Dora', 'Eve'] as $index => $name) {
            $participant = new CompetitionParticipant(
                id: Uuid::fromString(sprintf('018d00a6-0000-0000-0000-0000000002%02d', $index + 1)),
                name: $name,
                country: 'cz',
                competition: $competition,
                source: ParticipantSource::Imported,
            );
            $entityManager->persist($participant);
            $participants[$name] = $participant;
            $this->people[$name] = $participant->id->toString();
        }

        $enteredAt = $day->modify('+1 day');

        for ($number = 1; $number <= 6; $number++) {
            $solo = $this->wsRound($entityManager, $competition, sprintf('018d00a6-0000-0000-0000-0000000001%02d', $number), 'Solo ' . $number, $day->modify('+' . $number . ' hours'), RoundCategory::Solo, $number <= 3);
            $this->soloRounds[] = $solo->id->toString();

            if ($number === 6) {
                $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
                assert($puzzle instanceof Puzzle);
                $entityManager->persist(new CompetitionRoundPuzzle(id: Uuid::uuid7(), round: $solo, puzzle: $puzzle));
            }

            $soloResults = [
                'Alice' => RoundEntryResult::finished(1000),
                'Bob' => $number === 6 ? RoundEntryResult::unfinished(450) : RoundEntryResult::finished(900),
                'Cyril' => RoundEntryResult::finished(1100),
                'Dora' => $number === 4 ? RoundEntryResult::didNotStart() : RoundEntryResult::finished(800),
            ];

            foreach ($soloResults as $name => $result) {
                $entry = new CompetitionParticipantRound(Uuid::uuid7(), $participants[$name], $solo);
                $entry->recordResult($result, $organiser, $enteredAt);
                $entityManager->persist($entry);
            }

            $pairs = $this->wsRound($entityManager, $competition, sprintf('018d00a6-0000-0000-0000-0000000003%02d', $number), 'Pairs ' . $number, $day->modify('+' . (10 + $number) . ' hours'), RoundCategory::Duo, $number <= 3);
            $this->pairRounds[] = $pairs->id->toString();

            $this->wsTeam($entityManager, $pairs, 'Alice & Bob', [$participants['Alice'], $participants['Bob']], RoundEntryResult::finished(2000), $organiser, $enteredAt);
            $this->wsTeam(
                $entityManager,
                $pairs,
                'Team Cyril',
                [$participants['Cyril'], $number === 6 ? $participants['Eve'] : $participants['Dora']],
                RoundEntryResult::finished($number === 6 ? 1700 : 1800),
                $organiser,
                $enteredAt,
            );
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function wsRound(EntityManagerInterface $entityManager, Competition $competition, string $id, string $name, DateTimeImmutable $startsAt, RoundCategory $category, bool $published): CompetitionRound
    {
        $round = new CompetitionRound(
            id: Uuid::fromString($id),
            competition: $competition,
            name: $name,
            minutesLimit: 60,
            startsAt: $startsAt,
            category: $category,
            slug: strtolower(str_replace(' ', '-', $name)),
            timezone: 'Europe/Prague',
            resultsPublishedAt: $published ? $startsAt->modify('+2 hours') : null,
            resultsFirstPublishedAt: $published ? $startsAt->modify('+2 hours') : null,
        );
        $entityManager->persist($round);

        return $round;
    }

    /**
     * @param list<CompetitionParticipant> $members
     */
    private function wsTeam(EntityManagerInterface $entityManager, CompetitionRound $round, string $name, array $members, RoundEntryResult $result, Player $organiser, DateTimeImmutable $enteredAt): void
    {
        $team = new CompetitionTeam(Uuid::uuid7(), $round, $name);
        $team->recordResult($result, $organiser, $enteredAt);
        $entityManager->persist($team);

        foreach ($members as $member) {
            $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $member, $round, $team));
        }
    }
}
