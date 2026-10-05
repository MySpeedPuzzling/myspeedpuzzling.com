<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\Tag;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionTagShared;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\SetCompetitionPuzzles;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\TagRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SetCompetitionPuzzlesHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PuzzleRepository $puzzleRepository,
        private TagRepository $tagRepository,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws PuzzleNotFound
     * @throws CompetitionTagShared
     */
    public function __invoke(SetCompetitionPuzzles $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        /** @var array<string, Puzzle> $puzzles */
        $puzzles = [];

        foreach ($message->puzzleIds as $puzzleId) {
            $puzzle = $this->puzzleRepository->get($puzzleId);
            $puzzles[$puzzle->id->toString()] = $puzzle;
        }

        $tag = $competition->tag;

        if ($tag === null) {
            if ($puzzles === []) {
                return;
            }

            // Named like the competition's badge on solving times - the tag shows as a badge on its puzzles
            $tag = new Tag(Uuid::uuid7(), $competition->shortcut ?? $competition->name);
            $this->tagRepository->save($tag);
            $competition->tag = $tag;
        } else {
            $otherHolders = $this->tagRepository->countHoldersOtherThan($tag, $competition);

            if ($otherHolders > 0) {
                throw new CompetitionTagShared($tag->name, $otherHolders);
            }
        }

        foreach ($tag->puzzles->toArray() as $taggedPuzzle) {
            if (isset($puzzles[$taggedPuzzle->id->toString()]) === false) {
                $tag->puzzles->removeElement($taggedPuzzle);
            }
        }

        foreach ($puzzles as $puzzle) {
            if ($tag->puzzles->contains($puzzle) === false) {
                $tag->puzzles->add($puzzle);
            }
        }
    }
}
