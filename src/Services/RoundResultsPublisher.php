<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\RoundResult;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes round-results changes on the Mercure topic /round-results/{roundId}.
 *
 * Payloads are built from the entity (not a DB query) so they are correct even
 * before the surrounding transaction commits. Subscribed consoles merge the row
 * into their local state and re-rank client-side.
 */
readonly final class RoundResultsPublisher
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function publishResultChanged(RoundResult $result): void
    {
        $this->publish($result->round->id->toString(), [
            'type' => 'result_changed',
            'result' => [
                'resultId' => $result->id->toString(),
                'participantId' => $result->participant?->id->toString(),
                'teamId' => $result->team?->id->toString(),
                'entrantName' => $result->participant !== null ? $result->participant->name : $result->team?->name,
                'secondsToSolve' => $result->secondsToSolve,
                'missingPieces' => $result->missingPieces,
            ],
        ]);
    }

    public function publishResultDeleted(RoundResult $result): void
    {
        $this->publish($result->round->id->toString(), [
            'type' => 'result_deleted',
            'resultId' => $result->id->toString(),
        ]);
    }

    public function publishPublicationChanged(string $roundId, bool $published): void
    {
        $this->publish($roundId, [
            'type' => 'publication_changed',
            'published' => $published,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $roundId, array $payload): void
    {
        $this->hub->publish(new Update(
            '/round-results/' . $roundId,
            json_encode($payload, JSON_THROW_ON_ERROR),
            private: false,
        ));
    }
}
