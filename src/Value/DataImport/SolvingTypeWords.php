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
            'duo', 'duos', 'pair', 'pairs', 'double', 'doubles', 'couple', 'couples',
            'dvojice', 'dvojic', 'par', 'pary', 'paru',
            'paar', 'paare', 'duett', 'doppel',
            'pareja', 'parejas', 'duplas', 'dupla',
            'binome', 'binomes', 'paire', 'paires',
            'ペア', '二人', 'ふたり',
        ],
        'team' => [
            'team', 'teams', 'group', 'groups', 'family',
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

    /**
     * What a sheet's title (or a column's header) says about all its results: "500 piece Pairs" → pair,
     * "Solo 2023" → solo, "Týmy" → team. Null when it says nothing - or two things ("Pairs and teams").
     */
    public static function ofTitle(string $title): null|PuzzlingType
    {
        $folded = SearchText::fold($title);
        $words = explode(' ', trim(preg_replace('/[^\p{L}]+/u', ' ', $folded) ?? ''));
        $said = [];

        foreach (self::WORDS as $type => $typeWords) {
            foreach ($typeWords as $word) {
                $isScriptWithoutSpaces = preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $word) === 1;

                if (in_array($word, $words, true) || ($isScriptWithoutSpaces && str_contains($folded, $word))) {
                    $said[$type] = true;

                    break;
                }
            }
        }

        return count($said) === 1 ? PuzzlingType::from((string) array_key_first($said)) : null;
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
