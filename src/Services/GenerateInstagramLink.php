<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Nette\Utils\Strings;
use SpeedPuzzling\Web\Value\Link;

readonly final class GenerateInstagramLink
{
    public function fromUserInput(string $input): Link
    {
        $input = trim($input, "@ \n\r\t\v\0");

        if (str_starts_with($input, 'http')) {
            $link = $input;
        } else {
            $link = 'https://www.instagram.com/' . $input . '/';
        }

        $text = str_replace('https://www.instagram.com/', '', $link);
        if (str_contains($text, '?')) {
            $text = Strings::before($text, '?') ?? '';
        }
        $text = trim($text, '/');

        return new Link($link, '@' . $text);
    }
}
