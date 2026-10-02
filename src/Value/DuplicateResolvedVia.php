<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DuplicateResolvedVia: string
{
    case ReviewPage = 'review_page';
    case Recap = 'recap';
    case Form = 'form';
    case Automatic = 'automatic';
    case Admin = 'admin';
}
