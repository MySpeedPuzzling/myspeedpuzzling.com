<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum CollectionVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
