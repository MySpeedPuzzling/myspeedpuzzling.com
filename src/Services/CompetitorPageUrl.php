<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public competitor page of a scanned tag (docs/features/competitions-management/toolkit/B-qr-competitor-page.md
 * §4.1) - where a tag of a competition with competitor pages on leads anybody but its organisers and referees.
 *
 * One localized route named `competitor_page` (CompetitorPageController, package B4), parameters `competitionId`,
 * `kind` (the tag's letter p|g|t|e, CompetitionTagKind) and `id` (participant id, line-up id, table number, entry id) -
 * `/en/competitor/{competitionId}/{kind}/{id}`.
 */
final readonly class CompetitorPageUrl
{
    public const string ROUTE = 'competitor_page';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function of(string $competitionId, CompetitionTag $tag, string $locale): string
    {
        return $this->urlGenerator->generate(self::ROUTE, [
            'competitionId' => strtolower($competitionId),
            'kind' => $tag->kind->value,
            'id' => $tag->id,
            '_locale' => $locale,
        ]);
    }
}
