<?php

/**
 * Translations that are the same as English on purpose - read by tools/check-new-translations.php and
 * tests/Navigation/NavigationTranslationsTest.php (docs/features/navigation DOC-02).
 *
 * A translation equal to the English text (trimmed, case-insensitive) is a miss unless
 *  - it holds nothing to translate: once the `not_text` patterns and the `brands` are removed, only what `leftover`
 *    allows is left (digits, punctuation, symbols/emoji, spaces), or
 *  - an entry of `entries` names its key (or a prefix of it) and the language, with the reason.
 *
 * Keep it tight: an entry only where the word truly is the same in that language. The tool reports an entry no value
 * needs any more, so a later real translation removes it.
 *
 * Entry: 'key' => 'nav.menu.title' or 'prefix' => 'nav_admin.', optional 'domain' (default 'messages'), 'locales' (the
 * languages where it stays English) and a one-line 'reason'.
 */

declare(strict_types=1);

return [
    // Placeholders (%name%, {{ limit }}, {count}), plural intervals ({1}, [0,Inf[), HTML tags and entities
    'not_text' => [
        '/%[A-Za-z0-9_.-]+%/',
        '/\{\{[^}]*\}\}/',
        '/\{[A-Za-z_][A-Za-z0-9_]*\}/',
        '/\{\s*-?\d+\s*\}|[\[\]]\s*-?(?:\d+|Inf)\s*,\s*[+-]?(?:\d+|Inf)\s*[\[\]]/',
        '/<[^>]*>/',
        '/&(?:[a-z]+|#\d+);/',
    ],
    // Names that stay as they are in every language (whole words, case-insensitive; a longer name before its part)
    'brands' => [
        'MySpeedPuzzling',
        'MSP Rating',
        'MSP',
        'Google',
        'Apple',
        'Microsoft',
        'Facebook',
        'Stripe',
        'WJPF',
        'WJPC',
        'API',
    ],
    'leftover' => '/^[\p{N}\p{P}\p{S}\p{Z}\s\x{200D}\x{FE0F}]*$/u',
    'entries' => [
        [
            'prefix' => 'nav_admin.',
            'locales' => ['cs', 'de', 'es', 'fr', 'ja'],
            'reason' => 'Menu rows of the moderator and admin pages keep their English labels: those pages are English-only (README §4.12, MENU-01)',
        ],
        [
            'key' => 'community_page.title',
            'locales' => ['de'],
            'reason' => 'German uses the loanword "Community" for an online community (the Community place, its footer column)',
        ],
        [
            'key' => 'nav.group.community',
            'locales' => ['de'],
            'reason' => 'German uses the loanword "Community" for an online community',
        ],
        [
            'key' => 'nav.row.puzzles_place',
            'locales' => ['de', 'es', 'fr'],
            'reason' => '"Puzzles" is the plural of Puzzle in German and of puzzle in Spanish and French (the Puzzles place, its footer column)',
        ],
        [
            'key' => 'nav.group.puzzles',
            'locales' => ['de', 'es', 'fr'],
            'reason' => '"Puzzles" is the plural of Puzzle in German and of puzzle in Spanish and French',
        ],
        [
            'key' => 'nav.bar.menu',
            'locales' => ['cs', 'fr'],
            'reason' => '"Menu" is the Czech and the French word for a menu',
        ],
        [
            'key' => 'nav.menu.title',
            'locales' => ['cs', 'fr'],
            'reason' => '"Menu" is the Czech and the French word for a menu',
        ],
        [
            'key' => 'nav.bar.feedback',
            'locales' => ['de'],
            'reason' => 'German uses the loanword "Feedback"',
        ],
        // Labels of main's pages that registry rows reuse (one name per destination, README §4.12)
        [
            'key' => 'menu.puzzles_overview',
            'locales' => ['cs', 'de', 'es', 'fr'],
            'reason' => '"Puzzle" is the Czech, German, Spanish and French word for a puzzle',
        ],
        [
            'key' => 'menu.faq',
            'locales' => ['de', 'fr'],
            'reason' => '"FAQ" is the usual German and French name of a questions page',
        ],
        [
            'key' => 'menu.hub',
            'locales' => ['cs', 'de'],
            'reason' => 'The Hub page keeps its name in Czech and German (loanword "Hub" for a central place)',
        ],
        [
            'key' => 'messaging.title',
            'locales' => ['fr'],
            'reason' => '"Messages" is the French word for messages',
        ],
        [
            'key' => 'notifications.title',
            'locales' => ['fr'],
            'reason' => '"Notifications" is the French word for notifications',
        ],
        [
            'key' => 'xp.leaderboard.board.karma.tab',
            'locales' => ['cs', 'de', 'es', 'fr'],
            'reason' => '"Karma" is the same word in Czech, German, Spanish and French',
        ],
        [
            'key' => 'progression_hub.level.title',
            'locales' => ['de'],
            'reason' => 'German uses the gaming loanword "Level" (and XP)',
        ],
        [
            'key' => 'footer.puzzle_tracker_app',
            'locales' => ['de'],
            'reason' => 'The app\'s name "Puzzle Tracker" with the German word "App"',
        ],
    ],
];
