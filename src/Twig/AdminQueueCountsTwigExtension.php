<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Query\GetAdminQueueCounts;
use SpeedPuzzling\Web\Results\AdminQueueCounts;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The backlog of every review queue, next to its link: the Menu's Your roles rows (docs/features/navigation README
 * §4.12, MENU-01), later the ☰ dot (NAV-03) - called only for admins and moderators, one statement per page
 * (GetAdminQueueCounts). Memoised per request, so every caller on a page shares that one statement; reset between
 * requests (FrankenPHP worker mode).
 */
final class AdminQueueCountsTwigExtension extends AbstractExtension implements ResetInterface
{
    /**
     * @var array<string, AdminQueueCounts> by viewer kind (admin / moderator)
     */
    private array $memo = [];

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
            new TwigFunction('admin_queue_counts', $this->forViewer(...)),
        ];
    }

    public function forViewer(bool $isAdmin): AdminQueueCounts
    {
        return $this->memo[$isAdmin ? 'admin' : 'moderator'] ??= $this->getAdminQueueCounts->forViewer($isAdmin);
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
