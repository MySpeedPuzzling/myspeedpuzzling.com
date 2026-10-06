<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\NamePublicationNotConfirmed;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyShown;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeRoundPuzzleRevealHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RevealMomentAlreadyPassed
     * @throws RoundPuzzleAlreadyRevealed
     * @throws RoundPuzzleAlreadyShown
     * @throws PuzzleHiddenByHand
     * @throws PuzzleNameAlreadyPublic
     * @throws NamePublicationNotConfirmed
     */
    public function __invoke(ChangeRoundPuzzleReveal $message): void
    {
        $this->secretPuzzleHides->lockRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $now = $this->clock->now();

        // A revealed puzzle is public - it is never hidden again by a reveal change
        if ($roundPuzzle->hideUntilRoundStarts && $roundPuzzle->isHiddenAt($now) === false) {
            throw new RoundPuzzleAlreadyRevealed();
        }

        // A row that was not secret has shown the puzzle on the event page: it becomes secret only before the round
        // starts and while no other round shows the puzzle - never hidden again once it is out
        if ($roundPuzzle->hideUntilRoundStarts === false && $this->isShown($roundPuzzle, $now)) {
            throw new RoundPuzzleAlreadyShown();
        }

        // A reveal that is over already would reveal the puzzle the moment it is saved - that is "Reveal now"
        $newRevealAt = $message->revealMode->revealAt($roundPuzzle->round->startsAt, $message->scheduledAt);
        if ($message->revealMode !== RoundPuzzleReveal::Manual && ($newRevealAt === null || $newRevealAt <= $now)) {
            throw new RevealMomentAlreadyPassed();
        }

        $puzzle = $roundPuzzle->puzzle;
        $puzzleKeptSecret = $this->isPuzzleKeptSecret->byId($puzzle->id->toString());

        // A puzzle hidden by hand (a placeholder) is no round's to hide or reveal
        if ($puzzle->isImageHiddenAt($now) && $puzzleKeptSecret === false) {
            throw new PuzzleHiddenByHand();
        }

        // "Entirely" -> "image only" while the row still hides the name: the name comes out at once, on the event page and
        // (with no other round hiding it entirely) everywhere - and for good. Only on an explicit yes.
        if (
            $roundPuzzle->hideUntilRoundStarts
            && ($roundPuzzle->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely
            && $message->hideMode === PuzzleHideMode::ImageOnly
            && $message->namePublicationConfirmed === false
        ) {
            throw new NamePublicationNotConfirmed();
        }

        // Kept secret on the whole site with its name already public ("image only"): hiding the name again would take it
        // from the times, collections and listings that show it - it can only keep its picture secret
        if ($puzzleKeptSecret && $message->hideMode === PuzzleHideMode::Entirely && $puzzle->isHiddenAt($now) === false) {
            throw new PuzzleNameAlreadyPublic();
        }

        // Kept secret by a competition (created hidden, or another round keeps it so): this row keeps it secret on
        // the whole site too
        if ($puzzleKeptSecret) {
            $roundPuzzle->keepHiddenEverywhere();
        }

        $roundPuzzle->changeReveal($message->hideMode, $message->revealMode, $message->scheduledAt);

        $this->secretPuzzleHides->resync($puzzle);
    }

    /**
     * The same rule as GetRoundPuzzlesForManagement's `may_become_secret` (RoundPuzzleOwnership::sqlShownByAnotherRound()).
     */
    private function isShown(CompetitionRoundPuzzle $roundPuzzle, DateTimeImmutable $now): bool
    {
        if ($roundPuzzle->round->startsAt <= $now) {
            return true;
        }

        foreach ($this->secretPuzzleHides->rowsOf($roundPuzzle->puzzle) as $other) {
            if ($other !== $roundPuzzle && $other->isHiddenAt($now) === false) {
                return true;
            }
        }

        return false;
    }
}
