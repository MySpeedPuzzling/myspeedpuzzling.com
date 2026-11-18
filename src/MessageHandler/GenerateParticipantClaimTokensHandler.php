<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\GenerateParticipantClaimTokens;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Personal claim links for the people of the event who need one (docs/features/competitions-management/
 * participant-linking.md §5.2): on the list, not linked to an account, without a link yet - made when the organiser
 * hands them out (copy, CSV, claim slips), like OnlineLinkList gives the missing personal links when its page opens.
 * Links already made stay as they are. Under the event's participants lock - two runs never give one person two links.
 */
#[AsMessageHandler]
readonly final class GenerateParticipantClaimTokensHandler
{
    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
    ) {
    }

    /**
     * @return int how many participants got a claim link
     */
    public function __invoke(GenerateParticipantClaimTokens $message): int
    {
        $given = 0;

        foreach ($this->competitionParticipantRepository->unlinkedWithoutClaimToken(strtolower($message->competitionId), $message->participantIds) as $participant) {
            $participant->assignClaimToken(OnlineToken::generate());
            $given++;
        }

        return $given;
    }
}
