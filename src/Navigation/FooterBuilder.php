<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Twig\HelpTwigExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The site footer's columns (docs/features/navigation README §4.11, FOOT-01) from the registry's footer placements:
 * labels and icons as on every other surface, the links the footer always had.
 *
 * - A link goes to the entry's own route - the same for everybody, as it always did: a page behind sign-in stays linked
 *   for guests (README §4.11 "every route linked from today's footer stays linked"; NAV-12), so the guests' Add is the
 *   Add page too (the Add tab and the Menu row lead guests to its explainer, add_intro).
 * - Send feedback opens the Help sheet (help_path(), help_opener_attrs()): guests get "Sign in to send feedback" in it.
 * - Who gets a link is the placement's audience (FooterPlacement): everybody, but the XP pages behind the XP gate and
 *   "Where did it go?" for signed-in players only (R2).
 *
 * No statement and no state: built from the registry, the viewer (built once per request) and the request.
 */
final readonly class FooterBuilder
{
    public function __construct(
        private NavigationRegistry $registry,
        private NavigationViewerFactory $viewerFactory,
        private UrlGeneratorInterface $urlGenerator,
        private HelpTwigExtension $help,
    ) {
    }

    /**
     * @return list<array{column: FooterColumn, links: list<FooterLink>}>
     */
    public function columns(): array
    {
        $viewer = $this->viewerFactory->current();
        $columns = [];

        foreach ($this->registry->footer() as $entry) {
            $placement = $entry->footer;
            assert($placement !== null);

            if (!$viewer->sees($placement->visibility)) {
                continue;
            }

            $help = $entry->kind === RowKind::HelpSheet;

            $columns[$placement->column->value]['column'] = $placement->column;
            $columns[$placement->column->value]['links'][] = new FooterLink(
                entry: $entry,
                href: $help ? $this->help->helpPath() : $this->href($entry),
                nav: $placement->navFor($entry->id),
                nofollow: $placement->nofollow,
                newBadge: $placement->newBadge,
                helpSheet: $help,
            );
        }

        return array_values($columns);
    }

    private function href(NavEntry $entry): string
    {
        $target = $entry->target;
        assert($target->route !== null && !$target->needsViewer(), sprintf('Footer entry "%s" needs a route of its own', $entry->id));

        $url = $this->urlGenerator->generate($target->route, $target->params);

        return $target->fragment !== null ? $url . '#' . $target->fragment : $url;
    }
}
