<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a refused upload tells the player (`data_import.refused.*`) - the same texts for the direct upload, the
 * fallback form and the confirmation.
 */
readonly final class ImportUploadRefusalMessage
{
    /** Refusals that are not an ImportUploadRefused: the rate limit, object storage not answering, a broken upload */
    public const array OTHER_REASONS = ['rate_limited', 'storage', 'no_file'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function of(string $reason): string
    {
        $known = in_array($reason, ImportUploadRefused::REASONS, true) || in_array($reason, self::OTHER_REASONS, true);

        return $this->translator->trans('data_import.refused.' . ($known ? $reason : 'storage'), [
            '%megabytes%' => intdiv(DataImportLimits::MAX_FILE_BYTES, 1024 * 1024),
            '%limit%' => DataImportLimits::MAX_UNFINISHED_PER_PLAYER,
        ]);
    }

    /**
     * Every text the page's script may need, by reason
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $messages = [];

        foreach ([...ImportUploadRefused::REASONS, ...self::OTHER_REASONS] as $reason) {
            $messages[$reason] = $this->of($reason);
        }

        return $messages;
    }
}
