<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Message\GenerateOnlineTokens;
use SpeedPuzzling\Web\Query\GetOnlineLinks;
use SpeedPuzzling\Web\Results\OnlineLinkRow;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The personal links the organiser hands out - the links page, its CSV and its QR sheet (docs/features/online-competitions/
 * README.md §5.4): every participant who is not removed, by name. Somebody added after online timing was turned on gets
 * a link here, when the organiser opens any of the three ("generated when the organiser turns on online timing, or
 * opens the links page"): GenerateOnlineTokens for whoever is going without one, then the list is read again - so the
 * three never show a list with a gap, and a page view without anybody missing a link writes nothing.
 */
readonly final class OnlineLinkList
{
    public function __construct(
        private GetOnlineLinks $getOnlineLinks,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @param bool $usesOnlineTiming a round of the event uses online timing - without one, links would open nothing
     * @return list<OnlineLinkRow>
     */
    public function forCompetition(string $competitionId, null|string $viewerPlayerId, bool $usesOnlineTiming): array
    {
        $rows = $this->getOnlineLinks->ofCompetition($competitionId, $viewerPlayerId);

        if ($usesOnlineTiming && array_any($rows, static fn (OnlineLinkRow $row): bool => $row->lacksLink())) {
            $this->messageBus->dispatch(new GenerateOnlineTokens($competitionId));
            $rows = $this->getOnlineLinks->ofCompetition($competitionId, $viewerPlayerId);
        }

        return $rows;
    }
}
