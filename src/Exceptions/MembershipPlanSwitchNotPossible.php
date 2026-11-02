<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The member may not switch plans right now - another price (early adopter), cancelled, paused, a trial,
 * a change already booked, or the feature is off. Nothing was asked of Stripe yet.
 */
final class MembershipPlanSwitchNotPossible extends \Exception
{
}
