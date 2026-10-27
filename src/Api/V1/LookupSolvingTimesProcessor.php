<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use SpeedPuzzling\Web\Query\GetSolvingTimesLookup;
use SpeedPuzzling\Web\Results\DeletedSolvingTime;
use SpeedPuzzling\Web\Results\FoundSolvingTime;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\SolvingTimeSyncRateLimit;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Read-only despite the POST (docs/features/api/solving-time-sync.md) - nothing is written.
 *
 * @implements ProcessorInterface<LookupSolvingTimesInput, SolvingTimesLookupResponse>
 */
final readonly class LookupSolvingTimesProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private GetSolvingTimesLookup $getSolvingTimesLookup,
        private SolvingTimeSyncRateLimit $rateLimit,
    ) {
    }

    /**
     * @param LookupSolvingTimesInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimesLookupResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        // After validation (it runs before the processor): a 422 never consumes the budget
        $this->rateLimit->consume();

        $timeIds = array_values(array_unique(array_map(
            static fn (string $timeId): string => strtolower($timeId),
            $data->timeIds,
        )));

        $lookup = $this->getSolvingTimesLookup->forPlayer($user->getPlayer()->id->toString(), $timeIds);

        return new SolvingTimesLookupResponse(
            found: array_map(
                static fn (FoundSolvingTime $time): FoundSolvingTimeResponse => new FoundSolvingTimeResponse(
                    timeId: $time->timeId,
                    puzzleId: $time->puzzleId,
                    type: $time->type->value,
                    trackedByMe: $time->trackedByMe,
                ),
                $lookup->found,
            ),
            deleted: array_map(
                static fn (DeletedSolvingTime $time): DeletedSolvingTimeResponse => new DeletedSolvingTimeResponse(
                    timeId: $time->timeId,
                    deletedAt: $time->deletedAt->format('c'),
                ),
                $lookup->deleted,
            ),
            missing: $lookup->missing,
        );
    }
}
