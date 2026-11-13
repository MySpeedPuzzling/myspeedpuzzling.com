<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A sandbox catalogue file that is not what myspeedpuzzling:sandbox:export-catalogue writes (SandboxCatalogue) - the
 * import stops, its transaction rolls back.
 */
final class SandboxCatalogueFileInvalid extends \RuntimeException
{
}
