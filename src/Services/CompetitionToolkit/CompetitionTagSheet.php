<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Query\GetCompetitionLineupTags;
use SpeedPuzzling\Web\Query\GetCompetitionNameTags;
use SpeedPuzzling\Web\Query\GetCompetitionTableTags;
use SpeedPuzzling\Web\Query\GetRoundEntryTags;
use SpeedPuzzling\Web\Results\CompetitionLineupChange;
use SpeedPuzzling\Web\Results\CompetitionLineupTag;
use SpeedPuzzling\Web\Results\CompetitionNameTag;
use SpeedPuzzling\Web\Results\CompetitionRoundEntryTag;
use SpeedPuzzling\Web\Results\CompetitionTagMember;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Value\CompetitionTagPrintKind;
use SpeedPuzzling\Web\Value\CompetitionTagPrintLayout;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The toolkit's print page of a competition's tags (competition_name_tags with `?kind=` / `?layout=`, competition
 * toolkit B-qr-competitor-page.md §3.4): what to print - people (the name tags of before), pair/team line-ups, tables or
 * the entries of one round - and how - the A4 sheet of tags or table tent cards. The template variables of
 * live_results/competition_tags.html.twig; each printed tag is a plain array of what its card shows, with its QR (the
 * kind's frozen tag path, NameTagQrCode).
 *
 * Tables: every number from 1 to the highest one the event (or the round) uses - a table nobody sits at yet still gets
 * its card -, or the range the organiser asks for (`?from=` / `?to=`, at most MAX_TABLES cards at once).
 */
readonly final class CompetitionTagSheet
{
    public const int MAX_TABLES = 300;
    public const int HIGHEST_TABLE = 9999;

    public function __construct(
        private GetCompetitionNameTags $getCompetitionNameTags,
        private GetCompetitionLineupTags $getCompetitionLineupTags,
        private GetCompetitionTableTags $getCompetitionTableTags,
        private GetRoundEntryTags $getRoundEntryTags,
        private NameTagQrCode $nameTagQrCode,
    ) {
    }

    /**
     * @param list<RoundResultsOverview> $rounds
     * @return array<string, mixed>
     */
    public function variables(Request $request, string $competitionId, array $rounds, null|RoundResultsOverview $round, CompetitorPageSettings $settings): array
    {
        $pairsAndTeamsOnly = $rounds !== [] && array_all($rounds, static fn (RoundResultsOverview $overview): bool => $overview->category !== 'solo');
        $defaultKind = CompetitionTagPrintKind::defaultFor($settings->qrTagMode, $pairsAndTeamsOnly);
        $kind = CompetitionTagPrintKind::tryFrom($request->query->getString('kind')) ?? $defaultKind;
        $layout = CompetitionTagPrintLayout::tryFrom($request->query->getString('layout')) ?? $kind->defaultLayout();

        $sort = $request->query->getString('sort');

        if ($sort !== GetCompetitionNameTags::SORT_NAME && $sort !== GetCompetitionNameTags::SORT_TABLE) {
            $sort = $kind->sortsByTableByDefault() ? GetCompetitionNameTags::SORT_TABLE : GetCompetitionNameTags::SORT_NAME;
        }

        // A round's entries need a round: the first one by schedule unless one is chosen
        if ($kind === CompetitionTagPrintKind::Entries && $round === null) {
            $round = $rounds[0] ?? null;
        }

        $variables = [
            'kind' => $kind->value,
            'default_kind' => $defaultKind->value,
            'layout' => $layout->value,
            'kinds' => array_map(static fn (CompetitionTagPrintKind $option): string => $option->value, CompetitionTagPrintKind::cases()),
            'layouts' => array_map(static fn (CompetitionTagPrintLayout $option): string => $option->value, CompetitionTagPrintLayout::cases()),
            'round' => $round,
            'sort' => $sort,
            'with_waitlist' => false,
            'waitlisted_count' => 0,
            'lineup_changes' => [],
            'tables_from' => null,
            'tables_to' => null,
            'tables_used' => [],
            'competitor_pages' => $settings->competitorPages,
        ];

        return match ($kind) {
            CompetitionTagPrintKind::People => $this->people($request, $competitionId, $round, $sort) + $variables,
            CompetitionTagPrintKind::Lineups => $this->lineups($competitionId, $round, $sort) + $variables,
            CompetitionTagPrintKind::Tables => $this->tables($request, $competitionId, $round) + $variables,
            CompetitionTagPrintKind::Entries => $this->entries($competitionId, $round, $sort) + $variables,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function people(Request $request, string $competitionId, null|RoundResultsOverview $round, string $sort): array
    {
        $withWaitlist = $request->query->getBoolean('waitlist');
        $tags = $this->getCompetitionNameTags->forCompetition($competitionId, $round?->roundId, $sort, withWaitlist: true);
        $waitlisted = count(array_filter($tags, static fn (CompetitionNameTag $tag): bool => $tag->waitlisted));

        if ($withWaitlist === false) {
            $tags = array_values(array_filter($tags, static fn (CompetitionNameTag $tag): bool => $tag->waitlisted === false));
        }

        $locale = $request->getLocale();

        return [
            'with_waitlist' => $withWaitlist,
            'waitlisted_count' => $waitlisted,
            'tags' => array_map(fn (CompetitionNameTag $tag): array => [
                'heading' => $tag->name,
                'members' => [['name' => null, 'country' => $tag->country, 'code' => $tag->playerCode]],
                'table_number' => $tag->tableNumber,
                'table' => false,
                'qr_url' => $url = $this->nameTagQrCode->url($competitionId, $tag->participantId, $locale),
                'qr' => $this->nameTagQrCode->svg($url),
            ], $tags),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineups(string $competitionId, null|RoundResultsOverview $round, string $sort): array
    {
        $lineups = $this->getCompetitionLineupTags->ofCompetition($competitionId, $round?->roundId, $sort);

        return [
            'lineup_changes' => array_map(static fn (CompetitionLineupChange $change): array => [
                'lineup' => $change->lineupLabel,
                'round' => $change->laterRoundName,
                'later_lineup' => $change->laterLineupLabel,
            ], $lineups->changes),
            'tags' => array_map(fn (CompetitionLineupTag $tag): array => [
                'heading' => $tag->teamName,
                'members' => self::members($tag->members),
                'table_number' => $tag->tableNumber,
                'table' => false,
                'qr_url' => $url = $this->nameTagQrCode->lineupUrl($competitionId, $tag->lineupId),
                'qr' => $this->nameTagQrCode->svg($url),
            ], $lineups->tags),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tables(Request $request, string $competitionId, null|RoundResultsOverview $round): array
    {
        $used = $this->getCompetitionTableTags->usedNumbers($competitionId, $round?->roundId);

        $from = self::tableNumber($request->query->getString('from')) ?? 1;
        $to = self::tableNumber($request->query->getString('to')) ?? ($used === [] ? 0 : max($used));
        $to = min($to, $from + self::MAX_TABLES - 1);
        $numbers = $to >= $from ? range($from, $to) : [];

        return [
            'tables_from' => $from,
            'tables_to' => $to >= $from ? $to : null,
            'tables_used' => $used,
            'tags' => array_map(fn (int $number): array => [
                'heading' => null,
                'members' => [],
                'table_number' => $number,
                'table' => true,
                'qr_url' => $url = $this->nameTagQrCode->tableUrl($competitionId, $number),
                'qr' => $this->nameTagQrCode->svg($url),
            ], $numbers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entries(string $competitionId, null|RoundResultsOverview $round, string $sort): array
    {
        $tags = $round === null ? [] : $this->getRoundEntryTags->ofRound($competitionId, $round->roundId, $sort);

        return [
            'tags' => array_map(fn (CompetitionRoundEntryTag $tag): array => [
                'heading' => $tag->teamName ?? (count($tag->members) === 1 ? $tag->members[0]->name : null),
                'members' => $tag->teamName === null && count($tag->members) === 1
                    ? [['name' => null, 'country' => $tag->members[0]->country, 'code' => $tag->members[0]->playerCode]]
                    : self::members($tag->members),
                'table_number' => $tag->tableNumber,
                'table' => false,
                'qr_url' => $url = $this->nameTagQrCode->entryUrl($competitionId, $tag->entryId),
                'qr' => $this->nameTagQrCode->svg($url),
            ], $tags),
        ];
    }

    /**
     * @param list<CompetitionTagMember> $members
     * @return list<array{name: string, country: null|string, code: null|string}>
     */
    private static function members(array $members): array
    {
        return array_map(static fn (CompetitionTagMember $member): array => [
            'name' => $member->name,
            'country' => $member->country,
            'code' => $member->playerCode,
        ], $members);
    }

    private static function tableNumber(string $value): null|int
    {
        if (preg_match('/^\d{1,4}$/', trim($value)) !== 1) {
            return null;
        }

        $number = (int) trim($value);

        return $number >= 1 && $number <= self::HIGHEST_TABLE ? $number : null;
    }
}
