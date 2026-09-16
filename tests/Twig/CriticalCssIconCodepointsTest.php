<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * base.html.twig inlines a handful of icon rules into its critical CSS so the
 * header paints before app.css arrives. Those codepoints are a hand-written
 * copy of the real ones, and a copy drifts: every `.ci-*` value in it was
 * silently wrong from February to September 2026, because app.css overrides
 * them microseconds later and nobody sees the difference — until a visitor's
 * cached app.css breaks and the header renders LinkedIn, Messenger and dribbble
 * glyphs instead of search, sign-in and mail.
 *
 * So the inline block is pinned here against the sources app.css is built from.
 * Regenerating either icon font now fails this test instead of shipping.
 */
final class CriticalCssIconCodepointsTest extends TestCase
{
    private const string BASE_TEMPLATE = __DIR__ . '/../../templates/base.html.twig';
    private const string CARTZILLA_SCSS = __DIR__ . '/../../assets/styles/components/_icons.scss';
    private const string BOOTSTRAP_ICONS_CSS = __DIR__ . '/../../node_modules/bootstrap-icons/font/bootstrap-icons.css';

    public function testInlinedCartzillaCodepointsMatchTheIconFont(): void
    {
        $inlined = $this->inlinedCodepoints('ci');
        $authoritative = $this->cartzillaCodepoints();

        self::assertNotEmpty($inlined, 'No .ci-* rules found in the critical CSS — has the block moved?');

        foreach ($inlined as $icon => $codepoint) {
            self::assertArrayHasKey(
                $icon,
                $authoritative,
                sprintf('Critical CSS inlines .ci-%s, which no longer exists in _icons.scss.', $icon),
            );

            self::assertSame(
                $authoritative[$icon],
                $codepoint,
                sprintf(
                    'Critical CSS renders .ci-%s as \%s but the font maps it to \%s. '
                    . 'Update the inline block in base.html.twig to match _icons.scss.',
                    $icon,
                    $codepoint,
                    $authoritative[$icon],
                ),
            );
        }
    }

    public function testInlinedBootstrapIconCodepointsMatchTheIconFont(): void
    {
        if (!is_file(self::BOOTSTRAP_ICONS_CSS)) {
            self::markTestSkipped('bootstrap-icons is not installed (node_modules missing).');
        }

        $inlined = $this->inlinedCodepoints('bi');
        $authoritative = $this->bootstrapIconCodepoints();

        self::assertNotEmpty($inlined, 'No .bi-* rules found in the critical CSS — has the block moved?');

        foreach ($inlined as $icon => $codepoint) {
            self::assertArrayHasKey(
                $icon,
                $authoritative,
                sprintf('Critical CSS inlines .bi-%s, which no longer exists in bootstrap-icons.', $icon),
            );

            self::assertSame(
                $authoritative[$icon],
                $codepoint,
                sprintf(
                    'Critical CSS renders .bi-%s as \%s but bootstrap-icons maps it to \%s. '
                    . 'Update the inline block in base.html.twig.',
                    $icon,
                    $codepoint,
                    $authoritative[$icon],
                ),
            );
        }
    }

    /**
     * @return array<string, string> icon name (without prefix) => lowercase hex codepoint
     */
    private function inlinedCodepoints(string $prefix): array
    {
        $template = (string) file_get_contents(self::BASE_TEMPLATE);

        preg_match_all(
            sprintf('/\.%s-([a-z0-9-]+)::?before\{content:"\\\\([0-9a-fA-F]+)"\}/', $prefix),
            $template,
            $matches,
            PREG_SET_ORDER,
        );

        $codepoints = [];
        foreach ($matches as $match) {
            $codepoints[$match[1]] = strtolower($match[2]);
        }

        return $codepoints;
    }

    /**
     * Source of truth for app.css: `$ci-search: '\e972';` declarations.
     *
     * @return array<string, string>
     */
    private function cartzillaCodepoints(): array
    {
        $scss = (string) file_get_contents(self::CARTZILLA_SCSS);

        preg_match_all(
            '/^\$ci-([a-z0-9-]+):\s*\'\\\\([0-9a-fA-F]+)\';/m',
            $scss,
            $matches,
            PREG_SET_ORDER,
        );

        $codepoints = [];
        foreach ($matches as $match) {
            $codepoints[$match[1]] = strtolower($match[2]);
        }

        return $codepoints;
    }

    /**
     * @return array<string, string>
     */
    private function bootstrapIconCodepoints(): array
    {
        $css = (string) file_get_contents(self::BOOTSTRAP_ICONS_CSS);

        preg_match_all(
            '/\.bi-([a-z0-9-]+)::?before\s*\{\s*content:\s*"\\\\([0-9a-fA-F]+)"/',
            $css,
            $matches,
            PREG_SET_ORDER,
        );

        $codepoints = [];
        foreach ($matches as $match) {
            $codepoints[$match[1]] = strtolower($match[2]);
        }

        return $codepoints;
    }
}
