<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeZone;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The round fields of a create / update body, applied onto the web form's data object and validated by its rules
 * (CompetitionRoundFormData). A field left out keeps the value already in `$data`.
 *
 * `startsAt` is stored in UTC, like the round form stores it: an ISO 8601 date-time with an offset is that moment, one
 * without an offset is a wall-clock time in `timezone` (IANA, e.g. "Europe/Prague") - by default the time zone of the
 * competition's country, as the form preselects it.
 */
final class RoundInput
{
    public const array FIELDS = [
        'name',
        'category',
        'startsAt',
        'timezone',
        'minutesLimit',
        'badgeBackgroundColor',
        'badgeTextColor',
        'resultsLink',
    ];

    private const string DEFAULT_TIMEZONE = 'Europe/Prague';

    public static function applyTo(InternalApiInput $input, CompetitionRoundFormData $data, null|string $competitionCountryCode): void
    {
        if ($input->has('name')) {
            $data->name = $input->string('name');
        }

        if ($input->has('category')) {
            $category = $input->string('category');
            $roundCategory = $category !== null ? RoundCategory::tryFrom($category) : null;

            if ($roundCategory === null) {
                $input->addError('category', sprintf('must be one of: %s.', implode(', ', array_column(RoundCategory::cases(), 'value'))));
            }

            $data->category = $roundCategory ?? $data->category;
        }

        if ($input->has('startsAt')) {
            $data->startsAt = $input->dateTime('startsAt', self::timeZone($input, $competitionCountryCode), required: true);
        }

        if ($input->has('minutesLimit')) {
            $data->minutesLimit = $input->int('minutesLimit', required: true, minimum: 1);
        }

        if ($input->has('badgeBackgroundColor')) {
            $data->badgeBackgroundColor = $input->string('badgeBackgroundColor');
        }

        if ($input->has('badgeTextColor')) {
            $data->badgeTextColor = $input->string('badgeTextColor');
        }

        if ($input->has('resultsLink')) {
            $data->resultsLink = $input->string('resultsLink');
        }
    }

    private static function timeZone(InternalApiInput $input, null|string $competitionCountryCode): DateTimeZone
    {
        $default = CountryCode::fromCode($competitionCountryCode)?->defaultTimezone() ?? self::DEFAULT_TIMEZONE;
        $timezone = $input->string('timezone') ?? $default;

        if (in_array($timezone, DateTimeZone::listIdentifiers(), true) === false) {
            $input->addError('timezone', 'must be an IANA time zone, e.g. "Europe/Prague".');

            return new DateTimeZone($default);
        }

        return new DateTimeZone($timezone);
    }
}
