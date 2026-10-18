<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Restore this picture" cannot put the picture back (RestorePuzzleImageHandler) - nothing changed. The reason is a
 * translation key under admin.puzzle_history (the moderator's flash).
 */
final class PuzzleImageNotRestorable extends ConflictHttpException
{
    private function __construct(
        readonly public string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Neither the stored picture nor an archive copy of it exists any more.
     */
    public static function pictureGone(string $image): self
    {
        return new self('restore_picture_gone', sprintf('The picture "%s" is no longer stored - nothing was restored.', $image));
    }

    /**
     * The history line names no picture to put back (it replaced none, or it is not a line of this puzzle).
     */
    public static function nothingToRestore(): self
    {
        return new self('restore_nothing', 'This line of the history replaced no picture of the puzzle - nothing was restored.');
    }

    public static function alreadyThePicture(): self
    {
        return new self('restore_already_current', 'The puzzle has this picture now - nothing was restored.');
    }

    /**
     * The puzzle's picture is hidden now (a secret competition puzzle, a placeholder before its reveal) - a restored
     * picture would get a name made from the public record.
     */
    public static function pictureHidden(): self
    {
        return new self('restore_picture_hidden', 'The puzzle\'s picture is hidden now - restore it after the reveal.');
    }
}
