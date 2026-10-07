<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\BackfillRoundTimezones;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One-off after rounds kept their zone (2026-10, docs/features/competitions-management/README.md): a round saved before
 * has no zone and is read in its country's default zone, else its series', else the fallback - the zone the round form
 * pre-selected when the start was typed. Saves exactly that zone, so nothing any page shows changes. Idempotent.
 *
 * Also lists the rounds whose start looks moved by the old bug (every save of an untouched form moved a round by its
 * zone's offset): a local start at night, or outside the event's own dates. Those are only listed - which time was
 * meant is in the organiser's published schedule, not in the data.
 */
#[AsMessageHandler]
readonly final class BackfillRoundTimezonesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{changes: list<string>, suspects: list<string>}
     */
    public function __invoke(BackfillRoundTimezones $message): array
    {
        /** @var array<CompetitionRound> $rounds */
        $rounds = $this->entityManager->createQueryBuilder()
            ->select('r', 'c', 's')
            ->from(CompetitionRound::class, 'r')
            ->join('r.competition', 'c')
            ->leftJoin('c.series', 's')
            ->where('r.timezone IS NULL')
            ->orderBy('r.startsAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        $changes = [];
        $suspects = [];

        foreach ($rounds as $round) {
            $timezone = $round->displayTimezone();
            $localStart = RoundTimezone::toLocal($round->startsAt, $timezone);
            $line = sprintf(
                '%s · %s · %s %s%s',
                $this->eventLabel($round),
                $round->name,
                $localStart->format('Y-m-d H:i'),
                $timezone,
                $round->isTimezoneAssumed() ? ' (assumed)' : '',
            );
            $changes[] = $line;

            $reason = $this->suspectReason($round, $localStart);

            if ($reason !== null) {
                $suspects[] = $line . ' - ' . $reason . ' [' . $round->id->toString() . ']';
            }

            if ($message->dryRun === false) {
                $round->saveDisplayedTimezone();
            }
        }

        return ['changes' => $changes, 'suspects' => $suspects];
    }

    private function eventLabel(CompetitionRound $round): string
    {
        $competition = $round->competition;

        return ($competition->series !== null ? $competition->series->slug . '/' : '') . $competition->slug;
    }

    private function suspectReason(CompetitionRound $round, \DateTimeImmutable $localStart): null|string
    {
        $hour = (int) $localStart->format('G');

        if ($hour < 7 || $hour >= 23) {
            return 'starts at night';
        }

        $dateFrom = $round->competition->dateFrom;

        if ($dateFrom !== null) {
            $day = $localStart->format('Y-m-d');
            $dateTo = $round->competition->dateTo ?? $dateFrom;

            if ($day < $dateFrom->format('Y-m-d') || $day > max($dateFrom, $dateTo)->format('Y-m-d')) {
                return 'outside the event dates';
            }
        }

        return null;
    }
}
