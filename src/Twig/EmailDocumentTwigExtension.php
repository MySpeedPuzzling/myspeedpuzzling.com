<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `inline_css` returns a document without a <head>: no viewport, and Foundation's @media rules are gone (they
 * cannot be inlined). Phones then lay the fixed 580 px container out at desktop width and shrink everything -
 * 16 px text reads like 11 px on an iPhone. This filter, applied after `inline_css`, adds the head back: the
 * viewport meta and the few mobile rules our e-mails need. Desktop rendering is unchanged.
 */
final class EmailDocumentTwigExtension extends AbstractExtension
{
    private const string HEAD = <<<HTML
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<style>
@media only screen and (max-width: 596px) {
  table.container { width: 100% !important; }
  th.columns, th.column { width: 100% !important; padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; }
  center { min-width: 0 !important; }
  img { max-width: 100% !important; height: auto !important; }
}
</style>
</head>
HTML;

    public function getFilters(): array
    {
        return [
            new TwigFilter('email_document', $this->emailDocument(...), ['is_safe' => ['html']]),
        ];
    }

    public function emailDocument(string $html): string
    {
        if (stripos($html, '<head') !== false) {
            return $html;
        }

        $withHead = preg_replace('/<html([^>]*)>/i', '<html$1>' . self::HEAD, $html, 1, $count);

        if ($withHead === null || $count === 0) {
            return '<!DOCTYPE html><html>' . self::HEAD . '<body>' . $html . '</body></html>';
        }

        return $withHead;
    }
}
