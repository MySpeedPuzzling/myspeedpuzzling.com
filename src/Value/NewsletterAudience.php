<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who an e-mail address in the newsletter audience belongs to: a registered
 * player (subscription state lives on Player::$newsletterEnabled) or a guest
 * subscriber from the public signup form (NewsletterSubscriber entity).
 */
enum NewsletterAudience: string
{
    case Player = 'player';
    case Guest = 'guest';
}
