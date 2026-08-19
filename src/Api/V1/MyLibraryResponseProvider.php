<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\PuzzleLibrarySummaryFactory;

/**
 * GET /api/v1/me/library - the token owner's own puzzle library summary; the
 * owner always sees every section of their own library.
 *
 * @implements ProviderInterface<LibraryResponse>
 */
final readonly class MyLibraryResponseProvider implements ProviderInterface
{
    public function __construct(
        private ApiTokenOwner $tokenOwner,
        private PuzzleLibrarySummaryFactory $summaryFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LibraryResponse
    {
        // access_control admits only tokens with a player behind them (PAT, auth-code),
        // so the owner is always there; the profile is loaded once and memoised.
        $profile = $this->tokenOwner->profile() ?? throw new PlayerNotFound();

        return $this->summaryFactory->summary($profile);
    }
}
