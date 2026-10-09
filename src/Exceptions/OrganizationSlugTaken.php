<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An explicitly chosen slug another organization already holds (CompetitionSlugGenerator::isOrganizationSlugTaken()).
 */
final class OrganizationSlugTaken extends ConflictHttpException
{
    public function __construct(
        readonly public string $slug,
    ) {
        parent::__construct(sprintf('The slug "%s" is already used by another organization.', $slug));
    }
}
