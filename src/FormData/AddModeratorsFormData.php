<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class AddModeratorsFormData
{
    /**
     * @var array<string>
     */
    #[Assert\Count(min: 1, minMessage: 'admin.moderators.choose_player')]
    #[Assert\All([new Assert\Uuid(strict: false)])]
    public array $players = [];
}
