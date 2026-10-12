<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\InboxBadge;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * inbox_badge(): the signed-in viewer's Inbox badge parts (null for guests) for templates/inbox/_badge.html.twig and
 * _link.html.twig - one statement per page however many instances render (InboxBadge, docs/features/navigation INB-02).
 */
final class InboxTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private InboxBadge $inboxBadge,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_badge', $this->inboxBadge->forViewer(...)),
        ];
    }
}
