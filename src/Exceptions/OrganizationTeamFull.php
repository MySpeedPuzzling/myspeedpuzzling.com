<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Entity\Organization;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An organization's team holds at most Organization::MAX_MAINTAINERS people besides its creator
 * (docs/features/organizations/README.md "Data model") - the forms and the internal API validate first, the handlers
 * refuse whatever gets past them.
 */
final class OrganizationTeamFull extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(sprintf('An organization\'s team holds at most %d people besides its creator.', Organization::MAX_MAINTAINERS));
    }
}
