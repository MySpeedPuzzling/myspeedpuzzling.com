<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Query\GetEventGetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyChecklist;
use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRoundFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The "Get ready" checklist on an event's edit page (W2 of the event creation wizard,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10, the Shopify setup-guide pattern of
 * §2): every GetReadyItem asked over one set of facts (GetEventGetReadyFacts - two statements, whatever the number of
 * rounds).
 *
 * Null = no checklist: nobody signed in, and once the event is over (every round finished; an event without rounds:
 * after its last day). The caller has checked COMPETITION_EDIT.
 */
readonly final class EventGetReadyChecklist
{
    // A round without a time limit counts as finished this long after its start
    public const int MINUTES_WITHOUT_LIMIT = 180;

    // Set by the wizard's Publish: the edit page shows the checklist open under "Published - here's what's left"
    public const string PUBLISHED_FLASH = 'event_wizard_published';

    /**
     * @param iterable<GetReadyItem> $items
     */
    public function __construct(
        private GetEventGetReadyFacts $getEventGetReadyFacts,
        private Security $security,
        #[AutowireIterator(GetReadyItem::class)]
        private iterable $items,
    ) {
    }

    public function of(string $competitionId, null|string $playerId): null|GetReadyChecklist
    {
        if ($playerId === null) {
            return null;
        }

        $facts = $this->getEventGetReadyFacts->of(
            $competitionId,
            $this->security->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId),
        );

        return self::over($facts) ? null : $this->build($facts);
    }

    public function build(GetReadyFacts $facts): GetReadyChecklist
    {
        $items = [...$this->items];
        usort($items, static fn (GetReadyItem $a, GetReadyItem $b): int => $a->order() <=> $b->order());

        return new GetReadyChecklist(
            competitionId: $facts->competitionId,
            rows: array_values(array_filter(
                array_map(static fn (GetReadyItem $item): null|GetReadyRow => $item->row($facts), $items),
                static fn (null|GetReadyRow $row): bool => $row !== null,
            )),
            dateFrom: $facts->dateFrom,
            roundsCount: count($facts->rounds),
        );
    }

    /**
     * The event is over: every round finished (its stopwatch stopped, or its start + time limit has passed), or - without
     * rounds - its last day has passed.
     */
    public static function over(GetReadyFacts $facts): bool
    {
        if ($facts->rounds === []) {
            $lastDay = $facts->dateTo ?? $facts->dateFrom;

            return $lastDay !== null && $lastDay->setTime(23, 59, 59) < $facts->now;
        }

        foreach ($facts->rounds as $round) {
            if (self::roundFinished($round, $facts) === false) {
                return false;
            }
        }

        return true;
    }

    private static function roundFinished(GetReadyRoundFacts $round, GetReadyFacts $facts): bool
    {
        if ($round->stopwatchStatus === RoundStopwatchStatus::STOPPED) {
            return true;
        }

        $start = $round->stopwatchStatus === RoundStopwatchStatus::RUNNING && $round->stopwatchStartedAt !== null
            ? $round->stopwatchStartedAt
            : $round->startsAt;
        $minutes = $round->minutesLimit ?? self::MINUTES_WITHOUT_LIMIT;

        return $start->modify('+' . $minutes . ' minutes') < $facts->now;
    }
}
