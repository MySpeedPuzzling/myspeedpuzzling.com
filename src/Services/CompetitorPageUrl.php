<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public competitor page of a scanned tag (docs/features/competitions-management/toolkit/B-qr-competitor-page.md
 * §4.1) - where a tag of a competition with competitor pages on leads anybody but its organisers and referees.
 *
 * The contract with package B4, which builds the page: one localized route named `competitor_page`, parameters
 * `competitionId`, `kind` (the tag's letter p|g|t|e, CompetitionTagKind) and `id` (participant id, line-up id, table
 * number, entry id) - `/en/competitor/{competitionId}/{kind}/{id}`. Null while the route does not exist yet: the scan
 * then opens the event page as before.
 * TODO(B4): drop the fallback once the route exists (and flip LiveResultsTagRoutesTest's competitor page case).
 */
final readonly class CompetitorPageUrl
{
    public const string ROUTE = 'competitor_page';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function of(string $competitionId, CompetitionTag $tag, string $locale): null|string
    {
        try {
            return $this->urlGenerator->generate(self::ROUTE, [
                'competitionId' => strtolower($competitionId),
                'kind' => $tag->kind->value,
                'id' => $tag->id,
                '_locale' => $locale,
            ]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
