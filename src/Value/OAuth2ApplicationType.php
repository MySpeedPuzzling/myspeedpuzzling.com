<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum OAuth2ApplicationType: string
{
    case Confidential = 'confidential';
    case Public = 'public';
}
