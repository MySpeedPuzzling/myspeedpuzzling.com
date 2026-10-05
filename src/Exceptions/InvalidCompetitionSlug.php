<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class InvalidCompetitionSlug extends BadRequestHttpException
{
    public function __construct(string $slug)
    {
        parent::__construct(sprintf(
            'The slug "%s" must be lower-case letters and digits in words joined by single hyphens (%s), at most %d characters.',
            $slug,
            CompetitionSlugGenerator::PATTERN,
            CompetitionSlugGenerator::MAX_LENGTH,
        ));
    }
}
