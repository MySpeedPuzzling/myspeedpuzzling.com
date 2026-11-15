<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * **The one list of words that say solo, pair or team** - in the six languages' usual words, folded
 * (SearchText::fold()) - beside the header synonyms (ImportField::SYNONYMS). Three readers, one list:
 *
 * - a cell of a "solo / pair / team" column (ImportRowParser): the whole cell is one of the words - or one of the
 *   short ones only a cell can mean (`me`, `1`, `2`);
 * - **a sheet's title** (ImportColumnDecisions: "500 piece Pairs" is asked about, never silently solo;
 *   docs/features/data-import/people.md "A sheet called Pairs");
 * - a remembered layout (ImportLayoutMatcher): "every row is a pair result" is never carried onto a sheet whose
 *   title says otherwise.
 */
final class SolvingTypeWords
{
    /** @var array<string, list<string>> PuzzlingType's value => folded words */
    public const array WORDS = [
        'solo' => [
            'solo', 'solos', 'single', 'singles', 'individual', 'individuals', 'alone',
            'sam', 'sama', 'jednotlivec', 'jednotlivci', 'jednotlivcu',
            'einzel', 'allein', 'alleine',
            'individuales', 'sola',
            'seul', 'seule', 'individuel', 'individuelle', 'individuels',
            'ソロ', '個人', '一人', 'ひとり',
        ],
        'duo' => [
            'duo', 'duos', 'pair', 'pairs', 'double', 'doubles', 'couple', 'couples', 'duet', 'duets', 'partner', 'partners',
            'dvojice', 'dvojic', 'par', 'pary', 'paru',
            'paar', 'paare', 'duett', 'doppel', 'zweier',
            'pareja', 'parejas', 'duplas', 'dupla',
            'binome', 'binomes', 'paire', 'paires',
            'ペア', '二人', 'ふたり', '2人', 'デュオ', 'ダブルス',
        ],
        'team' => [
            'team', 'teams', 'group', 'groups', 'family', 'trio', 'trios',
            'tym', 'tymy', 'tymu', 'skupina', 'skupiny',
            'gruppe', 'gruppen', 'mannschaft', 'familie',
            'equipo', 'equipos', 'grupo', 'grupos', 'familia',
            'equipe', 'equipes', 'groupe', 'groupes', 'famille',
            'チーム', 'グループ', '団体',
        ],
    ];

    /** Too short or too common to mean anything in a title - a cell holding nothing else does mean it */
    private const array ONLY_IN_A_CELL = [
        'solo' => ['me', '1'],
        'duo' => ['2'],
        'team' => [],
    ];

    /**
     * What a cell of a solo / pair / team column says - the whole cell, folded (ImportText::plain()).
     */
    public static function ofCell(string $plainWord): null|PuzzlingType
    {
        foreach (self::WORDS as $type => $words) {
            if (in_array($plainWord, $words, true) || in_array($plainWord, self::ONLY_IN_A_CELL[$type], true)) {
                return PuzzlingType::from($type);
            }
        }

        return null;
    }

    /** Folded phrases of a title that say pair - several words, so no single one of them is in the list */
    private const array PAIR_PHRASES = ['zu zweit', 'a deux', 've dvou', 've dvojici', 'en pareja', 'for two', 'two of us'];

    /** German writes it in one word: Paarpuzzle, Teamwertung, Doppelwertung, Gruppenpuzzle - a word that begins so */
    private const array GERMAN_STEMS = ['duo' => ['paar', 'doppel', 'zweier'], 'team' => ['team', 'gruppen', 'mannschaft']];

    /** "2 player", "2-player", "2 Personen": read before the digits are taken out of the title */
    private const string TWO_PEOPLE = '/(?<!\d)2[\s-]?(?:player|players|people|person|persons|personen|spieler|joueurs|jugadores|personas|hraci|hracu|osoby)\b/u';

    /**
     * The kinds a sheet's title names, by any of their words.
     *
     * @return array<string, true> PuzzlingType's value => true
     */
    private static function kindsIn(string $title): array
    {
        $folded = SearchText::fold($title);
        $words = explode(' ', trim(preg_replace('/[^\p{L}]+/u', ' ', $folded) ?? ''));
        $said = [];

        foreach (self::WORDS as $type => $typeWords) {
            foreach ($typeWords as $word) {
                // A word of a script without spaces - or one with a digit in it (2人) - is looked for in the title as it is
                $isSearchedAsText = preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\d]/u', $word) === 1;

                if (in_array($word, $words, true) || ($isSearchedAsText && str_contains($folded, $word))) {
                    $said[$type] = true;

                    break;
                }
            }
        }

        foreach (self::GERMAN_STEMS as $type => $stems) {
            foreach ($words as $word) {
                foreach ($stems as $stem) {
                    if (strlen($word) > strlen($stem) && str_starts_with($word, $stem)) {
                        $said[$type] = true;
                    }
                }
            }
        }

        $spaced = ' ' . trim(preg_replace('/[^\p{L}\d]+/u', ' ', $folded) ?? '') . ' ';

        foreach (self::PAIR_PHRASES as $phrase) {
            if (str_contains($spaced, ' ' . $phrase . ' ')) {
                $said['duo'] = true;
            }
        }

        if (preg_match(self::TWO_PEOPLE, $folded) === 1) {
            $said['duo'] = true;
        }

        return $said;
    }

    /**
     * Whether a sheet's title names pair or team results **at all** - then the sheet asks, whatever else the title
     * says ("Pairs with Sam", "Pairs and Teams", "Doubles - single sitting"). A solo word alone never asks.
     */
    public static function titleNamesPairOrTeam(string $title): bool
    {
        $said = self::kindsIn($title);

        return isset($said['duo']) || isset($said['team']);
    }

    /**
     * The one kind a sheet's title says: "500 piece Pairs" → pair, "Doubles - single sitting" → pair (a solo word
     * beside it says nothing), "Solo 2023" → solo, "Týmy" → team. Null when it says nothing - or pair **and** team
     * ("Pairs and teams": asked, with nothing suggested).
     */
    public static function ofTitle(string $title): null|PuzzlingType
    {
        $said = self::kindsIn($title);

        return match (true) {
            isset($said['duo'], $said['team']) => null,
            isset($said['duo']) => PuzzlingType::Duo,
            isset($said['team']) => PuzzlingType::Team,
            isset($said['solo']) => PuzzlingType::Solo,
            default => null,
        };
    }

    /**
     * Whether the title says another kind than `$type` - a title that says nothing disagrees with nothing.
     */
    public static function titleDisagrees(string $title, null|PuzzlingType $type): bool
    {
        $said = self::ofTitle($title);

        return $said !== null && $type !== null && $said !== $type;
    }
}
