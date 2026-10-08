<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\CopyPuzzlesToCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The selected puzzles stay in the source and are added to the target too, with the source item's added date and
 * comment. Puzzles the target already holds are skipped; so are puzzles no longer in the source and secret puzzles
 * (nothing personal is recorded on one before its reveal - SecretPuzzleAccess).
 */
#[AsMessageHandler]
readonly final class CopyPuzzlesToCollectionHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * @throws CollectionNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(CopyPuzzlesToCollection $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $source = $this->collectionRepository->getOwnedBy($message->sourceCollectionId, $player);
        $target = $this->collectionRepository->getOwnedBy($message->targetCollectionId, $player);
        $puzzleIds = array_unique($message->puzzleIds);

        if ($source === $target) {
            return new SelectedPuzzlesOutcome(changed: 0, skipped: count($puzzleIds));
        }

        $sourceItems = $this->collectionItemRepository->findByCollectionPlayerAndPuzzles($source, $player, $puzzleIds);
        $targetItems = $this->collectionItemRepository->findByCollectionPlayerAndPuzzles($target, $player, array_keys($sourceItems));
        $copied = 0;
        $secret = 0;

        foreach ($sourceItems as $puzzleId => $item) {
            if (isset($targetItems[$puzzleId])) {
                continue;
            }

            try {
                $this->secretPuzzleAccess->assertPuzzleWritableBy($item->puzzle, $message->playerId);
            } catch (PuzzleNotRevealedYet) {
                $secret++;
                continue;
            }

            $this->collectionItemRepository->save(new CollectionItem(
                id: Uuid::uuid7(),
                collection: $target,
                player: $player,
                puzzle: $item->puzzle,
                comment: $item->comment,
                addedAt: $item->addedAt,
            ));
            $copied++;
        }

        return new SelectedPuzzlesOutcome(
            changed: $copied,
            alreadyThere: count($targetItems),
            skipped: count($puzzleIds) - count($sourceItems) + $secret,
        );
    }
}
