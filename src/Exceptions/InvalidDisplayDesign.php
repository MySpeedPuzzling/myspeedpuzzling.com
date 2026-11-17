<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use SpeedPuzzling\Web\Results\DisplayDesignError;

/**
 * A display design with a structure the sanitizer refuses (unknown element type, missing frame, too many elements or
 * pictures, a picture that is not this event's own upload, an unknown schema version, too large) - or a results element
 * switched to live results without the organiser's confirmation. The save endpoint answers 422 with every reason.
 */
final class InvalidDisplayDesign extends UnprocessableEntityHttpException
{
    /**
     * @param non-empty-list<DisplayDesignError> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(', ', array_map(
            static fn (DisplayDesignError $error): string => ($error->path === '' ? '/' : $error->path) . ': ' . $error->code,
            $errors,
        )));
    }
}
