<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's answer to an invitation to the studio (AnswerStudioInvite, `compete_api_studio_answer`): "Join" puts
 * them on air, "Not now" declines.
 */
enum StudioInviteAnswer: string
{
    case Join = 'join';
    case Decline = 'decline';
}
