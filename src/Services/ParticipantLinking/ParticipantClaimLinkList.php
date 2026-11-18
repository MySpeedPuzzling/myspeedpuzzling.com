<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use SpeedPuzzling\Web\Message\GenerateParticipantClaimTokens;
use SpeedPuzzling\Web\Query\GetParticipantClaimLinks;
use SpeedPuzzling\Web\Results\ParticipantClaimLinkRow;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The personal claim links the organiser hands out - the claim slips and the claim links CSV (docs/features/
 * competitions-management/participant-linking.md §7.4): everybody on the list not linked to an account, by name. The
 * links are made when the organiser opens either (§5.2, the OnlineLinkList precedent): GenerateParticipantClaimTokens
 * for whoever has none, then the list is read again - never a list with a gap, and a view without anybody missing a link
 * writes nothing.
 */
readonly final class ParticipantClaimLinkList
{
    public function __construct(
        private GetParticipantClaimLinks $getParticipantClaimLinks,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return list<ParticipantClaimLinkRow> every row with its token
     */
    public function forCompetition(string $competitionId): array
    {
        $rows = $this->getParticipantClaimLinks->ofCompetition($competitionId);

        if (array_any($rows, static fn (ParticipantClaimLinkRow $row): bool => $row->token === null)) {
            $this->messageBus->dispatch(new GenerateParticipantClaimTokens($competitionId));
            $rows = $this->getParticipantClaimLinks->ofCompetition($competitionId);
        }

        // Linked between the two reads keeps no link to hand out
        return array_values(array_filter($rows, static fn (ParticipantClaimLinkRow $row): bool => $row->token !== null));
    }
}
