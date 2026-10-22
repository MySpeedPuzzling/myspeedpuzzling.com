<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\PlainNumber;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A partner finder listing the rules refuse (docs/features/partner-finder.md): thrown by PartnerStrengthNormalizer and
 * by PartnerListing itself before anything changes. The form shows the translated $translationKey on $field (one of the
 * FIELD_* constants); the message is for the logs.
 */
final class PartnerListingInvalid extends UnprocessableEntityHttpException
{
    public const string FIELD_STRENGTHS = 'strengths';
    public const string FIELD_CUSTOM_STRENGTHS = 'customStrengths';
    public const string FIELD_GROUP_SIZE = 'groupSize';
    public const string FIELD_NEEDED = 'needed';
    public const string FIELD_LOOKING_FOR = 'lookingFor';
    public const string FIELD_CO_MEMBERS = 'coMembers';
    public const string FIELD_LANGUAGES = 'languages';
    public const string FIELD_NOTE = 'note';

    /**
     * @param array<string, int|string> $translationParameters
     */
    public function __construct(
        string $reason,
        public readonly string $field,
        public readonly string $translationKey,
        public readonly array $translationParameters = [],
    ) {
        parent::__construct('Invalid partner finder listing: ' . $reason);
    }

    public static function unknownStrength(string $value): self
    {
        return new self(sprintf('unknown strength "%s"', $value), self::FIELD_STRENGTHS, 'partner_finder.invalid.strength_unknown');
    }

    public static function tooManyStrengths(int $max): self
    {
        return new self(sprintf('more than %d strengths', $max), self::FIELD_STRENGTHS, 'partner_finder.invalid.too_many_strengths', ['%max%' => PlainNumber::format($max)]);
    }

    public static function tooManyCustomStrengths(int $max): self
    {
        return new self(sprintf('more than %d custom strengths', $max), self::FIELD_CUSTOM_STRENGTHS, 'partner_finder.invalid.too_many_custom_strengths', ['%max%' => PlainNumber::format($max)]);
    }

    public static function customStrengthTooLong(string $chip, int $max): self
    {
        return new self(sprintf('custom strength "%s" longer than %d characters', $chip, $max), self::FIELD_CUSTOM_STRENGTHS, 'partner_finder.invalid.custom_strength_too_long', [
            '%chip%' => $chip,
            '%max%' => PlainNumber::format($max),
        ]);
    }

    public static function customStrengthCharacters(): self
    {
        return new self('custom strength with < > or control characters', self::FIELD_CUSTOM_STRENGTHS, 'partner_finder.invalid.custom_strength_characters');
    }

    public static function groupSize(int $max): self
    {
        return new self('group size out of range', self::FIELD_GROUP_SIZE, 'partner_finder.invalid.group_size', ['%max%' => PlainNumber::format($max)]);
    }

    public static function needed(): self
    {
        return new self('needed missing or out of range', self::FIELD_NEEDED, 'partner_finder.invalid.needed');
    }

    public static function lookingFor(): self
    {
        return new self('nothing looked for', self::FIELD_LOOKING_FOR, 'partner_finder.invalid.looking_for');
    }

    public static function pairForGroup(): self
    {
        return new self('a group looking for a pair partner', self::FIELD_LOOKING_FOR, 'partner_finder.invalid.pair_for_group');
    }

    public static function coMembers(): self
    {
        return new self('co-members invalid or more than the group holds', self::FIELD_CO_MEMBERS, 'partner_finder.invalid.co_members');
    }

    public static function languages(int $max): self
    {
        return new self('languages invalid or too many', self::FIELD_LANGUAGES, 'partner_finder.invalid.languages', ['%max%' => PlainNumber::format($max)]);
    }

    public static function noteTooLong(int $max): self
    {
        return new self(sprintf('note longer than %d characters', $max), self::FIELD_NOTE, 'partner_finder.invalid.note_too_long', ['%max%' => PlainNumber::format($max)]);
    }

    public function trans(TranslatorInterface $translator): string
    {
        return $translator->trans($this->translationKey, $this->translationParameters);
    }
}
