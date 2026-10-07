<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\AutomaticRevealChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToCompetitionRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerResolver $manufacturerResolver,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private SecretPuzzleHides $secretPuzzleHides,
        private ImageOptimizer $imageOptimizer,
        private PuzzleImageNamer $puzzleImageNamer,
        private GetCompetitionRounds $getCompetitionRounds,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * The automatic reveal is checked first, before anything is created (a refused add leaves no puzzle, no row and no
     * picture behind).
     *
     * @throws AutomaticRevealChangedMeanwhile
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     * @throws PuzzleHiddenByHand
     * @throws PuzzleNameAlreadyPublic
     */
    public function __invoke(AddPuzzleToCompetitionRound $message): void
    {
        $isNewPuzzle = !Uuid::isValid($message->puzzle);

        // Locks the round (its start must not move meanwhile), then the puzzle - waits for every other change of them,
        // then reads fresh (SecretPuzzleHides)
        $this->secretPuzzleHides->lockForAddingTo($message->roundId, $isNewPuzzle ? [] : [$message->puzzle]);

        $round = $this->competitionRoundRepository->get($message->roundId);

        // A secret puzzle is a yes to the automatic reveal the organiser saw - the round's start or delay changed since
        // (another tab, the round form, the internal API): asked again, never added for another moment. The round is
        // locked: it stays as read here until the end of this handler.
        if ($message->hideUntilRoundStarts) {
            $automaticRevealAt = $round->automaticRevealAt();

            if ($message->shownAutomaticRevealAt?->getTimestamp() !== $automaticRevealAt->getTimestamp()) {
                throw new AutomaticRevealChangedMeanwhile($automaticRevealAt, $message->shownAutomaticRevealAt);
            }
        }

        $puzzleKeptSecret = false;

        if ($isNewPuzzle) {
            $puzzle = $this->createNewPuzzle($message);
        } else {
            $puzzle = $this->puzzleRepository->get($message->puzzle);

            // Another organiser's secret puzzle is not theirs to use - also while only its picture is hidden
            $this->secretPuzzleAccess->assertPuzzleUsableBy(
                $puzzle,
                $this->playerRepository->getByUserIdCreateIfNotExists($message->userId)->id->toString(),
                alsoWhileImageHidden: true,
            );

            // A puzzle hidden by hand (a placeholder) is no round's to hide or reveal
            $puzzleKeptSecret = $this->isPuzzleKeptSecret->byId($puzzle->id->toString());
            if ($puzzle->isImageHiddenAt($this->clock->now()) && $puzzleKeptSecret === false) {
                throw new PuzzleHiddenByHand();
            }

            // Kept secret on the whole site with its name already public ("image only" in another round): this round
            // would hide the name again - it can only keep the picture secret
            if (
                $puzzleKeptSecret
                && $message->hideUntilRoundStarts
                && $message->hideMode === PuzzleHideMode::Entirely
                && $puzzle->isHiddenAt($this->clock->now()) === false
            ) {
                throw new PuzzleNameAlreadyPublic();
            }

            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $round->competition->id->toString(),
                puzzleIds: [$puzzle->id->toString()],
                category: $round->category,
            );

            if ($conflictingRound !== null) {
                throw new PuzzleAlreadyInCompetitionRoundCategory($conflictingRound);
            }
        }

        // A new puzzle exists nowhere else yet, and a puzzle still secret from another round is not public either:
        // the round keeps it secret on the whole site, not only on its event pages (SecretPuzzleHides)
        $roundPuzzle = new CompetitionRoundPuzzle(
            id: $message->roundPuzzleId,
            round: $round,
            puzzle: $puzzle,
            hideUntilRoundStarts: $message->hideUntilRoundStarts,
            hideMode: $message->hideUntilRoundStarts ? $message->hideMode : null,
            hidesEverywhere: $message->hideUntilRoundStarts
                && ($isNewPuzzle || $puzzleKeptSecret),
        );

        $this->competitionRoundPuzzleRepository->save($roundPuzzle);
        $this->secretPuzzleHides->resync($puzzle);
    }

    private function createNewPuzzle(AddPuzzleToCompetitionRound $message): Puzzle
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $now = $this->clock->now();

        $manufacturer = $this->manufacturerResolver->resolve($message->brand, $player, $now);

        $puzzleId = Uuid::uuid7();
        $puzzlePhotoPath = null;

        if ($message->puzzlePhoto !== null) {
            $extension = $message->puzzlePhoto->guessExtension() ?? 'jpg';
            // A secret puzzle's picture must not be found by guessing its file name from the public name and id
            $puzzlePhotoPath = $message->hideUntilRoundStarts
                ? $this->puzzleImageNamer->secretFilename($extension)
                : $this->puzzleImageNamer->generateFilename(
                    $manufacturer->name,
                    $message->puzzle,
                    $message->piecesCount ?? 0,
                    $puzzleId->toString(),
                    $extension,
                );

            $this->imageOptimizer->optimize($message->puzzlePhoto->getPathname());

            $stream = fopen($message->puzzlePhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($puzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $puzzle = new Puzzle(
            $puzzleId,
            $message->piecesCount ?? 0,
            $message->puzzle,
            approved: false,
            image: $puzzlePhotoPath,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
            brandCodes: $message->brandCodes,
            eans: $message->eans,
        );

        $this->entityManager->persist($puzzle);

        return $puzzle;
    }
}
