<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\InboxBadge;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * inbox_badge(): the signed-in viewer's Inbox badge parts (null for guests) for templates/inbox/_badge.html.twig and
 * _link.html.twig - one statement per page however many instances render (InboxBadge, docs/features/navigation INB-02).
 *
 * inbox_link_strings(): true for the page's first Inbox link only - it carries the hrefs and the accessible names a
 * realtime push picks from (assets/inbox_badge.js reads them there for every Inbox link of the page). The top bar's
 * link renders first; the Menu's row then leaves them out (the chrome's weight, README §4.12) - and keeps them where
 * it is the first, e.g. the `menu` page in the app. Kept on the request, so the service holds no state.
 */
final class InboxTwigExtension extends AbstractExtension
{
    public const string LINK_STRINGS_ATTRIBUTE = '_inbox_link_strings';

    public function __construct(
        readonly private InboxBadge $inboxBadge,
        readonly private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_badge', $this->inboxBadge->forViewer(...)),
            new TwigFunction('inbox_link_strings', $this->inboxLinkStrings(...)),
        ];
    }

    public function inboxLinkStrings(): bool
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return true;
        }

        if ($request->attributes->get(self::LINK_STRINGS_ATTRIBUTE) === true) {
            return false;
        }

        $request->attributes->set(self::LINK_STRINGS_ATTRIBUTE, true);

        return true;
    }
}
