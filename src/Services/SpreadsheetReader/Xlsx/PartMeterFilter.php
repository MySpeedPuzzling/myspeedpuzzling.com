<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * A read filter on a part's stream that lets every chunk pass a PartMeter on its way to the XML parser - and stops
 * the stream, without handing the chunk on, when the meter says so.
 */
final class PartMeterFilter extends \php_user_filter
{
    public const string NAME = 'msp.spreadsheet.meter';

    private static bool $registered = false;

    public static function register(): bool
    {
        if (self::$registered === false) {
            self::$registered = in_array(self::NAME, stream_get_filters(), true) || stream_filter_register(self::NAME, self::class);
        }

        return self::$registered;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $meter = $this->params;

        if ($meter instanceof PartMeter === false) {
            return PSFS_ERR_FATAL;
        }

        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $consumed += $bucket->datalen;

            if ($meter->pass($bucket->data) === false) {
                return PSFS_ERR_FATAL;
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
