<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every layout with its own <html> sets the platform flags (docs/features/navigation GEST-09, NAV-14 (b)): it includes
 * templates/_head_flags.html.twig (data-os before first paint) and renders native_app_attribute() on <html>
 * (data-native-app inside the app), or is exempt with a reason (implementation plan decision 79).
 */
final class HeadFlagsCoverageTest extends TestCase
{
    /** template => why it has no platform flags */
    private const array EXEMPT = [
        'templates/print_round_tables.html.twig' => 'print page: no app bundle, nothing reads the flags',
        'templates/seating/print.html.twig' => 'print page: no app bundle, nothing reads the flags',
        'templates/live_results/name_tags.html.twig' => 'printed name tags: no app bundle, nothing reads the flags',
        'templates/asset-recovery.html.twig' => 'standalone recovery page for broken assets: no app bundle, must stay dependency-free',
    ];

    public function testEveryLayoutSetsThePlatformFlags(): void
    {
        $layouts = $this->layouts();
        $missing = [];

        foreach ($layouts as $path => $template) {
            if (array_key_exists($path, self::EXEMPT)) {
                continue;
            }

            if (preg_match('~\{\{\s*include\(\s*\'_head_flags\.html\.twig\'\s*\)\s*\}\}~', $template) !== 1) {
                $missing[] = $path . ': no {{ include(\'_head_flags.html.twig\') }}';
            }

            if (preg_match('~<html\b[^>]*\{\{\s*native_app_attribute\(\)\s*\}\}[^>]*>~', $template) !== 1) {
                $missing[] = $path . ': no {{ native_app_attribute() }} on <html>';
            }
        }

        self::assertSame([], $missing);
        // The layout every page extends is one of them
        self::assertArrayHasKey('templates/base.html.twig', $layouts);
        self::assertArrayHasKey('templates/oauth2/consent.html.twig', $layouts);
    }

    public function testEveryExemptionIsStillALayout(): void
    {
        $layouts = $this->layouts();

        foreach (array_keys(self::EXEMPT) as $path) {
            self::assertArrayHasKey($path, $layouts, $path . ' is exempt but renders no <html> any more - remove the exemption');
        }
    }

    /**
     * @return array<string, string> templates rendering an <html> element => their code without Twig comments
     */
    private function layouts(): array
    {
        $root = dirname(__DIR__);
        $layouts = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            // Twig comments may talk about <html> (templates/_head_flags.html.twig does)
            $template = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($file->getPathname()));

            if (preg_match('~<html[\s>]~i', $template) === 1) {
                $layouts[substr($file->getPathname(), strlen($root) + 1)] = $template;
            }
        }

        ksort($layouts);

        return $layouts;
    }
}
