<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ParticipantSource: string
{
    case SelfJoined = 'self_joined';
    case Imported = 'imported';
    case Manual = 'manual';
}
