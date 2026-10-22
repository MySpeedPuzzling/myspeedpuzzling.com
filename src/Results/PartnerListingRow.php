<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * One open listing on the partner finder page, as the signed-in viewer may see it (GetPartnerListings::forEvent()).
 * A private player hidden from the viewer ($private) keeps name, flag and the listing - no stats, no avatar, no profile
 * link (D5): their numbers are NULL already in SQL. $skillTier is NULL for a viewer without membership, $mspRating and
 * $skillTier for a player who opted out of rankings.
 */
readonly final class PartnerListingRow
{
    // "Similar to you" within this share of the viewer's best 500 either way
    public const float SIMILAR_SPEED_SHARE = 0.10;

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<PartnerCoMember> $coMembers
     * @param list<PartnerStrength> $strengths
     * @param list<string> $customStrengths
     * @param list<string> $languages
     */
    public function __construct(
        public string $listingId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $countryCode,
        public null|string $avatar,
        public bool $private,
        public bool $own,
        public int $groupSize,
        public array $lookingFor,
        public null|int $needed,
        public array $coMembers,
        public null|PartnerGoal $goal,
        public array $strengths,
        public array $customStrengths,
        public array $languages,
        public null|string $note,
        public DateTimeImmutable $createdAt,
        public null|int $best500Seconds,
        public null|int $best1000Seconds,
        public null|int $solvedTotal,
        public null|int $mspRating,
        public null|SkillTier $skillTier,
        public bool $rankingOptedOut,
        // Managed registration only: holds a spot (the going rule); null on a plain event, where listing = going
        public null|bool $registered,
        public bool $sent,
        public int $requestsCount,
    ) {
    }

    public function isGroup(): bool
    {
        return $this->groupSize > 1;
    }

    public function looksFor(PartnerKind $kind): bool
    {
        return in_array($kind, $this->lookingFor, true);
    }

    /**
     * The kind filter's keys: `pair` / `team` for somebody on their own, `group` for a group.
     *
     * @return list<string>
     */
    public function kindKeys(): array
    {
        if ($this->isGroup()) {
            return ['group'];
        }

        return array_map(static fn (PartnerKind $kind): string => $kind->value, $this->lookingFor);
    }

    /**
     * The "Good at" filter's keys: preset values and `c:` + a hash of each folded custom chip.
     *
     * @return list<string>
     */
    public function strengthKeys(): array
    {
        $keys = array_map(static fn (PartnerStrength $strength): string => $strength->value, $this->strengths);

        foreach ($this->customStrengths as $chip) {
            $keys[] = self::customKey($chip);
        }

        return $keys;
    }

    public static function customKey(string $chip): string
    {
        return 'c:' . substr(md5(SearchText::fold($chip)), 0, 12);
    }

    /**
     * 'similar' | 'faster' | 'slower' against the viewer's best 500, null when either is unknown or it is the own row.
     */
    public function speedComparedTo(null|int $viewerBest500Seconds): null|string
    {
        if ($this->own || $viewerBest500Seconds === null || $viewerBest500Seconds <= 0 || $this->best500Seconds === null) {
            return null;
        }

        $share = $this->best500Seconds / $viewerBest500Seconds;

        return match (true) {
            $share < 1 - self::SIMILAR_SPEED_SHARE => 'faster',
            $share > 1 + self::SIMILAR_SPEED_SHARE => 'slower',
            default => 'similar',
        };
    }

    /**
     * How far from the viewer's speed (for "Closest to my speed"), in thousandths of the log ratio; null = unknown.
     */
    public function speedDistance(null|int $viewerBest500Seconds): null|int
    {
        if ($viewerBest500Seconds === null || $viewerBest500Seconds <= 0 || $this->best500Seconds === null || $this->best500Seconds <= 0) {
            return null;
        }

        return (int) round(abs(log($this->best500Seconds / $viewerBest500Seconds)) * 1000);
    }
}
