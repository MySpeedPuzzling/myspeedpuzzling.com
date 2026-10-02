<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns what `inline_css` returns into a proper e-mail document (docs/features/transactional-emails.md).
 *
 * `inline_css` returns an HTML 4 document without a <head>: no viewport, and Foundation's @media rules are gone (they
 * cannot be inlined) - phones then lay the fixed 580 px container out at desktop width and shrink everything. Applied
 * after `inline_css`, this filter adds:
 * - `<!DOCTYPE html>`, `lang` + `dir` on <html> AND on a wrapper <div role="article"> around the body (Gmail drops
 *   the <html> attributes), the subject as <title> and as the wrapper's label, so screen readers read the e-mail in
 *   the right language and announce it as one e-mail;
 * - the viewport, the mobile rules, "light only" colour scheme, no auto-detected phone numbers/dates/addresses
 *   (iOS turns them into blue links with its own colours);
 * - `role="presentation"` on every layout table (a screen reader otherwise announces "table, 3 rows");
 * - a `bgcolor` attribute on every cell with an inline background colour - classic Outlook ignores the CSS of a
 *   button's link (padding, background) and shows only the cell, so the colour must sit on the cell.
 *
 * Locale and subject come from the e-mail being rendered (the mailer renders with the message's locale), so the
 * templates only write `|email_document`.
 */
final class EmailDocumentTwigExtension extends AbstractExtension
{
    private const string HEAD = <<<HTML
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
%title%<style>
:root { color-scheme: light only; supported-color-schemes: light only; }
a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
@media only screen and (max-width: 596px) {
  table.container { width: 100% !important; }
  th.columns, th.column { width: 100% !important; padding-left: 16px !important; padding-right: 16px !important; box-sizing: border-box; }
  center { min-width: 0 !important; }
  img { max-width: 100% !important; height: auto !important; }
}
</style>
</head>
HTML;

    public function __construct(
        readonly private null|TranslatorInterface $translator = null,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('email_document', $this->emailDocumentOfRenderedEmail(...), [
                'is_safe' => ['html'],
                'needs_context' => true,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emailDocumentOfRenderedEmail(array $context, string $html): string
    {
        // The mailer's BodyRenderer puts the message into the context as `email` (WrappedTemplatedEmail - internal,
        // hence no instanceof); rendered elsewhere (a test, a preview) there is simply no title
        $email = $context['email'] ?? null;
        $subject = is_object($email) && method_exists($email, 'getSubject') ? $email->getSubject() : null;

        return $this->emailDocument(
            $html,
            $this->translator?->getLocale() ?? 'en',
            is_string($subject) ? $subject : null,
        );
    }

    public function emailDocument(string $html, string $locale = 'en', null|string $subject = null): string
    {
        // Already a document (`<head`, not `<header>`)
        if (preg_match('/<head[\s>]/i', $html) === 1) {
            return $html;
        }

        $lang = self::languageTag($locale);
        $subject = $subject !== null ? trim($subject) : '';
        $escapedSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $head = str_replace('%title%', $subject !== '' ? '<title>' . $escapedSubject . "</title>\n" : '', self::HEAD);
        $htmlTag = sprintf('<html lang="%s" dir="ltr">', $lang);
        $wrapperOpen = sprintf(
            '<div lang="%s" dir="ltr" role="article" aria-roledescription="email"%s>',
            $lang,
            $subject !== '' ? ' aria-label="' . $escapedSubject . '"' : '',
        );

        if (stripos($html, '<html') === false || stripos($html, '<body') === false) {
            $html = '<html><body>' . $html . '</body></html>';
        }

        $html = (string) preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $html);
        $html = (string) preg_replace('/<html\b[^>]*>/i', $htmlTag . $head, $html, 1);
        $html = (string) preg_replace('/<body\b([^>]*)>/i', '<body$1>' . $wrapperOpen, $html, 1);
        $html = (string) preg_replace('~</body>~i', '</div></body>', $html, 1);
        $html = self::layoutTablesArePresentation($html);
        $html = self::cellBackgroundsAsAttributes($html);

        return '<!DOCTYPE html>' . "\n" . $html;
    }

    private static function languageTag(string $locale): string
    {
        $tag = str_replace('_', '-', trim($locale));

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag) === 1 ? $tag : 'en';
    }

    private static function layoutTablesArePresentation(string $html): string
    {
        return (string) preg_replace_callback(
            '/<table\b([^>]*)>/i',
            static fn (array $match): string => preg_match('/\srole\s*=/i', $match[1]) === 1
                ? $match[0]
                : '<table role="presentation"' . $match[1] . '>',
            $html,
        );
    }

    private static function cellBackgroundsAsAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '/<td\b([^>]*)>/i',
            static function (array $match): string {
                if (preg_match('/\sbgcolor\s*=/i', $match[1]) === 1) {
                    return $match[0];
                }

                if (preg_match('/\sstyle\s*=\s*"[^"]*\bbackground(?:-color)?\s*:\s*(#[0-9a-f]{6}|#[0-9a-f]{3})\b/i', $match[1], $color) !== 1) {
                    return $match[0];
                }

                return '<td bgcolor="' . strtolower($color[1]) . '"' . $match[1] . '>';
            },
            $html,
        );
    }
}
