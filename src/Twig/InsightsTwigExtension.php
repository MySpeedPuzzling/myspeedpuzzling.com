<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Query\GetInsightsResultCount;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Services\Insights\InsightsFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `insights_visible()` for templates that link to the Insights page (the menu row, the "See why" links): a link to a
 * page that 404s for the viewer is never rendered. The page and the profile tab ask InsightsFeature directly.
 * `insights_see_why_url()` is the "See why" link of the viewer's own result: straight to the section that explains
 * it once the page works (InsightsReport::MIN_RESULTS), the page itself (its empty state says why) before that.
 * Retires with the INSIGHTS_PUBLIC flag (docs/features/feature_flags.md).
 */
final class InsightsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private InsightsFeature $insightsFeature,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetInsightsResultCount $getInsightsResultCount,
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('insights_visible', $this->isVisible(...)),
            new TwigFunction('insights_see_why_url', $this->seeWhyUrl(...)),
        ];
    }

    public function isVisible(): bool
    {
        return $this->insightsFeature->isVisibleTo($this->retrieveLoggedUserProfile->getProfile());
    }

    /**
     * Call only where insights_visible() holds and the result is the viewer's own - one statement
     */
    public function seeWhyUrl(): string
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $url = $this->urlGenerator->generate('insights');

        if ($profile === null || $this->getInsightsResultCount->reaches($profile->playerId, InsightsReport::MIN_RESULTS) === false) {
            return $url;
        }

        return $url . ($profile->activeMembership ? '#insights-expected' : '#insights-median');
    }
}
