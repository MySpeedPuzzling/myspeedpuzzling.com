<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use BackedEnum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Writes the live side-by-side rows of an added time (SolvingTimePredictor - statistical, and for a first try image +
 * blend) as **one** multi-row INSERT right after the flush that inserted the time, inside the same transaction: three
 * rows through the unit of work would be three INSERTs (the ORM runs one per entity), and the add-time statement budget
 * must not grow by a statement per predictor (Insights plan §5.4). The rows are plain values computed before - the
 * reads that could fail ran in SolvingTimePredictor's savepoint - so this insert is as safe as the ORM's was.
 *
 * A row waits until its time is in the database (managed and no longer scheduled for insert: a flush of something else
 * before the handler's own one leaves it queued). The column list comes from the entity's mapping, so the rows are
 * written exactly like the ORM writes them. reset() drops what a failed request left behind (FrankenPHP worker mode,
 * the messenger worker resets after every message).
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class LivePredictionRowsWriter implements ResetInterface
{
    /** @var list<SolvingTimePredictionRecord> */
    private array $pending = [];

    public function add(SolvingTimePredictionRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->pending[] = $record;
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        if ($this->pending === []) {
            return;
        }

        $entityManager = $eventArgs->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $ready = [];
        $waiting = [];

        foreach ($this->pending as $record) {
            $timeInDatabase = $unitOfWork->isInIdentityMap($record->time) && $unitOfWork->isScheduledForInsert($record->time) === false;

            if ($timeInDatabase) {
                $ready[] = $record;
            } else {
                $waiting[] = $record;
            }
        }

        // Before the insert: a nested flush (the PuzzleSolved handlers run on postFlush too) must not write them twice
        $this->pending = $waiting;

        if ($ready === []) {
            return;
        }

        $metadata = $entityManager->getClassMetadata(SolvingTimePredictionRecord::class);
        $connection = $entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        $columns = [];
        $fields = [];

        foreach ($metadata->getFieldNames() as $field) {
            $mapping = $metadata->getFieldMapping($field);
            $columns[] = $mapping->columnName;
            $fields[] = [$field, Type::getType($mapping->type)];
        }

        $timeJoinColumn = $metadata->getSingleAssociationJoinColumnName('time');
        $columns[] = $timeJoinColumn;

        $params = [];
        $types = [];
        $tuples = [];

        foreach ($ready as $record) {
            $placeholders = [];

            foreach ($fields as [$field, $type]) {
                $value = $metadata->getFieldValue($record, $field);

                if ($value instanceof BackedEnum) {
                    $value = $value->value;
                }

                $params[] = $type->convertToDatabaseValue($value, $platform);
                $types[] = $type->getBindingType();
                $placeholders[] = '?';
            }

            $params[] = $record->time->id->toString();
            $types[] = Type::getType('string')->getBindingType();
            $placeholders[] = '?';

            $tuples[] = '(' . implode(', ', $placeholders) . ')';
        }

        $connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES %s', $metadata->getTableName(), implode(', ', $columns), implode(', ', $tuples)),
            $params,
            $types,
        );
    }
}
