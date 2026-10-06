<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;

#[AsMessageHandler]
readonly final class EditCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private GetCompetitionRounds $getCompetitionRounds,
        private SecretPuzzleHides $secretPuzzleHides,
        private ClockInterface $clock,
        private SecretRevealPreview $secretRevealPreview,
    ) {
    }

    /**
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     * @throws SecretPuzzlesWouldBeRevealed
     */
    public function __invoke(EditCompetitionRound $message): void
    {
        // Locks the round, then its secret puzzles - waits for every other change of them, then reads fresh
        // (SecretPuzzleHides)
        $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->competitionRoundRepository->get($message->roundId);
        $now = $this->clock->now();

        // Kept fields come from the round as it is now, under the lock - never from a read before it
        $keep = static fn (string $field): bool => in_array($field, $message->keepFields, true);
        $startsAt = $keep('startsAt') ? $round->startsAt : $message->startsAt;
        $category = $keep('category') ? $round->category : $message->category;

        if ($message->refuseToReveal || $message->confirmedRevealHash !== null) {
            $revealed = $this->secretRevealPreview->byMovingRound($round, $startsAt);

            if (SecretRevealPreview::refuses($revealed, $message->refuseToReveal, $message->confirmedRevealHash)) {
                throw new SecretPuzzlesWouldBeRevealed($revealed);
            }
        }

        // A puzzle already revealed by this round's automatic reveal is public - a start moved later must not hide it
        // again: its reveal stays at the moment it came out
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $revealsAt = $roundPuzzle->revealsAt();

            if ($roundPuzzle->hideUntilRoundStarts && $roundPuzzle->revealMode === RoundPuzzleReveal::Automatic && $revealsAt !== null && $revealsAt <= $now) {
                $roundPuzzle->pinRevealAt($revealsAt);
            }
        }

        if ($category !== $round->category) {
            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $round->competition->id->toString(),
                puzzleIds: array_values(array_map(
                    static fn (CompetitionRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->id->toString(),
                    $round->roundPuzzles->toArray(),
                )),
                category: $category,
                exceptRoundId: $round->id->toString(),
            );

            if ($conflictingRound !== null) {
                throw new PuzzleAlreadyInCompetitionRoundCategory($conflictingRound);
            }
        }

        $round->edit(
            name: $keep('name') ? $round->name : $message->name,
            minutesLimit: $keep('minutesLimit') ? $round->minutesLimit : $message->minutesLimit,
            startsAt: $startsAt,
            timezone: $keep('timezone') ? $round->displayTimezone() : $message->timezone,
            badgeBackgroundColor: $keep('badgeBackgroundColor') ? $round->badgeBackgroundColor : $message->badgeBackgroundColor,
            badgeTextColor: $keep('badgeTextColor') ? $round->badgeTextColor : $message->badgeTextColor,
            category: $category,
            resultsLink: $keep('resultsLink') ? $round->resultsLink : $message->resultsLink,
        );

        // An automatic reveal follows the round's start - the puzzles it keeps secret on the whole site follow too.
        // Scheduled and manual reveals are the organiser's own and stay where they are.
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
        }
    }
}
