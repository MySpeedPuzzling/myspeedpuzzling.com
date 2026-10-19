<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\Insights\InsightsFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `insights_visible()` for templates that link to the Insights page (the menu row, the "See why" links): a link to a
 * page that 404s for the viewer is never rendered. The page and the profile tab ask InsightsFeature directly.
 * Retires with the INSIGHTS_PUBLIC flag (docs/features/feature_flags.md).
 */
final class InsightsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private InsightsFeature $insightsFeature,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('insights_visible', $this->isVisible(...)),
        ];
    }

    public function isVisible(): bool
    {
        return $this->insightsFeature->isVisibleTo($this->retrieveLoggedUserProfile->getProfile());
    }
}
