<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\ChangeParticipantClaimPolicy;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeParticipantClaimPolicyHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function __invoke(ChangeParticipantClaimPolicy $message): void
    {
        $this->competitionRepository
            ->get($message->competitionId)
            ->changeParticipantClaimPolicy($message->policy);
    }
}
