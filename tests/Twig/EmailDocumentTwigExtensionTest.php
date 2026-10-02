<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Twig\EmailDocumentTwigExtension;

final class EmailDocumentTwigExtensionTest extends TestCase
{
    public function testTheInlinedDocumentGetsAHeadWithTheViewportAndTheMobileRules(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument(
            '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">' . "\n"
            . '<html><body style="font-size: 16px;"><table class="container" style="width: 580px;"></table></body></html>',
        );

        self::assertStringContainsString('<html><head>', $html);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1.0">', $html);
        self::assertStringContainsString('table.container { width: 100% !important; }', $html);
        self::assertSame(1, substr_count($html, '<head>'));
        self::assertStringContainsString('<body style="font-size: 16px;">', $html, 'The body stays as inlined');
    }

    public function testADocumentWithAHeadIsLeftAlone(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';

        self::assertSame($html, (new EmailDocumentTwigExtension())->emailDocument($html));
    }

    public function testAFragmentIsWrappedIntoADocument(): void
    {
        $html = (new EmailDocumentTwigExtension())->emailDocument('<p>Hi</p>');

        self::assertStringStartsWith('<!DOCTYPE html><html><head>', $html);
        self::assertStringEndsWith('<body><p>Hi</p></body></html>', $html);
    }
}
