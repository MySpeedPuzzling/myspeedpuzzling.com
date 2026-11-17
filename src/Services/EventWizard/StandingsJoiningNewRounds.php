<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Which standings a round added later in the wizard's schedule table joins (creation-wizard.md "Rounds added later"):
 * an unpublished standing that adds up **every** round of its category - what "add up the times per category" made,
 * or what the organiser keeps that way. A standing edited by hand to leave a round of its category out, and a published
 * one (its public table would change under its readers), is never touched. Advancement steps are never joined: whether a
 * new round is a group, a semifinal or a final is the organiser's call (the page points to the qualification page).
 */
final class StandingsJoiningNewRounds
{
    /**
     * @param list<array{id: string, category: RoundCategory, roundIds: list<string>, published: bool}> $standings
     * @param array<string, RoundCategory> $rounds the event's rounds now: id => category
     * @return list<string> the ids of the standings a new round of their category joins
     */
    public static function of(array $standings, array $rounds): array
    {
        $joining = [];

        foreach ($standings as $standing) {
            if ($standing['published']) {
                continue;
            }

            $ofCategory = array_keys(array_filter($rounds, static fn (RoundCategory $category): bool => $category === $standing['category']));
            $held = array_map('strtolower', $standing['roundIds']);

            if ($ofCategory !== [] && array_diff(array_map('strtolower', $ofCategory), $held) === []) {
                $joining[] = $standing['id'];
            }
        }

        return $joining;
    }
}
