<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A country code that is not a CountryCode - "Add your country" answers it with 422 (SetMyCountryController).
 */
final class UnknownCountryCode extends UnprocessableEntityHttpException
{
    public function __construct(string $code)
    {
        parent::__construct(sprintf('Unknown country code "%s"', $code));
    }
}
