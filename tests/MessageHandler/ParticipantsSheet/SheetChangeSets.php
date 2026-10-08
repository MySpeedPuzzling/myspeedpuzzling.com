<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ApplyParticipantSheetChanges;
use SpeedPuzzling\Web\Results\AppliedParticipantSheetChanges;
use SpeedPuzzling\Web\Results\SheetGroupOutcome;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Participants sheet change sets in the wire format the page sends, dispatched through the bus (lock, transaction).
 */
trait SheetChangeSets
{
    /**
     * @param list<array<string, mixed>> $groups
     */
    private function applySheetChanges(
        array $groups,
        bool $dryRun = false,
        null|string $changesetId = null,
        string $competitionId = OfficialResultsFixture::COMPETITION_RESULTS_CUP,
        string $actingPlayerId = PlayerFixture::PLAYER_WITH_STRIPE,
    ): AppliedParticipantSheetChanges {
        $parsed = SheetChangesParser::parse([
            'changesetId' => $changesetId ?? Uuid::uuid7()->toString(),
            'dryRun' => $dryRun,
            'groups' => $groups,
        ]);

        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $envelope = $messageBus->dispatch(new ApplyParticipantSheetChanges(
            competitionId: $competitionId,
            actingPlayerId: $actingPlayerId,
            changesetId: $parsed['changesetId'],
            groups: $parsed['groups'],
            dryRun: $parsed['dryRun'],
        ));

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        assert($applied instanceof AppliedParticipantSheetChanges);

        return $applied;
    }

    /**
     * @param array<string, mixed> ...$changes
     * @return array<string, mixed>
     */
    private static function sheetGroup(array ...$changes): array
    {
        return ['id' => 'group-' . Uuid::uuid4()->toString(), 'changes' => array_values($changes)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function placeChange(string $participantId, string $roundId, string $from, string $to): array
    {
        return ['op' => 'place', 'participant' => $participantId, 'round' => $roundId, 'from' => $from, 'to' => $to];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldChange(string $participantId, string $field, null|string $from, null|string $to): array
    {
        return ['op' => 'field', 'participant' => $participantId, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @return list<string> the groups' statuses
     */
    private static function groupStatuses(AppliedParticipantSheetChanges $applied): array
    {
        return array_map(static fn (SheetGroupOutcome $group): string => $group->status->value, $applied->groups);
    }

    /**
     * @return list<string> "status" or "status:reason" of every change of the group
     */
    private static function changeStatuses(AppliedParticipantSheetChanges $applied, int $group = 0): array
    {
        $statuses = [];

        foreach ($applied->groups[$group]->changes as $change) {
            $statuses[] = $change->status->value . ($change->reason !== null ? ':' . $change->reason : '');
        }

        return $statuses;
    }

    /**
     * `out`, `in` or `team:<id>` - as the database has it.
     */
    private static function storedPlace(Connection $database, string $participantId, string $roundId): string
    {
        $row = $database->fetchAssociative(
            'SELECT team_id FROM competition_participant_round WHERE participant_id = :participant AND round_id = :round',
            ['participant' => $participantId, 'round' => $roundId],
        );

        if ($row === false) {
            return 'out';
        }

        return is_string($row['team_id']) ? 'team:' . $row['team_id'] : 'in';
    }

    /**
     * @return array<string, mixed>
     */
    private static function participantRow(Connection $database, string $participantId): array
    {
        $row = $database->fetchAssociative('SELECT * FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        assert(is_array($row));

        return $row;
    }
}
