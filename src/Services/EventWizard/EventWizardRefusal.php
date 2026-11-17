<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * The wizard's composite messages dispatch existing messages inside their own handler, so a refusal of an inner handler
 * arrives wrapped twice (HandlerFailedException around HandlerFailedException) - or as itself when it is an HTTP
 * exception (UnwrapHttpExceptionMiddleware). This finds the refusal a step's form explains, wherever it sits.
 */
final class EventWizardRefusal
{
    /**
     * @template T of Throwable
     * @param class-string<T> $class
     * @return null|T
     */
    public static function find(Throwable $exception, string $class): null|Throwable
    {
        if ($exception instanceof $class) {
            return $exception;
        }

        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                $found = self::find($wrapped, $class);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        $previous = $exception->getPrevious();

        return $previous !== null ? self::find($previous, $class) : null;
    }
}
