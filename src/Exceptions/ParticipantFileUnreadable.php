<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An uploaded participant list that is not a readable .xlsx workbook or CSV/TSV text, too big, or a sheet it does not
 * have (ParticipantFileReader). The organiser is told the translated $translationKey; the message is for the logs.
 */
final class ParticipantFileUnreadable extends UnprocessableEntityHttpException
{
    public const string UNREADABLE = 'competition.participants.import.file.unreadable';
    public const string EMPTY = 'competition.participants.import.file.empty';
    public const string TOO_MANY_ROWS = 'competition.participants.import.file.too_many_rows';
    public const string TOO_LARGE = 'competition.participants.import.file.too_large';

    /**
     * @param array<string, int|string> $translationParameters
     */
    public function __construct(
        string $reason,
        null|\Throwable $previous = null,
        public readonly string $translationKey = self::UNREADABLE,
        public readonly array $translationParameters = [],
    ) {
        parent::__construct('The participant file could not be read: ' . $reason, $previous);
    }

    public static function empty(): self
    {
        return new self('the file has no values', translationKey: self::EMPTY);
    }

    /**
     * A workbook too big to read safely: unpacked parts over the limits (a zip bomb), or more cells than a list holds.
     */
    public static function tooLarge(string $reason): self
    {
        return new self($reason, translationKey: self::TOO_LARGE);
    }

    public static function tooManyRows(int $max): self
    {
        return new self(
            sprintf('more than %d rows', $max),
            translationKey: self::TOO_MANY_ROWS,
            translationParameters: ['%max%' => number_format($max, 0, '.', ',')],
        );
    }

    public function trans(TranslatorInterface $translator): string
    {
        return $translator->trans($this->translationKey, $this->translationParameters);
    }
}
