<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

final class CollectionAlreadyExists extends \Exception
{
    public function __construct(
        public readonly string $collectionId,
        string $message = 'Collection already exists',
        int $code = 0,
        null|\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
