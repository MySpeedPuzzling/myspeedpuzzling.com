<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A 422 for `PUT /me/solving-times/{id}` of an unfinished result (pieces placed when a competition round's time ran out -
 * docs/features/competitions-management/unfinished-results-plan.md). The update input knows no pieces placed yet, so
 * a save would turn it into a relax solve or a finished time. Nothing was saved.
 */
final class UnfinishedResultNotEditable extends UnprocessableEntityHttpException implements ProblemExceptionInterface
{
    public const string TYPE = '/errors/unfinished_result_not_editable';

    public function __construct()
    {
        parent::__construct('This result did not finish within the time limit (it has pieces_placed). It cannot be updated through the API yet - delete it or leave it as it is.');
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTitle(): string
    {
        return 'Unfinished result not editable';
    }

    public function getStatus(): int
    {
        return $this->getStatusCode();
    }

    public function getDetail(): string
    {
        return $this->getMessage();
    }

    public function getInstance(): null|string
    {
        return null;
    }
}
