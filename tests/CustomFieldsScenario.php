<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Custom fields, results and values for the tests of docs/features/custom-fields.md - everything made the way the
 * app makes it (through the messages), on PUZZLE_3000, which no fixture solved.
 */
readonly final class CustomFieldsScenario
{
    public const string PUZZLE = PuzzleFixture::PUZZLE_3000;

    private MessageBusInterface $messageBus;
    private Connection $database;

    public function __construct(
        private ContainerInterface $container,
    ) {
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->messageBus = $container->get(MessageBusInterface::class);
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->database = $container->get(Connection::class);
    }

    /**
     * @param list<string> $choices
     */
    public function field(
        string $playerId,
        string $name,
        CustomFieldType $type = CustomFieldType::Text,
        array $choices = [],
        bool $forResults = true,
        bool $forCollectionItems = false,
    ): string {
        $fieldId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new AddCustomField($fieldId, $playerId, $name, $type, $choices, $forResults, $forCollectionItems));

        return $fieldId;
    }

    public function readyMade(string $playerId, StandardCustomField $standard): string
    {
        $this->messageBus->dispatch(AddCustomField::readyMade(Uuid::uuid7()->toString(), $playerId, $standard));

        $fieldId = $this->database->fetchOne(
            'SELECT id FROM player_custom_field WHERE player_id = :playerId AND standard_key = :key',
            ['playerId' => $playerId, 'key' => $standard->value],
        );
        assert(is_string($fieldId));

        return $fieldId;
    }

    /**
     * A result on PUZZLE_3000, saved the normal way.
     *
     * @param list<string> $groupPlayers `#code` of registered players, a plain name for a guest
     */
    public function result(string $userId, array $groupPlayers = [], string $time = '05:00:00', int $daysAgo = 3): string
    {
        $timeId = Uuid::uuid7();
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $now = $this->container->get(ClockInterface::class)->now();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: self::PUZZLE,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $now->modify(sprintf('-%d days', $daysAgo)),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @param array<string, mixed> $values keyed by field id
     */
    public function setValues(string $timeId, string $playerId, array $values): void
    {
        try {
            $this->messageBus->dispatch(new SetSolvingTimeCustomValues($timeId, $playerId, $values));
        } finally {
            $this->forgetLoadedEntities();
        }
    }

    /**
     * Every step of a scenario is a request of its own in the app - what one step loaded, the next must not hold
     * (a values row still held would be flushed against a result a later step deleted).
     */
    public function forgetLoadedEntities(): void
    {
        $this->container->get('doctrine')->getManager()->clear();
    }

    /**
     * @return null|array<string, mixed> what the player's row of the result holds, null = no row
     */
    public function storedValues(string $timeId, string $playerId): null|array
    {
        $stored = $this->database->fetchOne(
            'SELECT custom_values FROM solving_time_custom_values WHERE solving_time_id = :timeId AND player_id = :playerId',
            ['timeId' => $timeId, 'playerId' => $playerId],
        );

        if (is_string($stored) === false) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        return $values;
    }

    /**
     * @param array<string, mixed> $values keyed by field id
     */
    public function setItemValues(string $collectionItemId, string $playerId, array $values): void
    {
        try {
            $this->messageBus->dispatch(new SetCollectionItemCustomValues($collectionItemId, $playerId, $values));
        } finally {
            $this->forgetLoadedEntities();
        }
    }

    /**
     * The player's item of the puzzle in the collection (null = the system collection), null = there is none.
     */
    public function itemId(string $playerId, string $puzzleId, null|string $collectionId): null|string
    {
        $itemId = $this->database->fetchOne(
            'SELECT id FROM collection_item WHERE player_id = :playerId AND puzzle_id = :puzzleId AND collection_id IS NOT DISTINCT FROM :collectionId',
            ['playerId' => $playerId, 'puzzleId' => $puzzleId, 'collectionId' => $collectionId],
        );

        return is_string($itemId) ? $itemId : null;
    }

    /**
     * @return null|array<string, mixed> what the collection item holds, null = nothing (or no such item)
     */
    public function storedItemValues(string $collectionItemId): null|array
    {
        $stored = $this->database->fetchOne(
            'SELECT custom_values FROM collection_item WHERE id = :id',
            ['id' => $collectionItemId],
        );

        if (is_string($stored) === false) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);

        return $values;
    }

    public function valueRows(null|string $timeId = null): int
    {
        $count = $timeId === null
            ? $this->database->fetchOne('SELECT COUNT(*) FROM solving_time_custom_values')
            : $this->database->fetchOne('SELECT COUNT(*) FROM solving_time_custom_values WHERE solving_time_id = :timeId', ['timeId' => $timeId]);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    public function fieldsOf(string $playerId): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM player_custom_field WHERE player_id = :playerId', ['playerId' => $playerId]);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }
}
