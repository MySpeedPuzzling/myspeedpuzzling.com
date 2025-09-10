<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Modal
{
    public null|string $id = null;
}
