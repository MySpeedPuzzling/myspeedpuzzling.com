<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\Player;
use Symfony\Component\Security\Core\User\UserInterface;

interface ApiUser extends UserInterface
{
    public function getPlayer(): Player;
}
