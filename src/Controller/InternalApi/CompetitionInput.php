<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The competition fields of a create / update body, applied onto the web form's data object - so the API validates
 * them by the same rules as the form (CompetitionFormData: required name, location and dates of an in-person event,
 * links that are URLs, at most 30 days long, …). A field left out keeps the value already in `$data`.
 */
final class CompetitionInput
{
    public const array FIELDS = [
        'name',
        'shortcut',
        'description',
        'location',
        'locationCountryCode',
        'dateFrom',
        'dateTo',
        'link',
        'registrationLink',
        'resultsLink',
        'isOnline',
        'eligibility',
        'slug',
        'maintainerIds',
    ];

    /**
     * @return array{slug: null|string, maintainerIds: null|list<string>} the fields that are no part of the form data
     */
    public static function applyTo(InternalApiInput $input, CompetitionFormData $data): array
    {
        if ($input->has('name')) {
            $data->name = $input->string('name');
        }

        if ($input->has('shortcut')) {
            $data->shortcut = $input->string('shortcut');
        }

        if ($input->has('description')) {
            $data->description = $input->string('description');
        }

        if ($input->has('location')) {
            $data->location = $input->string('location');
        }

        if ($input->has('link')) {
            $data->link = $input->string('link');
        }

        if ($input->has('registrationLink')) {
            $data->registrationLink = $input->string('registrationLink');
        }

        if ($input->has('resultsLink')) {
            $data->resultsLink = $input->string('resultsLink');
        }

        // "Who can enter" - an edition without its own shows its series'
        if ($input->has('eligibility')) {
            $data->eligibility = $input->string('eligibility');
        }

        if ($input->has('locationCountryCode')) {
            $countryCode = $input->string('locationCountryCode');
            $country = CountryCode::fromCode($countryCode);

            if ($countryCode !== null && $country === null) {
                $input->addError('locationCountryCode', 'must be an ISO 3166-1 alpha-2 country code, e.g. "cz".');
            }

            $data->locationCountryCode = $country?->name;
        }

        if ($input->has('dateFrom')) {
            $data->dateFrom = $input->date('dateFrom');
        }

        if ($input->has('dateTo')) {
            $data->dateTo = $input->date('dateTo');
        }

        if ($input->has('isOnline')) {
            $data->isOnline = $input->bool('isOnline') ?? false;
        }

        // Like the web form (Add/EditCompetitionController): an online event has no place - also when only
        // `"isOnline": true` is sent. Its dates stay: optional for an online event (empty = ongoing), never thrown away
        if ($data->isOnline === true) {
            $data->location = null;
        }

        $slug = $input->string('slug');

        if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
            $input->addError('slug', 'must be lower-case letters and digits in words joined by single hyphens, e.g. "wjpc-2026".');
        }

        return [
            'slug' => $slug,
            'maintainerIds' => $input->idList('maintainerIds'),
        ];
    }
}
