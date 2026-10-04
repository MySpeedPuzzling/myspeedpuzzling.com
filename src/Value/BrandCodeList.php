<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The brand code field of a puzzle (`identification_number`): one or more of the brand's own article numbers.
 */
readonly final class BrandCodeList
{
    /**
     * The codes of a stored value, separated by `,` `;` `|` (a slash or a dash belongs to the code: "12000/199").
     *
     * @return list<string>
     */
    public static function tokens(null|string $value): array
    {
        $tokens = [];

        foreach (preg_split('/[,;|]/', $value ?? '') ?: [] as $token) {
            $token = trim($token);

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
