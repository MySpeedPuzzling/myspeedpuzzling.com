<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Moderators;

/**
 * The moderator guide (templates/moderators/guide.html.twig) - what an invited player reads and agrees to before
 * becoming a moderator. An accepted invitation stores the version it was accepted under
 * (ModeratorInvitation::$agreedGuideVersion): bump it when what we ask of moderators changes, not for wording.
 */
final class ModeratorGuide
{
    public const string VERSION = '2026-10';

    public const string MOTTO = 'Leave it better than you found it.';
}
