<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\EventResultsImport\EventResultClaimRefusal;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A claim, release or removal of a spot of an imported event result is not possible
 * (docs/features/event-results-import/README.md "Gates"). Nothing was changed.
 */
final class EventResultClaimRefused extends UnprocessableEntityHttpException
{
    public function __construct(
        readonly public EventResultClaimRefusal $refusal,
    ) {
        parent::__construct(sprintf('Event result claim refused: %s.', $refusal->value));
    }

    public function translationKey(): string
    {
        return 'event_result_claim.refused.' . $this->refusal->value;
    }
}
