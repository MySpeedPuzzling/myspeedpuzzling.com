<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

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

    public function reset(): void
    {
        $this->topics = [];
    }
}
