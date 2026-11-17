<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The "Profile" line of an import preview row (participant-linking.md §7.3): the MySpeedPuzzling profile the person is
 * linked to after the import - "→ #CODE · Name" for a link the import makes, "#CODE · Name (linked by themselves)" for
 * one it keeps. A profile the organiser may not see (private to them, or blocked by them) is never named: "→ the
 * profile <what your file says> from your file".
 */
readonly final class ParticipantImportRowProfile
{
    public function __construct(
        /** The import makes this link (otherwise the participant has it already and keeps it) */
        public bool $linkedNow,
        /** Upper-cased code, null when masked */
        public null|string $code,
        /** Profile name, null when masked or not set */
        public null|string $name,
        /** The viewer may not see who it is */
        public bool $masked,
        /** The file's cell that names the player - shown instead of the profile when masked (the organiser typed it) */
        public null|string $fileReference,
        /** The kept link was made by the player themselves (ParticipantLinkMethod::isThePlayersOwn()) */
        public bool $linkedByThemselves = false,
    ) {
    }
}
