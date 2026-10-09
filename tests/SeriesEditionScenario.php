<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddApprovedPuzzle;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds made-up series, editions, rounds, puzzles and series picks inside a test (docs/features/events-page/
 * high-frequency-series.md P1 - no fixture rows, DAMA rolls everything back). Everything goes through the messages the
 * app uses; only what no message does is done on the entities (a secret round puzzle on a past round). Never a raw SQL
 * write to puzzle_solving_time. The entity manager is cleared after every step, so a handler never works on an entity
 * a reconcile changed in SQL.
 *
 * Made-up names only - the repository is public ("Lantern Weekly Jam", "Jam No. 153", "Copper Lighthouse").
 */
readonly final class SeriesEditionScenario
{
    public const string ADMIN_PLAYER_ID = PlayerFixture::PLAYER_ADMIN;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private ObjectManager $entityManager;

    public function __construct(ContainerInterface $container)
    {
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->messageBus = $container->get(MessageBusInterface::class);
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->database = $container->get(Connection::class);
        // The public registry: the EntityManagerInterface service is private
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    /**
     * @param bool $public approved (else waiting for approval)
     */
    public function series(string $name = 'Lantern Weekly Jam', bool $online = true, bool $public = true, bool $draft = false): string
    {
        $seriesId = Uuid::uuid7();

        $this->dispatch(new AddCompetitionSeries(
            seriesId: $seriesId,
            playerId: self::ADMIN_PLAYER_ID,
            name: $name,
            shortcut: null,
            description: null,
            link: null,
            isOnline: $online,
            location: $online ? null : 'Harbor Town',
            locationCountryCode: $online ? null : 'cz',
            logo: null,
            maintainerIds: [],
            // Unique in every test, whatever the name
            slug: 'hfs-' . substr(str_replace('-', '', $seriesId->toString()), -12),
            isDraft: $draft,
            notifyAdmin: false,
        ));

        if ($public) {
            $this->dispatch(new ApproveCompetitionSeries(
                seriesId: $seriesId->toString(),
                approvedByPlayerId: self::ADMIN_PLAYER_ID,
                notifyCreator: false,
            ));
        }

        return $seriesId->toString();
    }

    /**
     * @param null|string $day Y-m-d - the edition is dated that one day; null = undated
     */
    public function edition(string $seriesId, string $name, null|string $day, bool $draft = false): string
    {
        $competitionId = Uuid::uuid7();
        $date = $day !== null ? new DateTimeImmutable($day) : null;

        $this->dispatch(new AddEdition(
            competitionId: $competitionId,
            seriesId: $seriesId,
            name: $name,
            dateFrom: $date,
            dateTo: $date,
            registrationLink: null,
            resultsLink: null,
            isDraft: $draft,
            slug: 'edition-' . substr(str_replace('-', '', $competitionId->toString()), -12),
        ));

        return $competitionId->toString();
    }

    /**
     * @param string $startsAtLocal Y-m-d H:i in $timezone
     * @param list<string> $puzzleIds
     * @param bool $secret its puzzles stay secret on the event pages until "Reveal now" (a manual reveal) - a public
     *     catalogue puzzle the round keeps secret
     */
    public function round(
        string $editionId,
        RoundCategory $category,
        string $startsAtLocal,
        string $timezone = 'Europe/Berlin',
        array $puzzleIds = [],
        bool $secret = false,
    ): string {
        $roundId = Uuid::uuid7();

        $this->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $editionId,
            name: ucfirst($category->value) . ' ' . substr($startsAtLocal, 0, 10),
            minutesLimit: 120,
            startsAt: RoundTimezone::toInstant($startsAtLocal, $timezone),
            timezone: $timezone,
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $category,
        ));

        if ($puzzleIds !== []) {
            $this->dispatch(new SetCompetitionRoundPuzzles(
                roundId: $roundId->toString(),
                puzzleIds: array_map('strtolower', $puzzleIds),
            ));
        }

        if ($secret) {
            // No message makes a puzzle of a past round secret (the organiser decides before the round starts) - the
            // entity's own method, flushed: it records CompetitionRoundsChanged like the message would
            foreach ($this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findBy(['round' => $roundId->toString()]) as $roundPuzzle) {
                $roundPuzzle->changeReveal(PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return $roundId->toString();
    }

    /**
     * Approved, without a photo of its box (AddApprovedPuzzle)
     */
    public function puzzle(string $name = 'Copper Lighthouse', int $pieces = 500): string
    {
        $puzzleId = Uuid::uuid7();

        $this->dispatch(new AddApprovedPuzzle(
            puzzleId: $puzzleId,
            reviewerId: self::ADMIN_PLAYER_ID,
            name: $name,
            brand: 'Lantern Puzzle Works',
            piecesCount: $pieces,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        ));

        return $puzzleId->toString();
    }

    /**
     * @param string $day Y-m-d - the solve day (finished at midnight)
     * @param list<string> $groupPlayers co-puzzlers (guest names or player ids): 1 = a pair, 2+ = a team
     */
    public function addTime(
        string $userId,
        string $puzzleId,
        string $day,
        null|string $seriesId = null,
        null|string $competitionId = null,
        array $groupPlayers = [],
        string $time = '01:05:00',
    ): string {
        $timeId = Uuid::uuid7();

        $this->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable($day),
            firstAttempt: false,
            unboxed: false,
            seriesId: $seriesId,
        ));

        return $timeId->toString();
    }

    /**
     * The 15-minute cron: every series' picks, then every round
     */
    public function reconcile(): void
    {
        $this->dispatch(new ReconcileRoundResults());
    }

    /**
     * The stored link as it is now - read again on every call
     *
     * @phpstan-impure
     * @return array{competition_id: ?string, competition_series_id: ?string, series_edition_match: ?string, competition_round_id: ?string}
     */
    public function link(string $timeId): array
    {
        /** @var false|array{competition_id: ?string, competition_series_id: ?string, series_edition_match: ?string, competition_round_id: ?string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT competition_id, competition_series_id, series_edition_match, competition_round_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        if ($row === false) {
            throw new \LogicException(sprintf('No solving time %s.', $timeId));
        }

        return $row;
    }

    /**
     * The round puzzle row of a puzzle in a round - for the reveal messages
     */
    public function roundPuzzleId(string $roundId, string $puzzleId): string
    {
        $id = $this->database->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id = :puzzle',
            ['round' => $roundId, 'puzzle' => $puzzleId],
        );

        if (is_string($id) === false) {
            throw new \LogicException(sprintf('Puzzle %s is not in round %s.', $puzzleId, $roundId));
        }

        return $id;
    }

    public function dispatch(object $message): void
    {
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();
    }
}
