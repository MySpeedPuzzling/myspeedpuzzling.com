<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\Api\PuzzleLibrarySummaryFactory;

/**
 * GET /api/v1/players/{playerId}/library - another player's puzzle library
 * summary under the website's visibility rules: a section the player keeps
 * private, and every section of a private profile, reports count 0 and
 * visibility "private" (never 403, like every /players/{id} endpoint); the
 * player behind an authorization-code token gets their complete library, as
 * under /me. PuzzleLibrarySummaryFactory applies the rules per section.
 *
 * @implements ProviderInterface<LibraryResponse>
 */
final readonly class PlayerLibraryResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private PuzzleLibrarySummaryFactory $summaryFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LibraryResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        // validates the id, 404 for an unknown player
        $profile = $this->getPlayerProfile->byId($playerId);

        return $this->summaryFactory->summary($profile);
    }
}
