<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One old surface on "Where did it go?" (LAUNCH-02) with the rows the viewer gets.
 */
final readonly class WhereDidItGoSection
{
    /**
     * @param list<WhereDidItGoRow> $rows
     */
    public function __construct(
        public FormerSurface $surface,
        public array $rows,
    ) {
    }
}
