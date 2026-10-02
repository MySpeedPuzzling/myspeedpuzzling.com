<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class DuplicateClassification
{
    public function __construct(
        public DuplicateTier $tier,
        public DuplicateKind $kind,
    ) {
    }
}
