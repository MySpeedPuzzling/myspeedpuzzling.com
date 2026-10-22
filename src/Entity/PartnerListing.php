<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Results\PartnerStrengthsNormalized;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use SpeedPuzzling\Web\Value\PartnerStrength;

/**
 * A puzzler going to an in-person event looking for a pair partner, a team, or more people for their group
 * (docs/features/partner-finder.md). One open listing per player and event (custom_partner_listing_one_open); a closed
 * one stays as history. Every value is checked before anything changes - PartnerListingInvalid carries the form error.
 * Whether the event offers what it looks for is the handler's check (PartnerFinderEligibility).
 */
#[Entity]
class PartnerListing
{
    public const int GROUP_SIZE_MAX = CompetitionRound::TEAM_SIZE_MAX;
    public const int MAX_LANGUAGES = 4;
    public const int NOTE_MAX_LENGTH = 200;

    // 1 = on my own, 2..GROUP_SIZE_MAX = a group
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT)]
    public int $groupSize;

    /** @var list<string> PartnerKind values - read lookingFor() */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB)]
    public array $lookingFor;

    // Groups only: how many more people they need
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $needed;

    /** @var list<string> registered players in the group besides the listing's player (player ids) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $coMemberIds;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true, enumType: PartnerGoal::class)]
    public null|PartnerGoal $goal;

    /** @var list<string> PartnerStrength values - read strengths() */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $strengths;

    /** @var list<string> shown as typed, never translated */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $customStrengths;

    /** @var list<string> ISO 639-1 codes */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $languages;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    public null|string $note;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;

    // null = open
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $closedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true, enumType: PartnerListingClosedReason::class)]
    public null|PartnerListingClosedReason $closedReason = null;

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds
     * @param list<string> $languages
     *
     * @throws PartnerListingInvalid
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        int $groupSize,
        array $lookingFor,
        null|int $needed,
        array $coMemberIds,
        null|PartnerGoal $goal,
        PartnerStrengthsNormalized $strengths,
        array $languages,
        null|string $note,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $values = self::validated($player, $groupSize, $lookingFor, $needed, $coMemberIds, $strengths, $languages, $note);

        $this->groupSize = $groupSize;
        $this->lookingFor = $values['lookingFor'];
        $this->needed = $needed;
        $this->coMemberIds = $values['coMemberIds'];
        $this->goal = $goal;
        $this->strengths = $strengths->presetValues();
        $this->customStrengths = $strengths->custom;
        $this->languages = $values['languages'];
        $this->note = $values['note'];
        $this->updatedAt = $createdAt;
    }

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds
     * @param list<string> $languages
     *
     * @throws PartnerListingInvalid
     */
    public function edit(
        int $groupSize,
        array $lookingFor,
        null|int $needed,
        array $coMemberIds,
        null|PartnerGoal $goal,
        PartnerStrengthsNormalized $strengths,
        array $languages,
        null|string $note,
        DateTimeImmutable $now,
    ): void {
        $values = self::validated($this->player, $groupSize, $lookingFor, $needed, $coMemberIds, $strengths, $languages, $note);

        $this->groupSize = $groupSize;
        $this->lookingFor = $values['lookingFor'];
        $this->needed = $needed;
        $this->coMemberIds = $values['coMemberIds'];
        $this->goal = $goal;
        $this->strengths = $strengths->presetValues();
        $this->customStrengths = $strengths->custom;
        $this->languages = $values['languages'];
        $this->note = $values['note'];
        $this->updatedAt = $now;
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }

    public function isGroup(): bool
    {
        return $this->groupSize > 1;
    }

    /**
     * Closing a closed listing changes nothing - the first reason stays.
     */
    public function close(PartnerListingClosedReason $reason, DateTimeImmutable $now): void
    {
        if ($this->closedAt !== null) {
            return;
        }

        $this->closedAt = $now;
        $this->closedReason = $reason;
        $this->updatedAt = $now;
    }

    /**
     * "Found my partner" (D7): a group still needing more than one person counts down, anything else closes as found.
     */
    public function reduceNeeded(DateTimeImmutable $now): void
    {
        if ($this->closedAt !== null) {
            return;
        }

        if ($this->needed !== null && $this->needed > 1) {
            $this->needed--;
            $this->updatedAt = $now;

            return;
        }

        $this->close(PartnerListingClosedReason::Found, $now);
    }

    /**
     * @return list<PartnerKind>
     */
    public function lookingFor(): array
    {
        return array_values(array_filter(array_map(PartnerKind::tryFrom(...), $this->lookingFor)));
    }

    /**
     * @return list<PartnerStrength>
     */
    public function strengths(): array
    {
        return array_values(array_filter(array_map(PartnerStrength::tryFrom(...), $this->strengths)));
    }

    /**
     * @return list<string>
     */
    public function customStrengths(): array
    {
        return $this->customStrengths;
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->languages;
    }

    /**
     * @return list<string>
     */
    public function coMemberIds(): array
    {
        return $this->coMemberIds;
    }

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds
     * @param list<string> $languages
     *
     * @return array{lookingFor: list<string>, coMemberIds: list<string>, languages: list<string>, note: null|string}
     *
     * @throws PartnerListingInvalid
     */
    private static function validated(
        Player $player,
        int $groupSize,
        array $lookingFor,
        null|int $needed,
        array $coMemberIds,
        PartnerStrengthsNormalized $strengths,
        array $languages,
        null|string $note,
    ): array {
        if ($groupSize < 1 || $groupSize > self::GROUP_SIZE_MAX) {
            throw PartnerListingInvalid::groupSize(self::GROUP_SIZE_MAX);
        }

        $kinds = [];

        foreach (PartnerKind::cases() as $kind) {
            if (in_array($kind, $lookingFor, true)) {
                $kinds[] = $kind->value;
            }
        }

        if ($kinds === []) {
            throw PartnerListingInvalid::lookingFor();
        }

        // A group of two or more is a pair already - it looks for a team
        if ($groupSize > 1 && in_array(PartnerKind::Pair->value, $kinds, true)) {
            throw PartnerListingInvalid::pairForGroup();
        }

        if ($groupSize === 1 ? $needed !== null : ($needed === null || $needed < 1 || $groupSize + $needed > self::GROUP_SIZE_MAX)) {
            throw PartnerListingInvalid::needed();
        }

        $members = [];

        foreach ($coMemberIds as $coMemberId) {
            if (Uuid::isValid($coMemberId) === false) {
                throw PartnerListingInvalid::coMembers();
            }

            $coMemberId = strtolower($coMemberId);

            if ($coMemberId !== $player->id->toString() && in_array($coMemberId, $members, true) === false) {
                $members[] = $coMemberId;
            }
        }

        if (count($members) > $groupSize - 1) {
            throw PartnerListingInvalid::coMembers();
        }

        if (count($strengths->presets) > PartnerStrengthsNormalized::MAX_PRESETS) {
            throw PartnerListingInvalid::tooManyStrengths(PartnerStrengthsNormalized::MAX_PRESETS);
        }

        if (count($strengths->custom) > PartnerStrengthsNormalized::MAX_CUSTOM) {
            throw PartnerListingInvalid::tooManyCustomStrengths(PartnerStrengthsNormalized::MAX_CUSTOM);
        }

        $codes = [];

        foreach ($languages as $language) {
            $language = strtolower(trim($language));

            if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
                throw PartnerListingInvalid::languages(self::MAX_LANGUAGES);
            }

            if (in_array($language, $codes, true) === false) {
                $codes[] = $language;
            }
        }

        if (count($codes) > self::MAX_LANGUAGES) {
            throw PartnerListingInvalid::languages(self::MAX_LANGUAGES);
        }

        $note = $note !== null ? trim($note) : null;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw PartnerListingInvalid::noteTooLong(self::NOTE_MAX_LENGTH);
        }

        return [
            'lookingFor' => $kinds,
            'coMemberIds' => $members,
            'languages' => $codes,
            'note' => $note === '' ? null : $note,
        ];
    }
}
