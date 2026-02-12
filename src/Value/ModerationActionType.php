<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ModerationActionType: string
{
    case Warning = 'warning';
    case TemporaryMute = 'temporary_mute';
    case MarketplaceBan = 'marketplace_ban';
    case ListingRemoved = 'listing_removed';
    case MuteLifted = 'mute_lifted';
    case BanLifted = 'ban_lifted';
}
