<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * An organiser's rich text section (docs/features/competitions-management/public-page.md) keeps its numbered and
 * bulleted lists: the site resets every list in base.html.twig's critical CSS, so `.page-section-rich-text` must give
 * `ul` and `ol` their markers back (browser verification of PR #136: the rules of an event lost their numbers).
 */
final class PageSectionRichTextListsTest extends TestCase
{
    public function testTheRichTextRestoresTheListMarkersTheSiteResets(): void
    {
        $base = (string) file_get_contents(__DIR__ . '/../templates/base.html.twig');
        self::assertMatchesRegularExpression('/ul,ol\{[^}]*list-style:none/', $base, 'The reset this test guards against is gone - the test can go too');

        $styles = (string) file_get_contents(__DIR__ . '/../assets/styles/_page-sections.scss');
        $richText = substr($styles, (int) strpos($styles, '.page-section-rich-text {'));

        self::assertMatchesRegularExpression('/\bul\s*\{\s*list-style:\s*disc;/', $richText);
        self::assertMatchesRegularExpression('/\bol\s*\{\s*list-style:\s*decimal;/', $richText);
        self::assertMatchesRegularExpression('/padding-left:\s*[1-9]/', $richText);
    }
}
