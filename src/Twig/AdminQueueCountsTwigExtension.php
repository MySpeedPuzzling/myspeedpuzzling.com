<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Query\GetAdminQueueCounts;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The backlog of every review queue, next to its link in the key menu - called only for admins and moderators
 * (inside PUZZLE_MODERATION_ACCESS in base.html.twig), one statement (GetAdminQueueCounts).
 */
final class AdminQueueCountsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private GetAdminQueueCounts $getAdminQueueCounts,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_queue_counts', $this->getAdminQueueCounts->forViewer(...)),
        ];
    }
}
