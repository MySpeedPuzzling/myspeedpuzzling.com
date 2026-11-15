<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPuzzleDecidedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportPuzzlePile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One answer for a whole pile of an import's puzzles (docs/features/data-import/guided-flow.md §6.2, §6.3): "Keep
 * them just for me" or "Don't import them" for every puzzle that is not in our database, "Keep the rest just for me"
 * for the puzzles the player would have to pick one by one. **Neither answer matches a puzzle with the database** -
 * nothing of it reaches a leaderboard, and each puzzle can be decided otherwise afterwards.
 *
 * What makes it safe:
 *
 * - **the puzzles are worked out here, never sent by a form**: the pile's puzzles of that import of that player that
 *   wait *now* (GetImportPuzzleScreen::waitingOfPile()), each with the version it has now;
 * - they are decided through the one message every decision goes through (DecideImportPuzzles - under the import's
 *   lock, version-checked, all of a chunk or none), CHUNK at a time, by a cursor that only moves forward;
 * - a puzzle decided between the read and the lock (another tab) is **skipped and counted**: the chunk is sent again
 *   without it - what the player decided themselves is never overwritten;
 * - nothing is answered while puzzles are still looked up (the pile is not whole yet), and one answer at a time per
 *   import: a second one while the first still runs is told so (`busy`) - "keep" and "don't import" side by side
 *   would split the pile.
 *
 * Up to AT_ONCE puzzles are answered in the request; a larger pile goes to the `imports` worker
 * (AnswerImportPuzzlePile), a chunk a turn, and the pile's page follows it. What says "an answer runs" is a short
 * entry of a shared cache - it frees itself when a worker dies.
 */
readonly final class ImportPuzzlePile
{
    public const int CHUNK = 200;
    public const int AT_ONCE = 200;

    public const string DONE = 'done';
    public const string QUEUED = 'queued';
    public const string BUSY = 'busy';
    public const string STILL_LOOKING = 'still_looking';
    public const string NOTHING = 'nothing';
    public const string REFUSED = 'refused';

    /** What one answer may be, per pile: the puzzles to pick one by one are only ever kept - never left out in bulk */
    public const array CHOICES = [
        'not-found' => [ImportPuzzleChoice::Unlisted, ImportPuzzleChoice::LeftOut],
        'choose' => [ImportPuzzleChoice::Unlisted],
    ];

    private const int RUNNING_SECONDS = 600;

    public function __construct(
        private GetImportPuzzleScreen $getImportPuzzleScreen,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'import_puzzle_pile_cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{state: string, decided: int, skipped: int, waiting: int}
     *
     * @throws DataImportNotFound another player's import, or none
     */
    public function answer(string $importId, string $playerId, string $pile, string $choice): array
    {
        // Another player's import: not found - before anything else is said
        $overview = $this->getImportPuzzleScreen->overview($importId, $playerId);
        $choice = ImportPuzzleChoice::tryFrom($choice);
        $nothing = ['state' => self::NOTHING, 'decided' => 0, 'skipped' => 0, 'waiting' => 0];

        if ($choice === null || in_array($choice, self::CHOICES[$pile] ?? [], true) === false || $overview->beingSetUp === false) {
            return ['state' => self::REFUSED] + $nothing;
        }

        $waiting = $overview->groups[$pile]['open'];

        // The pile is not whole while puzzles are still looked up: one answer would miss those that arrive
        if ($overview->paired === false || $overview->searching > 0) {
            return ['state' => self::STILL_LOOKING, 'waiting' => $waiting] + $nothing;
        }

        if ($this->running($importId) !== null) {
            return ['state' => self::BUSY, 'waiting' => $waiting] + $nothing;
        }

        if ($waiting === 0) {
            return $nothing;
        }

        if ($waiting > self::AT_ONCE) {
            $this->markRunning($importId, $pile, $choice);
            $this->messageBus->dispatch(new AnswerImportPuzzlePile($importId, $playerId, $pile, $choice->value));

            return ['state' => self::QUEUED, 'waiting' => $waiting] + $nothing;
        }

        $decided = 0;
        $skipped = 0;
        $after = null;

        // Bounded: the pile held AT_ONCE puzzles at most a moment ago, and the cursor only moves forward
        for ($turn = 0; $turn <= (int) ceil(self::AT_ONCE / self::CHUNK); $turn++) {
            $done = $this->turn($importId, $playerId, $pile, $choice, $after);
            $decided += $done['decided'];
            $skipped += $done['skipped'];
            $after = $done['after'];

            if ($after === null) {
                break;
            }
        }

        return ['state' => self::DONE, 'decided' => $decided, 'skipped' => $skipped, 'waiting' => 0];
    }

    /**
     * One chunk: the next CHUNK puzzles of the pile that wait now take the answer.
     *
     * @return array{decided: int, skipped: int, after: null|string} `after`: where the next turn goes on - null: that
     *         was the last chunk
     *
     * @throws DataImportNotFound the import is no longer the player's, or no longer being set up
     */
    public function turn(string $importId, string $playerId, string $pile, ImportPuzzleChoice $choice, null|string $after): array
    {
        if (in_array($choice, self::CHOICES[$pile] ?? [], true) === false) {
            return ['decided' => 0, 'skipped' => 0, 'after' => null];
        }

        $waiting = $this->getImportPuzzleScreen->waitingOfPile($importId, $playerId, $pile, self::CHUNK, $after);

        if ($waiting === []) {
            return ['decided' => 0, 'skipped' => 0, 'after' => null];
        }

        $last = $waiting[array_key_last($waiting)]['id'];
        $skipped = 0;

        // A puzzle decided between the read and the lock refuses the whole message: it is sent again without the
        // puzzles that changed. Each try names fewer puzzles, so it ends
        while ($waiting !== []) {
            try {
                $this->messageBus->dispatch(new DecideImportPuzzles($importId, $playerId, array_map(
                    static fn (array $puzzle): ImportPuzzleDecisionChange => new ImportPuzzleDecisionChange($puzzle['id'], $choice, null, $puzzle['version']),
                    $waiting,
                )));

                break;
            } catch (HandlerFailedException $exception) {
                $cause = $exception->getPrevious();

                if ($cause instanceof DataImportNotFound) {
                    throw $cause;
                }

                if ($cause instanceof ImportPuzzleDecidedMeanwhile === false || $cause->importPuzzleIds === []) {
                    throw $exception;
                }

                $changed = array_map(strtolower(...), $cause->importPuzzleIds);
                $left = array_values(array_filter($waiting, static fn (array $puzzle): bool => in_array(strtolower($puzzle['id']), $changed, true) === false));
                $skipped += count($waiting) - count($left);

                if (count($left) === count($waiting)) {
                    throw $exception;
                }

                $waiting = $left;
                // The refused message's puzzles are still in the entity manager as they were read: the next try must
                // read them - and their versions - afresh
                $this->entityManager->clear();
            }
        }

        return ['decided' => count($waiting), 'skipped' => $skipped, 'after' => count($waiting) + $skipped < self::CHUNK ? null : $last];
    }

    /**
     * The answer that runs for the import on the worker - null: none.
     *
     * @return null|array{pile: string, choice: string}
     */
    public function running(string $importId): null|array
    {
        $item = $this->cache->getItem(self::key($importId));
        $value = $item->isHit() ? $item->get() : null;

        return is_array($value) && is_string($value['pile'] ?? null) && is_string($value['choice'] ?? null)
            ? ['pile' => $value['pile'], 'choice' => $value['choice']]
            : null;
    }

    /**
     * Said again by every turn of the worker: an answer whose worker died frees the import by itself.
     */
    public function markRunning(string $importId, string $pile, ImportPuzzleChoice $choice): void
    {
        $item = $this->cache->getItem(self::key($importId));
        $item->set(['pile' => $pile, 'choice' => $choice->value]);
        $item->expiresAfter(self::RUNNING_SECONDS);
        $this->cache->save($item);
    }

    public function ended(string $importId): void
    {
        $this->cache->deleteItem(self::key($importId));
    }

    private static function key(string $importId): string
    {
        return 'pile.' . preg_replace('/[^a-f0-9-]/', '', strtolower($importId));
    }
}
