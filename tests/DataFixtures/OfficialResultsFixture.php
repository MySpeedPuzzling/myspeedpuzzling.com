<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * "Results Cup" - a past in-person event with official results recorded by its organiser (PLAYER_WITH_STRIPE),
 * docs/features/competitions-management/official-results.md. Its own competition, so no other fixture's rounds change.
 * See .claude/fixtures.md "Official results".
 */
final class OfficialResultsFixture extends Fixture implements DependentFixtureInterface
{
    public const string COMPETITION_RESULTS_CUP = '018d0020-0000-0000-0000-000000000001';

    public const string ROUND_GROUP_A = '018d0020-0000-0000-0000-000000000101';
    public const string ROUND_GROUP_B = '018d0020-0000-0000-0000-000000000102';
    public const string ROUND_FINAL = '018d0020-0000-0000-0000-000000000103';
    public const string ROUND_PAIRS = '018d0020-0000-0000-0000-000000000104';
    public const string ROUND_PAIRS_FINAL = '018d0020-0000-0000-0000-000000000105';

    public const string PARTICIPANT_ANNA = '018d0020-0000-0000-0000-000000000201';
    public const string PARTICIPANT_BEN = '018d0020-0000-0000-0000-000000000202';
    public const string PARTICIPANT_CARA = '018d0020-0000-0000-0000-000000000203';
    public const string PARTICIPANT_DAN = '018d0020-0000-0000-0000-000000000204';
    public const string PARTICIPANT_EVA = '018d0020-0000-0000-0000-000000000205';
    public const string PARTICIPANT_FILIP = '018d0020-0000-0000-0000-000000000206';
    public const string PARTICIPANT_GINA = '018d0020-0000-0000-0000-000000000207';
    public const string PARTICIPANT_HUGO = '018d0020-0000-0000-0000-000000000208';
    public const string PARTICIPANT_IVAN = '018d0020-0000-0000-0000-000000000209';

    // Round entries of the solo rounds (CompetitionParticipantRound)
    public const string ENTRY_A_ANNA = '018d0020-0000-0000-0000-000000000301';
    public const string ENTRY_A_BEN = '018d0020-0000-0000-0000-000000000302';
    public const string ENTRY_A_CARA = '018d0020-0000-0000-0000-000000000303';
    public const string ENTRY_A_DAN = '018d0020-0000-0000-0000-000000000304';
    public const string ENTRY_A_EVA = '018d0020-0000-0000-0000-000000000305';
    public const string ENTRY_A_FILIP = '018d0020-0000-0000-0000-000000000306';
    public const string ENTRY_B_GINA = '018d0020-0000-0000-0000-000000000307';
    public const string ENTRY_B_HUGO = '018d0020-0000-0000-0000-000000000308';
    public const string ENTRY_B_IVAN = '018d0020-0000-0000-0000-000000000309';
    public const string ENTRY_FINAL_ANNA = '018d0020-0000-0000-0000-000000000310';

    // Pairs of ROUND_PAIRS (CompetitionTeam)
    public const string TEAM_SHARKS = '018d0020-0000-0000-0000-000000000401';
    public const string TEAM_CORNERS = '018d0020-0000-0000-0000-000000000402';
    public const string TEAM_UNNAMED = '018d0020-0000-0000-0000-000000000403';
    public const string TEAM_EDGES = '018d0020-0000-0000-0000-000000000404';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $organiser = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $privatePlayer = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        $day = $this->clock->now()->modify('-10 days')->setTime(9, 0);

        $competition = new Competition(
            id: Uuid::fromString(self::COMPETITION_RESULTS_CUP),
            name: 'Results Cup',
            slug: 'results-cup',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Brno',
            locationCountryCode: 'cz',
            dateFrom: $day,
            dateTo: $day,
            tag: null,
            isOnline: false,
            approvedAt: $day->modify('-30 days'),
            addedByPlayer: $organiser,
            createdAt: $day->modify('-30 days'),
        );
        $manager->persist($competition);
        $this->addReference(self::COMPETITION_RESULTS_CUP, $competition);

        $groupA = $this->round($manager, self::ROUND_GROUP_A, $competition, 'Group A', 'group-a', $day, RoundCategory::Solo, PuzzleFixture::PUZZLE_1000_05, published: true);
        $groupB = $this->round($manager, self::ROUND_GROUP_B, $competition, 'Group B', 'group-b', $day->modify('+2 hours'), RoundCategory::Solo, PuzzleFixture::PUZZLE_5000);
        $final = $this->round($manager, self::ROUND_FINAL, $competition, 'Final', 'final', $day->modify('+6 hours'), RoundCategory::Solo, PuzzleFixture::PUZZLE_6000);
        $pairs = $this->round($manager, self::ROUND_PAIRS, $competition, 'Pairs', 'pairs', $day->modify('+4 hours'), RoundCategory::Duo, PuzzleFixture::PUZZLE_2000);
        $this->round($manager, self::ROUND_PAIRS_FINAL, $competition, 'Pairs Final', 'pairs-final', $day->modify('+8 hours'), RoundCategory::Duo, null);

        $anna = $this->participant($manager, self::PARTICIPANT_ANNA, 'Anna Fast', 'cz', $competition, $adminPlayer);
        $ben = $this->participant($manager, self::PARTICIPANT_BEN, 'Ben Steady', 'de', $competition);
        $cara = $this->participant($manager, self::PARTICIPANT_CARA, 'Cara Tied', 'us', $competition);
        $dan = $this->participant($manager, self::PARTICIPANT_DAN, 'Dan Unfinished', 'cz', $competition);
        $eva = $this->participant($manager, self::PARTICIPANT_EVA, 'Eva Noshow', 'sk', $competition);
        $filip = $this->participant($manager, self::PARTICIPANT_FILIP, 'Filip Pending', 'cz', $competition);
        $gina = $this->participant($manager, self::PARTICIPANT_GINA, 'Gina Quick', 'us', $competition, $privatePlayer);
        $hugo = $this->participant($manager, self::PARTICIPANT_HUGO, 'Hugo Slow', 'de', $competition, $regularPlayer);
        $ivan = $this->participant($manager, self::PARTICIPANT_IVAN, 'Ivan Last', 'sk', $competition);

        $enteredAt = $day->modify('+1 hour');

        // Group A: a winner, a tie for second, an unfinished result, a no-show, one without a result yet
        $this->entry($manager, self::ENTRY_A_ANNA, $anna, $groupA, RoundEntryResult::finished(3600), 1, true, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_A_BEN, $ben, $groupA, RoundEntryResult::finished(4200), 2, true, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_A_CARA, $cara, $groupA, RoundEntryResult::finished(4200), 3, false, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_A_DAN, $dan, $groupA, RoundEntryResult::unfinished(850), 4, false, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_A_EVA, $eva, $groupA, RoundEntryResult::didNotStart(), 5, false, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_A_FILIP, $filip, $groupA, RoundEntryResult::none(), null, false, null, null);

        // Group B: not published
        $this->entry($manager, self::ENTRY_B_GINA, $gina, $groupB, RoundEntryResult::finished(3900), 1, true, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_B_HUGO, $hugo, $groupB, RoundEntryResult::finished(4500), 2, true, $organiser, $enteredAt);
        $this->entry($manager, self::ENTRY_B_IVAN, $ivan, $groupB, RoundEntryResult::finished(5000), 3, false, $organiser, $enteredAt);

        // Final: Anna is in already (advanced by hand), no result yet
        $this->entry($manager, self::ENTRY_FINAL_ANNA, $anna, $final, RoundEntryResult::none(), null, false, null, null);

        // Pairs: two finished (qualified), one unfinished, one unnamed pair without a result
        $this->team($manager, self::TEAM_SHARKS, 'Puzzle Sharks', $pairs, [$anna, $ben], RoundEntryResult::finished(5400), 1, true, $organiser, $enteredAt);
        $this->team($manager, self::TEAM_CORNERS, 'Corner Pieces', $pairs, [$cara, $dan], RoundEntryResult::unfinished(1700), 2, false, $organiser, $enteredAt);
        $this->team($manager, self::TEAM_UNNAMED, null, $pairs, [$eva, $filip], RoundEntryResult::none(), null, false, null, null);
        $this->team($manager, self::TEAM_EDGES, 'Edge Hunters', $pairs, [$gina, $hugo], RoundEntryResult::finished(6000), 3, true, $organiser, $enteredAt);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
        ];
    }

    private function round(
        ObjectManager $manager,
        string $id,
        Competition $competition,
        string $name,
        string $slug,
        DateTimeImmutable $startsAt,
        RoundCategory $category,
        null|string $puzzleId,
        bool $published = false,
    ): CompetitionRound {
        $round = new CompetitionRound(
            id: Uuid::fromString($id),
            competition: $competition,
            name: $name,
            minutesLimit: 90,
            startsAt: $startsAt,
            category: $category,
            slug: $slug,
            timezone: 'Europe/Prague',
            // Published straight away, as if the organiser did it right after the round - no notification is sent
            resultsPublishedAt: $published ? $startsAt->modify('+2 hours') : null,
            resultsFirstPublishedAt: $published ? $startsAt->modify('+2 hours') : null,
        );
        $manager->persist($round);
        $this->addReference($id, $round);

        if ($puzzleId !== null) {
            $manager->persist(new CompetitionRoundPuzzle(
                id: Uuid::uuid7(),
                round: $round,
                puzzle: $this->getReference($puzzleId, Puzzle::class),
            ));
        }

        return $round;
    }

    private function participant(ObjectManager $manager, string $id, string $name, string $country, Competition $competition, null|Player $player = null): CompetitionParticipant
    {
        $participant = new CompetitionParticipant(
            id: Uuid::fromString($id),
            name: $name,
            country: $country,
            competition: $competition,
            source: ParticipantSource::Imported,
        );

        if ($player !== null) {
            $participant->connect($player, $this->clock->now()->modify('-20 days'));
        }

        $manager->persist($participant);
        $this->addReference($id, $participant);

        return $participant;
    }

    private function entry(
        ObjectManager $manager,
        string $id,
        CompetitionParticipant $participant,
        CompetitionRound $round,
        RoundEntryResult $result,
        null|int $tableNumber,
        bool $qualified,
        null|Player $enteredBy,
        null|DateTimeImmutable $enteredAt,
    ): void {
        $entry = new CompetitionParticipantRound(Uuid::fromString($id), $participant, $round);

        if ($enteredAt !== null) {
            $entry->recordResult($result, $enteredBy, $enteredAt);
        }

        $entry->assignTableNumber($tableNumber);

        if ($qualified && $enteredAt !== null) {
            $entry->markQualified($enteredAt);
        }

        $manager->persist($entry);
        $this->addReference($id, $entry);
    }

    /**
     * @param list<CompetitionParticipant> $members
     */
    private function team(
        ObjectManager $manager,
        string $id,
        null|string $name,
        CompetitionRound $round,
        array $members,
        RoundEntryResult $result,
        null|int $tableNumber,
        bool $qualified,
        null|Player $enteredBy,
        null|DateTimeImmutable $enteredAt,
    ): void {
        $team = new CompetitionTeam(Uuid::fromString($id), $round, $name);

        if ($enteredAt !== null) {
            $team->recordResult($result, $enteredBy, $enteredAt);
        }

        $team->assignTableNumber($tableNumber);

        if ($qualified && $enteredAt !== null) {
            $team->markQualified($enteredAt);
        }

        $manager->persist($team);
        $this->addReference($id, $team);

        foreach ($members as $member) {
            $manager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $member, $round, $team));
        }
    }
}
