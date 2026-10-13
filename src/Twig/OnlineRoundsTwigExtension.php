<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkUrl;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundTools;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Online timing in templates (docs/features/online-competitions/):
 * - `online_round_tools(roundId)` - the event of a round that uses online timing when the viewer may set it up, null
 *   otherwise (OnlineRoundTools); official_results/_round_tool_links.html.twig asks it for rounds of online events only;
 * - `online_link_url(token)` - the address of a personal link (OnlineLinkUrl).
 */
final class OnlineRoundsTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private OnlineRoundTools $onlineRoundTools,
        readonly private OnlineLinkUrl $onlineLinkUrl,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('online_round_tools', $this->onlineRoundTools->forRound(...)),
            new TwigFunction('online_link_url', $this->onlineLinkUrl->of(...)),
        ];
    }
}
