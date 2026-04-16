<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Entity\Badge;

final class SavedBadgeRecorder
{
    /** @var list<Badge> */
    public array $saved = [];
}
