<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `partner_finder_url(competitionSlug, seriesSlug)` - the partner finder page of an event (PartnerFinderUrls), null for
 * an event without a slug.
 */
final class PartnerFinderTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PartnerFinderUrls $partnerFinderUrls,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('partner_finder_url', fn (null|string $competitionSlug, null|string $seriesSlug = null): null|string => $this->partnerFinderUrls->forSlugs($competitionSlug, $seriesSlug)),
        ];
    }
}
