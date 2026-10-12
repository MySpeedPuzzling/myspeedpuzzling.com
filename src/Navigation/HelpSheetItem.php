<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One item of the Help sheet in its order (HelpSheetBuilder::items()): its id is the data-help-item value, the registry
 * entry gives the label, icon and description where it has one.
 */
final readonly class HelpSheetItem
{
    public function __construct(
        public string $id,
        public HelpSheetItemKind $kind,
        public null|NavEntry $entry = null,
    ) {
    }
}
