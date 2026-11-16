<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\DenormalizationViolationFactoryInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;

/**
 * API Platform 5 turns a wrongly typed request field (a number where a string belongs, a null) into a 422 with
 * `violations` whenever the field carries a validator constraint. The public API has always answered such input
 * with 400 - clients rely on it (no BC breaks in API v1), so this factory promotes nothing: returning lets API
 * Platform rethrow the denormalization error as the 400 it was before.
 */
final readonly class TypeMismatchStaysBadRequest implements DenormalizationViolationFactoryInterface
{
    public function handle(NotNormalizableValueException|PartialDenormalizationException $exception, Operation $operation): void
    {
    }
}
