<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The events of an organization's results sheet are created as somebody on its team - anybody else would get them
 * approved in the organization's name (OrganizationApprovalPolicy).
 */
final class ActingPlayerNotOnOrganizationTeam extends UnprocessableEntityHttpException
{
}
