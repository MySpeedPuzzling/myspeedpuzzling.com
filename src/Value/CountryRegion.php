<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The regions the site groups countries by (the sell/swap settings, the events page's country sheet). cases() order is
 * the display order; every country not listed is in RestOfWorld.
 */
enum CountryRegion: string
{
    case CentralEurope = 'central_europe';
    case WesternEurope = 'western_europe';
    case SouthernEurope = 'southern_europe';
    case NorthernEurope = 'northern_europe';
    case EasternEurope = 'eastern_europe';
    case NorthAmerica = 'north_america';
    case RestOfWorld = 'rest_of_world';

    public static function forCountry(CountryCode $country): self
    {
        foreach (self::cases() as $region) {
            if (in_array($country, $region->countries(), true)) {
                return $region;
            }
        }

        return self::RestOfWorld;
    }

    /**
     * The countries listed under the region - empty for RestOfWorld, which takes every other one.
     *
     * @return list<CountryCode>
     */
    public function countries(): array
    {
        return match ($this) {
            self::CentralEurope => [
                CountryCode::cz, CountryCode::sk, CountryCode::pl, CountryCode::hu,
                CountryCode::at, CountryCode::si, CountryCode::ch, CountryCode::li,
            ],
            self::WesternEurope => [
                CountryCode::de, CountryCode::fr, CountryCode::nl, CountryCode::be,
                CountryCode::lu, CountryCode::ie, CountryCode::gb, CountryCode::mc,
            ],
            self::SouthernEurope => [
                CountryCode::es, CountryCode::pt, CountryCode::it, CountryCode::gr,
                CountryCode::hr, CountryCode::ba, CountryCode::rs, CountryCode::me,
                CountryCode::mk, CountryCode::al, CountryCode::mt, CountryCode::cy,
            ],
            self::NorthernEurope => [
                CountryCode::se, CountryCode::no, CountryCode::dk, CountryCode::fi,
                CountryCode::is, CountryCode::ee, CountryCode::lv, CountryCode::lt,
            ],
            self::EasternEurope => [
                CountryCode::ro, CountryCode::bg, CountryCode::ua, CountryCode::md,
                CountryCode::by,
            ],
            self::NorthAmerica => [
                CountryCode::us, CountryCode::ca, CountryCode::mx,
            ],
            self::RestOfWorld => [],
        };
    }

    public function translationKey(): string
    {
        return 'sell_swap_list.settings.region.' . $this->value;
    }
}
