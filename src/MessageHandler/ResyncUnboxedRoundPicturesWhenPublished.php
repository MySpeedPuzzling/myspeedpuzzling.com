<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The safety net of an unboxed round's first publish (world-series-rules.md §7): PublishRoundResultsHandler decides
 * whether to re-sync the round's secret puzzles on a read before any lock - an "Unboxed" switched on in between
 * (ChangeRoundUnboxed commits before the publish) re-synced them while the round was not published yet, so a puzzle the
 * round hides everywhere would keep its picture hidden for good. After the publish's commit (asynchronous, like the
 * notification of the same event) this re-syncs them once more, under the round's lock.
 *
 * A round that is not unboxed pays nothing: the round comes from the entity manager the notification handler of the
 * same event loads it into (one find, the identity map), and nothing else is read. An unboxed published round pays one
 * cheap check, and the lock only while one of its pictures is still kept back for good.
 */
#[AsMessageHandler]
readonly final class ResyncUnboxedRoundPicturesWhenPublished
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private Connection $database,
    ) {
    }

    public function __invoke(OfficialRoundResultsPublished $event): void
    {
        $roundId = $event->roundId->toString();

        try {
            $round = $this->roundRepository->get($roundId);
        } catch (CompetitionRoundNotFound) {
            // Handled asynchronously - the round may be gone by now
            return;
        }

        if ($round->unboxed === false || $round->resultsFirstPublishedAt === null) {
            return;
        }

        $keptForGood = $this->database->fetchOne(
            'SELECT 1 FROM competition_round_puzzle crp'
            . ' INNER JOIN puzzle p ON p.id = crp.puzzle_id'
            . ' WHERE crp.round_id = :roundId AND crp.hide_until_round_starts AND crp.hides_everywhere'
            . ' AND p.hide_image_until = :never LIMIT 1',
            ['roundId' => $roundId, 'never' => CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED],
        );

        if ($keptForGood === false) {
            return;
        }

        // The round, then its secret puzzles - read again after the lock (SecretPuzzleHides)
        $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange([$roundId]);
        $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);
    }
}
