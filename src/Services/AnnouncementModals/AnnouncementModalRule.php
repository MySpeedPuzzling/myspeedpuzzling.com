<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Who an announcement modal is for. Whether the player already saw it, whether this page may be
 * interrupted at all and the pause between two modals are ResolveAnnouncementModal's business -
 * a rule only answers "is this viewer the audience right now?", from the profile it is handed,
 * without querying anything: it runs on every full page view of every signed-in player.
 */
#[AutoconfigureTag(self::TAG)]
interface AnnouncementModalRule
{
    public const string TAG = 'app.announcement_modal_rule';

    public function modal(): AnnouncementModal;

    public function isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool;
}
