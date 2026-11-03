<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Suppress every stored address a mistyped domain makes unreachable (GetMistypedEmailAddresses), like a domain that is
 * not found: no more notifications, the player's banner asks for a working address, transactional mail is still tried
 */
final readonly class SuppressMistypedEmailAddresses
{
}
