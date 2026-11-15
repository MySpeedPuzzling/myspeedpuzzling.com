<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps the instructions Claude Code loads lean. Root CLAUDE.md is loaded into every session (and every
 * sub-agent); by 2026-11-15 it had grown to 143k characters - 73 % of it per-feature summaries - and went over
 * Claude Code's startup limit. Area knowledge now lives in path-scoped rules (.claude/rules/**), loaded only when a
 * matching file is read or edited. A rule without `paths:` would load at startup again, and Claude Code truncates
 * an on-demand file around 40k characters.
 */
final class ClaudeInstructionsTest extends TestCase
{
    private const int ROOT_MAX_CHARACTERS = 25_000;
    private const int RULE_MAX_CHARACTERS = 35_000;
    private const string PROJECT_DIR = __DIR__ . '/..';

    /** Where rule globs point; the rest of the tree (vendor, node_modules, tools' model caches, builds) is never scanned. */
    private const array SCANNED_DIRECTORIES = ['src', 'templates', 'assets', 'docs', 'tests', 'translations', 'migrations', 'config', 'public/barcode-scanner', '.docker', '.github', '.claude/skills', 'bin', 'tools/online-cv'];

    /** @var list<string>|null */
    private static null|array $projectFiles = null;

    public function testRootClaudeMdStaysSmall(): void
    {
        $characters = mb_strlen($this->read('CLAUDE.md'));

        self::assertLessThanOrEqual(
            self::ROOT_MAX_CHARACTERS,
            $characters,
            'CLAUDE.md is loaded into every session. Put area knowledge into its .claude/rules/ file instead.',
        );
    }

    #[DataProvider('ruleFiles')]
    public function testRuleIsPathScopedAndSmallEnough(string $rule): void
    {
        $content = $this->read($rule);

        self::assertMatchesRegularExpression(
            '/\A---\npaths:\n(  - "[^"\n]+"\n)+---\n/',
            $content,
            "$rule must start with a `paths:` frontmatter (a list of quoted globs), otherwise it loads in every session.",
        );
        self::assertLessThanOrEqual(
            self::RULE_MAX_CHARACTERS,
            mb_strlen($content),
            "$rule is too long - Claude Code truncates on-demand files; split the area.",
        );
    }

    #[DataProvider('ruleFiles')]
    public function testRuleIsListedInRootIndex(string $rule): void
    {
        self::assertStringContainsString(
            '`' . $rule . '`',
            $this->read('CLAUDE.md'),
            "$rule is missing from the \"Where knowledge lives\" table in CLAUDE.md.",
        );
    }

    /**
     * A glob matching nothing is a typo or a moved file - the rule then silently never loads for that area.
     */
    #[DataProvider('ruleFiles')]
    public function testEveryGlobMatchesAFile(string $rule): void
    {
        preg_match('/\A---\npaths:\n((?:  - "[^"\n]+"\n)+)---\n/', $this->read($rule), $frontmatter);
        self::assertArrayHasKey(1, $frontmatter);
        preg_match_all('/"([^"\n]+)"/', $frontmatter[1], $globs);

        $files = self::projectFiles();
        $dead = [];

        foreach ($globs[1] as $glob) {
            $regex = self::globToRegex($glob);

            if (preg_grep($regex, $files) === []) {
                $dead[] = $glob;
            }
        }

        self::assertSame([], $dead, "$rule has globs that match no file in the project.");
    }

    public function testPathsNamedInRootExist(): void
    {
        preg_match_all('~`((?:docs|\.claude|tests|templates|config)/[^`*<>{} ]+)`~', $this->read('CLAUDE.md'), $matches);

        $missing = array_values(array_filter(
            array_unique($matches[1]),
            static fn (string $path): bool => !file_exists(self::PROJECT_DIR . '/' . $path),
        ));

        self::assertSame([], $missing, 'CLAUDE.md points at paths that do not exist.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ruleFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::PROJECT_DIR . '/.claude/rules'));
        $rules = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $rules[] = '.claude/rules/' . substr($file->getPathname(), strlen(self::PROJECT_DIR . '/.claude/rules/'));
            }
        }

        sort($rules);

        foreach ($rules as $rule) {
            yield $rule => [$rule];
        }
    }

    /**
     * Same semantics as Claude Code's globs: `**` + slash = zero or more directories, `*` = within one path segment.
     */
    private static function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            if (substr($glob, $i, 3) === '**/') {
                $regex .= '(?:.*/)?';
                $i += 2;
            } elseif (substr($glob, $i, 2) === '**') {
                $regex .= '.*';
                $i++;
            } elseif ($glob[$i] === '*') {
                $regex .= '[^/]*';
            } else {
                $regex .= preg_quote($glob[$i], '~');
            }
        }

        return '~\A' . $regex . '\z~';
    }

    /**
     * @return list<string>
     */
    private static function projectFiles(): array
    {
        if (self::$projectFiles !== null) {
            return self::$projectFiles;
        }

        $projectFiles = [];

        foreach (['', '.claude/', 'public/'] as $flatDirectory) {
            $files = glob(self::PROJECT_DIR . '/' . $flatDirectory . '{,.}*', GLOB_BRACE | GLOB_MARK);
            self::assertIsArray($files);

            foreach ($files as $path) {
                $relative = substr($path, strlen(self::PROJECT_DIR . '/'));

                if (!str_ends_with($relative, '/')) {
                    $projectFiles[] = $relative;
                }
            }
        }

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::PROJECT_DIR . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $projectFiles[] = substr($file->getPathname(), strlen(self::PROJECT_DIR . '/'));
                }
            }
        }

        return self::$projectFiles = $projectFiles;
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::PROJECT_DIR . '/' . $path);
        self::assertIsString($content);

        return $content;
    }
}
