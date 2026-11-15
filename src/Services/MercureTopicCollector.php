<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use Symfony\Contracts\Service\ResetInterface;

final class MercureTopicCollector implements ResetInterface
{
    /** @var list<string> */
    private array $topics = [];

    public function addTopic(string $topic): void
    {
        if (!in_array($topic, $this->topics, true)) {
            $this->topics[] = $topic;
        }
    }

    /** @return list<string> */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /** @return list<string> */
    public function getAllTopicsForPlayer(string $playerId): array
    {
        $baseTopics = [
            '/unread-count/' . $playerId,
            '/conversations/' . $playerId,
            // The progress of the player's spreadsheet imports (ImportProgress). One topic per player, not per
            // import: a topic of one page would be dropped whenever another request rewrites the subscribe cookie
            ImportProgress::topicOf($playerId),
        ];

        $allTopics = $baseTopics;
        foreach ($this->topics as $topic) {
            if (!in_array($topic, $allTopics, true)) {
                $allTopics[] = $topic;
            }
        }

        return $allTopics;
    }

    public function reset(): void
    {
        $this->topics = [];
    }
}
