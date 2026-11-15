<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Message\DecideImportTimeCases;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspicious;
use SpeedPuzzling\Web\Message\TrustSolvingTime;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeQueue;
use SpeedPuzzling\Web\Results\SuspiciousTimeImportCard;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An import card decided as a whole (DecideImportTimeCases): the pending cases **the card showed**, each through the single card's own
 * message - so a decision is recorded per case exactly as today (suspicious_time_decision), a case that changed
 * meanwhile (decided by somebody else, its time edited, taken back with its import) is passed over and counted, and
 * the player is told by the notice run as for any mark: per result a notice, shown as one group (below).
 *
 * A mark shows the player every reason of the case a player may read - the reasons the scan found for that very
 * result - with the one note typed for the group.
 */
#[AsMessageHandler]
readonly final class DecideImportTimeCasesHandler
{
    /** Far more than a card holds: every pending case of the import is compared with what the card showed */
    private const int CASES_READ = 5000;

    public function __construct(
        private GetSuspiciousTimeQueue $getSuspiciousTimeQueue,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{decided: int, changed: int, more: bool}
     */
    public function __invoke(DecideImportTimeCases $message): array
    {
        $pending = $this->getSuspiciousTimeQueue->importCardCases($message->batchId, $message->direction, self::CASES_READ);
        $seen = array_fill_keys($message->seen, true);
        // Only what the moderator looked at: the cases the card showed, still pending for the entry it showed. A case
        // raised since the card was rendered, or one whose time was changed, is not decided unseen
        $cases = array_values(array_filter(
            $pending,
            static fn (array $case): bool => isset($seen[SuspiciousTimeImportCard::seenMark($case['caseId'], $case['fingerprint'])]),
        ));
        $more = count($cases) > DecideImportTimeCases::LIMIT;
        $decided = 0;
        // On the card and not decidable any more (decided by somebody else, edited, taken back with the import)
        $changed = max(0, count($seen) - count($cases));
        // Only the case's own reasons a player may read are kept by the handler - every code may be offered
        $everyReason = array_map(static fn (SuspiciousTimeReasonCode $code): string => $code->value, SuspiciousTimeReasonCode::cases());

        foreach (array_slice($cases, 0, DecideImportTimeCases::LIMIT) as $case) {
            try {
                $this->messageBus->dispatch($message->mark
                    ? new MarkSolvingTimeSuspicious($case['caseId'], $message->decidedById, $everyReason, $message->note, $case['fingerprint'])
                    : new TrustSolvingTime($case['caseId'], $message->decidedById, $message->note, $case['fingerprint'], SuspiciousTimeCaseStatus::Pending));
                $decided++;
            } catch (HandlerFailedException $exception) {
                $cause = $exception->getPrevious();

                if ($cause instanceof SuspiciousTimeCaseChanged === false && $cause instanceof SuspiciousTimeCaseNotFound === false) {
                    throw $exception;
                }

                // Rolled back alone: the others of the group are decided all the same
                $this->entityManager->clear();
                $changed++;
            } catch (SuspiciousTimeCaseNotFound) {
                $this->entityManager->clear();
                $changed++;
            }
        }

        return ['decided' => $decided, 'changed' => $changed, 'more' => $more || count($pending) > count($cases)];
    }
}
