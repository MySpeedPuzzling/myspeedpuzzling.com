<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `xp_system_visible()` for templates that only LINK to the gamification pages —
 * navigation, footer, ladder CTAs. Components and controllers gate themselves through
 * XpFeatureGate directly; this exists so a link to a page that 404s for the viewer is
 * never rendered (docs/features/xp-levels/leak-inventory.md §SEO / discovery).
 *
 * Retires with the flag on XP launch day.
 */
final class XpTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('xp_system_visible', $this->isVisible(...)),
        ];
    }

    public function isVisible(): bool
    {
        return $this->xpFeatureGate->isVisibleFor($this->retrieveLoggedUserProfile->getProfile());
    }
}
