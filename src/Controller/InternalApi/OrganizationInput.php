<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * The organization fields of a create / update body, applied onto the web form's data object - so the API validates
 * them by the same rules as the form (OrganizationFormData: required name, at most 10 social links, http(s) URLs, …).
 * A field left out keeps the value already in `$data`.
 */
final class OrganizationInput
{
    public const array FIELDS = [
        'name',
        'shortName',
        'slug',
        'about',
        'website',
        'socialLinks',
        'countryCode',
        'region',
        'kind',
        'maintainerIds',
    ];

    /**
     * @return array{slug: null|string, maintainerIds: null|list<string>} the fields that are no part of the form data
     */
    public static function applyTo(InternalApiInput $input, OrganizationFormData $data): array
    {
        if ($input->has('name')) {
            $data->name = $input->string('name');
        }

        if ($input->has('shortName')) {
            $data->shortName = $input->string('shortName');
        }

        if ($input->has('about')) {
            $data->about = $input->string('about');
        }

        if ($input->has('website')) {
            $data->website = $input->string('website');
        }

        if ($input->has('socialLinks')) {
            // null or [] removes every link
            $data->socialLinks = $input->stringList('socialLinks') ?? [];
        }

        if ($input->has('region')) {
            $data->region = $input->string('region');
        }

        if ($input->has('countryCode')) {
            $countryCode = $input->string('countryCode');
            $country = CountryCode::fromCode($countryCode);

            if ($countryCode !== null && $country === null) {
                $input->addError('countryCode', 'must be an ISO 3166-1 alpha-2 country code, e.g. "us".');
            }

            $data->countryCode = $country?->name;
        }

        if ($input->has('kind')) {
            $kind = $input->string('kind');
            $data->kind = $kind !== null ? OrganizationKind::tryFrom($kind) : null;

            if ($kind !== null && $data->kind === null) {
                $input->addError('kind', sprintf(
                    'must be one of: %s - or null.',
                    implode(', ', array_map(static fn (OrganizationKind $case): string => $case->value, OrganizationKind::cases())),
                ));
            }
        }

        $slug = $input->string('slug');

        if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
            $input->addError('slug', 'must be lower-case letters and digits in words joined by single hyphens, e.g. "riverbend-jigsaw".');
        }

        return [
            'slug' => $slug,
            'maintainerIds' => $input->idList('maintainerIds'),
        ];
    }
}
