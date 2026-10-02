<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Twig\EmailDocumentTwigExtension;

final class EmailDocumentTwigExtensionTest extends TestCase
{
    private const string INLINED = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">' . "\n"
        . '<html><body style="font-size: 16px;"><table class="container" style="width: 580px;"><tr>'
        . '<td style="background-color: #d63c42; border-radius: 8px;"><a href="https://example.test">Go</a></td>'
        . '<td bgcolor="#ffffff" style="background-color: #d63c42;">x</td>'
        . '<td style="background: transparent;">y</td>'
        . '</tr></table><table role="grid"></table></body></html>';

    public function testTheInlinedDocumentGetsAHeadWithTheViewportAndTheMobileRules(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(self::INLINED, 'cs', 'Tvůj kód');

        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1.0">', $html);
        self::assertStringContainsString('table.container { width: 100% !important; }', $html);
        self::assertSame(1, substr_count($html, '<head>'));
        self::assertStringContainsString('<body style="font-size: 16px;">', $html, 'The body stays as inlined');
    }

    public function testDocumentSemantics(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(self::INLINED, 'cs', 'Tvůj kód & "odkaz"');

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"cs\" dir=\"ltr\"><head>", $html);
        self::assertStringNotContainsString('HTML 4.0', $html);
        self::assertStringContainsString('<title>Tvůj kód &amp; &quot;odkaz&quot;</title>', $html);
        // Gmail drops the <html> attributes - the language must also sit on a wrapper inside the body
        self::assertStringContainsString(
            '<body style="font-size: 16px;"><div lang="cs" dir="ltr" role="article" aria-roledescription="email" aria-label="Tvůj kód &amp; &quot;odkaz&quot;">',
            $html,
        );
        self::assertStringEndsWith('</table></div></body></html>', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light only">', $html);
        self::assertStringContainsString('<meta name="supported-color-schemes" content="light only">', $html);
        self::assertStringContainsString('<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">', $html);
        self::assertStringContainsString('a[x-apple-data-detectors] { color: inherit !important;', $html);
    }

    public function testLayoutTablesArePresentationAndColouredCellsGetBgcolor(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(self::INLINED);

        self::assertStringContainsString('<table role="presentation" class="container"', $html);
        self::assertStringContainsString('<table role="grid">', $html, 'An explicit role is kept');
        // Classic Outlook ignores the link's CSS of a button - the colour must sit on the cell as an attribute
        self::assertStringContainsString('<td bgcolor="#d63c42" style="background-color: #d63c42; border-radius: 8px;">', $html);
        self::assertStringContainsString('<td bgcolor="#ffffff" style="background-color: #d63c42;">', $html, 'An explicit bgcolor is kept');
        self::assertStringContainsString('<td style="background: transparent;">', $html);
    }

    public function testWithoutSubjectThereIsNoTitleAndAnOddLocaleFallsBackToEnglish(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(self::INLINED, 'x"><script>', null);

        self::assertStringNotContainsString('<title>', $html);
        self::assertStringNotContainsString('aria-label', $html);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testARegionalLocaleBecomesALanguageTag(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(self::INLINED, 'pt_BR');

        self::assertStringContainsString('<html lang="pt-BR" dir="ltr">', $html);
    }

    public function testADocumentWithAHeadIsLeftAlone(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';

        self::assertSame($html, (new EmailDocumentTwigExtension())->emailDocument($html));
    }

    public function testAHeaderElementIsNotAHead(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument('<html><body><header>Hi</header></body></html>');

        self::assertStringContainsString('<meta name="viewport"', $html);
    }

    public function testAFragmentIsWrappedIntoADocument(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument('<p>Hi</p>');

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\" dir=\"ltr\"><head>", $html);
        self::assertStringEndsWith('<body><div lang="en" dir="ltr" role="article" aria-roledescription="email"><p>Hi</p></div></body></html>', $html);
    }
}
