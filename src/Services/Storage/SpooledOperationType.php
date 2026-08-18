<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

enum SpooledOperationType: string
{
    case Write = 'write';
    case Delete = 'delete';
}
