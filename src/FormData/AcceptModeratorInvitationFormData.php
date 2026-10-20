<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class AcceptModeratorInvitationFormData
{
    #[Assert\IsTrue(message: 'Please tick the box to agree - it is what being a moderator is about.')]
    public bool $pledge = false;
}
