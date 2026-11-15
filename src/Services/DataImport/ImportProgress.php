<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Query\GetDataImports;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one place that says how far a background step of an import is (docs/features/data-import/reading.md
 * "Progress"):
 * - store(): written on the import, by the handler of the step, inside its transaction - the progress is always in
 *   the database, the page can ask for it at any time;
 * - publish(): **after that transaction is committed**, what the database now says goes to the player's own private
 *   Mercure topic (`/imports/<playerId>`, one of the topics every signed-in page subscribes to -
 *   MercureTopicCollector::getAllTopicsForPlayer()), so an open page moves without asking. Numbers and states only.
 *
 * The stream only brings it sooner: a hub that does not answer never fails a step (one warning per import, the page
 * falls back to asking), and steps that follow each other within a second are published once.
 */
final class ImportProgress implements ResetInterface
{
    private const int AT_MOST_EVERY_NANOSECONDS = 1_000_000_000;

    /** @var array<string, int> import id => when its progress was last published (hrtime) */
    private array $publishedAt = [];
    /** @var array<string, true> imports whose publishing failed and was reported */
    private array $reported = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly GetDataImports $getDataImports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function topicOf(string $playerId): string
    {
        return '/imports/' . strtolower($playerId);
    }

    public function store(DataImport $import, null|int $sheetsTotal, int $sheetsRead, int $rowsRead, DateTimeImmutable $now): void
    {
        $import->readSoFar($sheetsTotal, $sheetsRead, $rowsRead, $now);
    }

    /**
     * Call it outside a transaction, after the step's write was committed.
     *
     * @param bool $always false: left out when the import's progress was published less than a second ago (the rows
     *        of a file come in many batches a second); true for what the page must not miss - a change of state
     */
    public function publish(string $importId, bool $always = true): void
    {
        $now = (int) hrtime(true);

        if ($always === false && $now - ($this->publishedAt[$importId] ?? 0) < self::AT_MOST_EVERY_NANOSECONDS) {
            return;
        }

        $this->publishedAt[$importId] = $now;

        try {
            $progress = $this->getDataImports->progress($importId);

            if ($progress === null) {
                return;
            }

            $this->hub->publish(new Update(
                self::topicOf($progress->playerId),
                json_encode($progress->toArray(), JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $e) {
            if (isset($this->reported[$importId])) {
                return;
            }

            $this->reported[$importId] = true;
            $this->logger->warning('An import\'s progress could not be published - its page falls back to asking', [
                'import_id' => $importId,
                'exception' => $e,
            ]);
        }
    }

    public function reset(): void
    {
        $this->publishedAt = [];
        $this->reported = [];
    }
}
