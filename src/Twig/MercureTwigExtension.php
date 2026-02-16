<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\MercureTopicCollector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MercureTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly MercureTopicCollector $topicCollector,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('mercure_subscribe_topics', $this->getSubscribeTopics(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function getSubscribeTopics(string $playerId): array
    {
        return $this->topicCollector->getAllTopicsForPlayer($playerId);
    }
}
