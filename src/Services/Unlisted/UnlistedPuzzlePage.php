<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Unlisted;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Results\ImportCataloguePuzzle;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the page of one unlisted puzzle shows (docs/features/data-import/unlisted-puzzles.md "The pages"). **It takes
 * no player id**: it resolves the signed-in player itself and reads that player's unlisted puzzle only - a guest and
 * anybody else get "not found", which nobody can tell from a puzzle that does not exist.
 *
 * Catalogue puzzles are offered by the import's own rules and shown by its own reader
 * (GetImportPuzzleScreen::offered() / search() - never a hidden or secret puzzle).
 */
readonly final class UnlistedPuzzlePage
{
    public const string CSRF_TOKEN_ID = 'unlisted_puzzles';

    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private GetUnlistedPuzzles $getUnlistedPuzzles,
        private UnlistedPuzzleRepository $unlistedRepository,
        private UnlistedPuzzleMatcher $matcher,
        private GetImportPuzzleScreen $getImportPuzzleScreen,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The signed-in player's id - a guest is told "not found", like anybody who is not the owner.
     *
     * @throws NotFoundHttpException
     */
    public function ownerPlayerId(): string
    {
        return $this->retrieveLoggedUserProfile->getProfile()->playerId ?? throw new UnlistedPuzzleNotFound();
    }

    /**
     * @param null|string $typed the player's own search - null: none was made
     *
     * @return array<string, mixed> the template's variables
     *
     * @throws NotFoundHttpException
     */
    public function parameters(string $unlistedPuzzleId, null|string $typed = null): array
    {
        $playerId = $this->ownerPlayerId();
        $puzzle = $this->getUnlistedPuzzles->one($playerId, $unlistedPuzzleId);
        $now = $this->clock->now();
        $working = null;

        foreach ($puzzle['pairings'] as $pairing) {
            if ($pairing['state'] === 'running' || $pairing['state'] === 'undoing') {
                $working = $pairing;
            }
        }

        $paired = $puzzle['pairedPuzzleId'] === null ? null : $this->offered($playerId, $puzzle['pairedPuzzleId'], $puzzle['piecesCount'], null, 'chosen');
        $suggested = null;
        $candidates = [];
        $manufacturerId = null;
        $canPair = $working === null && $puzzle['unlistedLeft'] > 0;

        // What is left of a paired puzzle goes to the same catalogue puzzle - no other is offered
        if ($canPair && $puzzle['pairedPuzzleId'] === null) {
            $said = $this->matcher->candidatesOf($this->unlistedRepository->getOwnedBy($unlistedPuzzleId, $playerId), $now);
            $manufacturerId = $said['manufacturerId'];

            if ($puzzle['suggestedPuzzleId'] !== null) {
                $suggested = $this->offered($playerId, $puzzle['suggestedPuzzleId'], $puzzle['piecesCount'], $manufacturerId, 'suggested');
            }

            foreach ($said['candidates'] as $candidate) {
                if ($suggested !== null && $candidate['puzzleId'] === $suggested->puzzleId) {
                    continue;
                }

                $offered = $this->offered($playerId, $candidate['puzzleId'], $puzzle['piecesCount'], $manufacturerId, $candidate['how']);

                if ($offered !== null) {
                    $candidates[] = $offered;
                }
            }
        }

        $searchable = $typed !== null && GetImportPuzzleScreen::isSearchable($typed);

        return [
            'puzzle' => $puzzle,
            'working' => $working,
            'paired' => $paired,
            'suggested' => $suggested,
            'candidates' => $candidates,
            'can_pair' => $canPair,
            'can_choose' => $canPair && $puzzle['pairedPuzzleId'] === null,
            'typed' => $typed ?? $puzzle['name'],
            'searched' => $typed !== null,
            'search_too_short' => $typed !== null && $searchable === false,
            'found' => $searchable ? $this->getImportPuzzleScreen->search($playerId, $typed, $puzzle['piecesCount'], $manufacturerId) : [],
            // One id per rendered page: a double tap or a retry of "It is this one" answers the same pairing
            'request_id' => Uuid::uuid7()->toString(),
            'undo_until' => $now->modify(sprintf('-%d days', UnlistedPairing::UNDO_DAYS)),
        ];
    }

    /**
     * A catalogue puzzle as the import's screens show it - null when it may not be offered to the player (hidden,
     * secret, gone). A puzzle merged since is its survivor.
     */
    private function offered(string $playerId, string $puzzleId, null|int $pieces, null|string $manufacturerId, string $how): null|ImportCataloguePuzzle
    {
        $shown = $this->getImportPuzzleScreen->offered($playerId, $puzzleId, $pieces, $manufacturerId);

        if ($shown === null) {
            $survivorId = $this->puzzleRedirectRepository->findByOldPuzzleId($puzzleId)?->survivorPuzzleId->toString();
            $shown = $survivorId === null ? null : $this->getImportPuzzleScreen->offered($playerId, $survivorId, $pieces, $manufacturerId);
        }

        if ($shown === null) {
            return null;
        }

        return new ImportCataloguePuzzle(
            puzzleId: $shown->puzzleId,
            name: $shown->name,
            alternativeNames: $shown->alternativeNames,
            brandName: $shown->brandName,
            piecesCount: $shown->piecesCount,
            image: $shown->image,
            imageRatio: $shown->imageRatio,
            how: $how,
            piecesDiffer: $shown->piecesDiffer,
            brandDiffers: $shown->brandDiffers,
        );
    }
}
