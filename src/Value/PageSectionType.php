<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Kinds of organiser-written content sections on an event, edition or series page
 * (docs/features/competitions-management/public-page.md).
 */
enum PageSectionType: string
{
    case RichText = 'rich_text';
    case Faq = 'faq';
    case Gallery = 'gallery';
    case Venue = 'venue';
    case Sponsors = 'sponsors';
    case Links = 'links';
    case Contact = 'contact';

    /**
     * A venue belongs to an event that takes place somewhere: not offered for an online owner, and an online page
     * never shows one (one kept from before the event went online stays stored, it just does not render).
     */
    public function isAvailableFor(bool $isOnline): bool
    {
        return $this !== self::Venue || $isOnline === false;
    }

    /**
     * @return list<self>
     */
    public static function availableFor(bool $isOnline): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type->isAvailableFor($isOnline),
        ));
    }

    public function icon(): string
    {
        return match ($this) {
            self::RichText => 'bi-card-text',
            self::Faq => 'bi-question-circle',
            self::Gallery => 'bi-images',
            self::Venue => 'bi-geo-alt',
            self::Sponsors => 'bi-award',
            self::Links => 'bi-link-45deg',
            self::Contact => 'bi-envelope',
        };
    }
}
