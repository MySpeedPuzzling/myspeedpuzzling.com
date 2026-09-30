<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the player chose when the result they are saving collides with a first try they already have.
 * Taking the tag off the new result is no resolution - the checkbox is simply unticked.
 */
enum FirstTryResolution: string
{
    case None = '';
    // The new result becomes the first try, the tag comes off the older ones - only results the player took part in
    case MoveHere = 'move';
}
