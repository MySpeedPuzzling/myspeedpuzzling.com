<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The round fields of a create / update body, applied onto the web form's data object and validated by its rules
 * (CompetitionRoundFormData). A field left out keeps the value already in `$data`.
 *
 * A round keeps its time zone (`timezone`, IANA, e.g. "America/Chicago") - its times are shown and typed in it, like
 * on the organiser's form. `$data->timezone` comes in set: the round's own zone on an update, the zone of the event's
 * other rounds (else its country's) on a create. `startsAt` is the moment the round starts: an ISO 8601 date-time
 * with an offset is that moment, one without an offset is a wall-clock time in the round's zone (the one sent along,
 * else the round's) - refused when a daylight-saving change skips or repeats it. Stored in UTC.
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

    /**
     * @return null|DateTimeImmutable the start (UTC) when the body sends `startsAt`, null when it keeps the stored one
     */
    public static function applyTo(InternalApiInput $input, CompetitionRoundFormData $data): null|DateTimeImmutable
    {
        assert($data->timezone !== null);

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

        // A zone alone would leave open whether the round keeps its moment or its wall-clock time
        if ($input->has('timezone') && $input->has('startsAt') === false) {
            $input->addError('timezone', 'is changed together with "startsAt" only - send both.');
        }

        if ($input->has('timezone')) {
            $timezone = $input->string('timezone', required: true);

            if ($timezone !== null && RoundTimezone::isValid($timezone) === false) {
                $input->addError('timezone', 'must be an IANA time zone, e.g. "Europe/Prague".');
            } elseif ($timezone !== null) {
                $data->timezone = $timezone;
            }
        }

        $startsAt = null;

        if ($input->has('startsAt')) {
            $startsAt = $input->dateTime('startsAt', new DateTimeZone($data->timezone), required: true);

            if ($startsAt !== null) {
                // The form data carries the round's local wall clock (CompetitionRoundFormData::fromCompetitionRound())
                $data->startsAt = new DateTimeImmutable(RoundTimezone::toLocal($startsAt, $data->timezone)->format('Y-m-d H:i:s'));
            }
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

        return $startsAt;
    }
}
