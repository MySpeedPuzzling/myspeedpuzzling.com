<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

/**
 * A provider's signing keys (JWKS) could not be fetched or are not a valid
 * key set, so no token signed by that provider can be verified right now.
 */
final class JwksUnavailable extends Exception
{
}
