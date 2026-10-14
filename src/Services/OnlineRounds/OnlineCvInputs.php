<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Value\OnlineCvRequest;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;

/**
 * What the CV worker gets for one finish photo (docs/features/online-competitions/README.md §5.8, implementation-plan.md
 * WP 3.2) - presigned GET URLs valid URL_SECONDS, made for the stores' server-side endpoints
 * (OnlineMediaStores::serverLocation(): the worker sits inside the network, a browser's endpoint means nothing there):
 * - the finish photo (a `candidate`, `button` or `end` photo) in its own store;
 * - the entry's empty mat: its latest verified `empty_mat` photo taken before the finish photo (the set-up captures it
 *   before the unwrapping) - none: the background-holes check cannot run;
 * - the reference: the organiser's reference image of the round when there is one (OnlineReferenceImages - a clean
 *   motif, a JPEG in the primary store), else the round's one puzzle's catalogue image (its stored image, in the
 *   primary store - a box photo too: the worker decides how far to trust it); a secret puzzle's stays on the server,
 *   the check runs there - none with several puzzles or a puzzle without an image: the grid check cannot run;
 * - the piece count of the round's one puzzle (null with several: the checks need it).
 */
readonly final class OnlineCvInputs
{
    // The worker downloads at once (its queue holds a few requests of about a second each) - minutes are plenty
    public const int URL_SECONDS = 300;

    public function __construct(
        private OnlineMediaStores $stores,
        private S3QueryPresigner $presigner,
        private OnlineEntryMediaRepository $onlineEntryMediaRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    public function for(OnlineEntryMedia $finish): OnlineCvRequest
    {
        $round = $finish->onlineEntry->entry->round;
        $emptyMat = $this->onlineEntryMediaRepository->latestVerified(
            $finish->onlineEntry->id->toString(),
            OnlineMediaKind::EmptyMat,
            $finish->takenMs,
        );

        return new OnlineCvRequest(
            finishUrl: $this->url($finish->store, $finish->objectKey),
            emptyMatUrl: $emptyMat === null ? null : $this->url($emptyMat->store, $emptyMat->objectKey),
            referenceUrl: $this->referenceUrl($finish),
            pieceCount: $round->singlePuzzlePiecesCount(),
        );
    }

    /**
     * The organiser's reference image of the photo's round, else the catalogue image of its one puzzle - null: neither
     */
    private function referenceUrl(OnlineEntryMedia $finish): null|string
    {
        $round = $finish->onlineEntry->entry->round;
        $uploaded = $this->onlineRoundRepository->ofRound($round->id->toString())?->referenceImage;

        if ($uploaded !== null) {
            return $this->url(OnlineMediaStore::Primary, $uploaded);
        }

        $roundPuzzle = $round->roundPuzzles->count() === 1 ? $round->roundPuzzles->first() : false;
        $image = $roundPuzzle === false ? null : $roundPuzzle->puzzle->image;

        return $image === null || trim($image) === '' ? null : $this->url(OnlineMediaStore::Primary, ltrim($image, '/'));
    }

    private function url(OnlineMediaStore $store, string $key): string
    {
        return $this->presigner->presign($this->stores->serverLocation($store), 'GET', $key, [], $this->clock->now(), self::URL_SECONDS);
    }
}
