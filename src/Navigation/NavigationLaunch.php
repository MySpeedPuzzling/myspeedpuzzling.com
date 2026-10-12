<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * The launch date of the navigation redesign (LAUNCH-00, README §8) - the one place that drives the "What moved"
 * modal, the 30-day pointers and the 14-day expiry of the "New" badges.
 *
 * DATE is null until the owner sets it at release ('Y-m-d', a day in Europe/Prague): no "What moved" modal, no
 * pointers, footer and Menu "New" badges shown - the new chrome may deploy before that. Setting it is a one-line
 * change of DATE (the container parameter navigation.launch_date reads it, config/services.php).
 *
 * Consumers never read DATE: they ask this service, which tests replace in the container with a MockClock and a date
 * (decision R5 - a null constant alone cannot be tested with a set date).
 */
final readonly class NavigationLaunch
{
    public const null|string DATE = null;
    // Pointers (Hub banner, Inbox note, the line at the top of the Menu) expire this many days after DATE
    public const int POINTER_DAYS = 30;
    // "New" badges disappear this many days after DATE (rulebook: "gone after first visit or 14 days")
    public const int NEW_BADGE_DAYS = 14;
    public const string TIMEZONE = 'Europe/Prague';

    private null|DateTimeImmutable $launchedAt;

    public function __construct(
        private ClockInterface $clock,
        null|string $date,
    ) {
        $this->launchedAt = self::parse($date);
    }

    /**
     * Midnight of the launch day in Europe/Prague, or null while the date is not set.
     */
    public function date(): null|DateTimeImmutable
    {
        return $this->launchedAt;
    }

    public function hasLaunched(): bool
    {
        return $this->launchedAt !== null && $this->clock->now() >= $this->launchedAt;
    }

    /**
     * The 30-day pointers: launched, not expired, and only for accounts that knew the old navigation (never guests).
     */
    public function pointersActiveFor(PlayerProfile $viewer): bool
    {
        if ($this->launchedAt === null || !$this->hasLaunched()) {
            return false;
        }

        if ($this->clock->now() >= $this->launchedAt->add(new DateInterval(sprintf('P%dD', self::POINTER_DAYS)))) {
            return false;
        }

        return $this->accountPredatesLaunch($viewer->registeredAt);
    }

    /**
     * Was the account created before the launch day? An account without a registration date is an old one.
     * False while the date is not set.
     */
    public function accountPredatesLaunch(null|DateTimeImmutable $registeredAt): bool
    {
        if ($this->launchedAt === null) {
            return false;
        }

        return $registeredAt === null || $registeredAt < $this->launchedAt;
    }

    /**
     * Footer and Menu "New" badges: shown while the date is not set and until 14 days after it (decision 1).
     */
    public function newBadgesVisible(): bool
    {
        if ($this->launchedAt === null) {
            return true;
        }

        return $this->clock->now() < $this->launchedAt->add(new DateInterval(sprintf('P%dD', self::NEW_BADGE_DAYS)));
    }

    private static function parse(null|string $date): null|DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE));

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(sprintf('The navigation launch date "%s" is not a Y-m-d day', $date));
        }

        return $parsed;
    }
}
