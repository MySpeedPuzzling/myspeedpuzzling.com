<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The 404 of a draft's page for a viewer who may not edit it (docs/features/organizations/README.md, P5). The draft
 * exists - it is only not public - so the old-URL redirect lookup (EventUrlRedirectSubscriber) leaves it alone: a draft
 * that took an old path shadows the redirect.
 */
final class DraftNotVisible extends NotFoundHttpException
{
}
