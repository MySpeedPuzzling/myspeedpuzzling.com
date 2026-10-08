<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantRules;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
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
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * Every check comes before anything is changed: a handler that throws after changing an entity is rolled back, but
     * the change stays in the entity manager and a later flush in the same request would write it.
     *
     * @throws CompetitionRoundNotFound a round of another event
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     * @throws SecretPuzzlesWouldBeRevealed
     * @throws OfficialResultsProtected
     */
    public function __invoke(EditCompetitionRound $message): void
    {
        // Locks the round, then its secret puzzles - waits for every other change of them, then reads fresh
        // (SecretPuzzleHides)
        $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $now = $this->clock->now();

        // Kept fields come from the round as it is now, under the lock - never from a read before it
        $keep = static fn (string $field): bool => in_array($field, $message->keepFields, true);
        $startsAt = $keep('startsAt') ? $round->startsAt : $message->startsAt;
        $category = $keep('category') ? $round->category : $message->category;
        // Null = the round's delay as it is now, under the lock
        $revealDelayMinutes = $message->revealDelayMinutes ?? $round->revealDelayMinutes;

        // Official results and qualified marks belong to the round's kind of entries (people or pairs/teams) - checked
        // before anything changes
        if ($category !== $round->category && $this->officialResultsGuard->countEntriesWithOfficialDataInRound($round->id->toString()) > 0) {
            throw new OfficialResultsProtected(OfficialResultsProtected::ROUND_CATEGORY_LOCKED);
        }

        RoundPuzzleReveal::assertValidDelay($revealDelayMinutes);

        // Null = the round's expected team size as it is now, under the lock; checked before anything changes. Only a
        // team round has one - a solo or pair round has none, whatever was sent (a round changed away from team loses it)
        $teamSize = $category === RoundCategory::Team
            ? ($message->clearTeamSize ? null : ($message->teamSize ?? $round->teamSize))
            : null;
        if (ParticipantRules::isValidTeamSize($teamSize) === false) {
            throw new \InvalidArgumentException('A team size is 2 to 20 people.');
        }

        // The round's automatic reveal (start + delay) moved earlier lets its secret puzzles out earlier than planned -
        // only on a yes for exactly that list (SecretRevealPreview), checked again here, after the locks. A later moment
        // never reveals anything early.
        if ($message->refuseToReveal || $message->confirmedRevealHash !== null) {
            $revealed = $this->secretRevealPreview->byChangingRound($round, $startsAt, $revealDelayMinutes);

            if (SecretRevealPreview::refuses($revealed, $message->refuseToReveal, $message->confirmedRevealHash)) {
                throw new SecretPuzzlesWouldBeRevealed($revealed);
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

        // A puzzle already revealed by this round's automatic reveal is public - a start moved later or a longer delay
        // must not hide it again: its reveal stays at the moment it came out. Computed with the round's start and delay
        // as they are now, before the edit below changes them.
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $revealsAt = $roundPuzzle->revealsAt();

            if ($roundPuzzle->hideUntilRoundStarts && $roundPuzzle->revealMode === RoundPuzzleReveal::Automatic && $revealsAt !== null && $revealsAt <= $now) {
                $roundPuzzle->pinRevealAt($revealsAt);
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
        $round->changeRevealDelay($revealDelayMinutes);

        // Null for a solo or pair round (a pair always has 2)
        $round->changeTeamSize($teamSize);

        // An automatic reveal follows the round's start and its delay - the puzzles it keeps secret on the whole site
        // follow too (a longer delay hides them longer). Scheduled and manual reveals are the organiser's own and stay
        // where they are.
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
        }
    }
}
