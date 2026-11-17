<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the tags are laid out on paper (competition_name_tags `?layout=`, B-qr-competitor-page.md §3.4): `tags` - the
 * A4 sheet of 90 × 55 mm tags of before (2 × 5), cut along the dashed lines; `tents` - one table tent card per A4
 * landscape page, folded in half along its middle with the upper half printed upside down, so it reads from both sides of
 * a table.
 */
enum CompetitionTagPrintLayout: string
{
    case Tags = 'tags';
    case Tents = 'tents';
}
