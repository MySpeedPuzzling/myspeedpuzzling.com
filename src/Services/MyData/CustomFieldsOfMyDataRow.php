<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MyData;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Query\GetCollectionItemCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Query\GetCustomValues;
use SpeedPuzzling\Web\Results\MyData\MyDataRowCustomFields;
use SpeedPuzzling\Web\Services\CustomFields\CustomValuesWriteFailure;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The player's own fields on ONE row of "My data" - read for the row sheet and a cell edited in place, written
 * through the fields' own messages (docs/features/my-data.md "Editing one row", docs/features/custom-fields.md).
 *
 * **Whose: the signed-in player's, resolved here** - there is no player id to pass, so a caller can not read or write
 * anybody else's values. On a pair's result everybody has their own row of values: a partner opening the same result
 * gets their own.
 *
 * Reading fails open (the row is shown without the fields). Writing never throws: the result or the comment was
 * saved before it, and nothing of a value is ever logged.
 */
readonly final class CustomFieldsOfMyDataRow
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private GetCustomFields $getCustomFields,
        private GetCustomValues $getCustomValues,
        private GetCollectionItemCustomValues $getCollectionItemCustomValues,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function onResultOfSignedInViewer(string $solvingTimeId): MyDataRowCustomFields
    {
        try {
            $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            if ($viewerId === null) {
                return new MyDataRowCustomFields();
            }

            $fields = $this->getCustomFields->usableOnResults($viewerId);

            if ($fields === []) {
                return new MyDataRowCustomFields();
            }

            return new MyDataRowCustomFields($fields, $this->getCustomValues->forViewer([$solvingTimeId], $viewerId)[$solvingTimeId] ?? new CustomValues());
        } catch (\Throwable $exception) {
            return $this->withoutFields($exception);
        }
    }

    public function onCollectionItemOfSignedInViewer(string $collectionItemId): MyDataRowCustomFields
    {
        try {
            $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            if ($viewerId === null) {
                return new MyDataRowCustomFields();
            }

            $fields = $this->getCustomFields->usableOnCollectionItems($viewerId);

            if ($fields === []) {
                return new MyDataRowCustomFields();
            }

            return new MyDataRowCustomFields($fields, $this->getCollectionItemCustomValues->forViewer([$collectionItemId], $viewerId)[$collectionItemId] ?? new CustomValues());
        } catch (\Throwable $exception) {
            return $this->withoutFields($exception);
        }
    }

    /**
     * @param array<string, string> $inputs keyed by field id, as typed - only these fields change
     * @return bool whether they were stored
     */
    public function saveOnResultOfSignedInViewer(string $solvingTimeId, array $inputs): bool
    {
        return $this->save(
            static fn (string $viewerId): object => new SetSolvingTimeCustomValues($solvingTimeId, $viewerId, $inputs),
            'Custom fields of a result could not be saved from My data',
            ['solvingTimeId' => $solvingTimeId],
        );
    }

    /**
     * @param array<string, string> $inputs keyed by field id, as typed - only these fields change
     * @return bool whether they were stored
     */
    public function saveOnCollectionItemOfSignedInViewer(string $collectionItemId, array $inputs): bool
    {
        return $this->save(
            static fn (string $viewerId): object => new SetCollectionItemCustomValues($collectionItemId, $viewerId, $inputs),
            'Custom fields of a collection item could not be saved from My data',
            ['collectionItemId' => $collectionItemId],
        );
    }

    /**
     * @param callable(string): object $message
     * @param array<string, string> $context ids only
     */
    private function save(callable $message, string $warning, array $context): bool
    {
        try {
            $viewerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            if ($viewerId === null) {
                return false;
            }

            $this->messageBus->dispatch($message($viewerId));

            return true;
        } catch (\Throwable $exception) {
            try {
                $refusal = $exception instanceof HandlerFailedException ? $exception->getPrevious() : null;

                // A refused value was checked a moment ago (a field archived in another tab meanwhile) - not a failure
                if (!$refusal instanceof InvalidCustomValues) {
                    $this->logger->warning($warning, [
                        ...$context,
                        // Never the exception itself: a driver's message can quote the values (CustomValuesWriteFailure)
                        'exception' => CustomValuesWriteFailure::withoutValues($exception),
                    ]);
                }
            } catch (\Throwable) {
                // Nothing about the player's own fields may get in the way of what was saved before them
            }

            return false;
        }
    }

    private function withoutFields(\Throwable $exception): MyDataRowCustomFields
    {
        // Reading carries no value of anybody (the statements' parameters are ids) - logged as it is
        $this->logger->warning('Custom fields could not be loaded for a row of My data - it is shown without them', [
            'exception' => $exception,
        ]);

        return new MyDataRowCustomFields();
    }
}
