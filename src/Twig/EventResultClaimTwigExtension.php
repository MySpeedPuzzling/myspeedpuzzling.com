<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\EventResultsImport\EventResultClaimability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `can_claim_event_result(players, competitionId)` - "This was my team" on an imported event result (EventResultClaimability).
 */
final class EventResultClaimTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private EventResultClaimability $eventResultClaimability,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    /**
     * @param iterable<object> $players
     */
    public function canClaim(iterable $players, null|string $competitionId = null): bool
    {
        return $this->eventResultClaimability->canClaim($players, $competitionId);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_claim_event_result', $this->canClaim(...)),
        ];
    }
}
