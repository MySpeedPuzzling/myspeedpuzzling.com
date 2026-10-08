<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The series fields of a create / update body, applied onto the web form's data object of "Add event" with "Recurring"
 * ticked (CompetitionFormData, `isRecurring = true`) - so the API validates a series by the form's rules: required
 * name, an in-person series needs a location, links that are URLs, "Who can enter" ≤ 120 and "When it happens" ≤ 160
 * characters. A field left out keeps the value already in `$data`.
 */
final class SeriesInput
{
    public const array FIELDS = [
        'name',
        'shortcut',
        'description',
        'link',
        'isOnline',
        'location',
        'locationCountryCode',
        'eligibility',
        'schedule',
        'slug',
        'maintainerIds',
    ];

    public static function formDataOf(CompetitionSeries $series): CompetitionFormData
    {
        $data = new CompetitionFormData(
            name: $series->name,
            shortcut: $series->shortcut,
            description: $series->description,
            link: $series->link,
            location: $series->location,
            locationCountryCode: $series->locationCountryCode,
            isOnline: $series->isOnline,
            isRecurring: true,
            eligibility: $series->eligibility,
            schedule: $series->schedule,
        );

        $maintainerIds = [];

        foreach ($series->maintainers as $maintainer) {
            $maintainerIds[] = $maintainer->id->toString();
        }

        $data->maintainers = $maintainerIds;

        return $data;
    }

    /**
     * @return array{slug: null|string, maintainerIds: null|list<string>} the fields that are no part of the form data
     */
    public static function applyTo(InternalApiInput $input, CompetitionFormData $data): array
    {
        $data->isRecurring = true;

        if ($input->has('name')) {
            $data->name = $input->string('name');
        }

        if ($input->has('shortcut')) {
            $data->shortcut = $input->string('shortcut');
        }

        if ($input->has('description')) {
            $data->description = $input->string('description');
        }

        if ($input->has('link')) {
            $data->link = $input->string('link');
        }

        if ($input->has('location')) {
            $data->location = $input->string('location');
        }

        if ($input->has('eligibility')) {
            $data->eligibility = $input->string('eligibility');
        }

        if ($input->has('schedule')) {
            $data->schedule = $input->string('schedule');
        }

        if ($input->has('locationCountryCode')) {
            $countryCode = $input->string('locationCountryCode');
            $country = CountryCode::fromCode($countryCode);

            if ($countryCode !== null && $country === null) {
                $input->addError('locationCountryCode', 'must be an ISO 3166-1 alpha-2 country code, e.g. "us".');
            }

            $data->locationCountryCode = $country?->name;
        }

        if ($input->has('isOnline')) {
            $data->isOnline = $input->bool('isOnline') ?? false;
        }

        // Like the web form: an online series has no place
        if ($data->isOnline === true) {
            $data->location = null;
        }

        $slug = $input->string('slug');

        if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
            $input->addError('slug', 'must be lower-case letters and digits in words joined by single hyphens, e.g. "lantern-nights".');
        }

        return [
            'slug' => $slug,
            'maintainerIds' => $input->idList('maintainerIds'),
        ];
    }
}
