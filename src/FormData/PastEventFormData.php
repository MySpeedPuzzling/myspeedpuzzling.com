<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The backfill path's one quick form (creation-wizard.md "Backfill"): the event that already happened - name, its day,
 * where - and, only when the player says it belongs to one, its series or organization (an existing one of theirs or a
 * new one by its name).
 */
final class PastEventFormData
{
    public const string NEW = 'new';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public null|string $name = null;

    #[Assert\NotNull]
    public null|DateTimeImmutable $date = null;

    public bool $isOnline = false;

    #[Assert\Length(max: 255)]
    public null|string $location = null;

    public null|string $locationCountryCode = null;

    public bool $partOf = false;

    // '' = none, a series id the player may add dates to, or NEW
    public null|string $series = null;

    #[Assert\Length(max: 255)]
    public null|string $newSeriesName = null;

    // '' = none, an organization the player is on the team of, or NEW
    public null|string $organization = null;

    #[Assert\Length(max: 255)]
    public null|string $newOrganizationName = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->isOnline === false && self::blank($this->location)) {
            $context->buildViolation('This value should not be blank.')->atPath('location')->addViolation();
        }

        if ($this->partOf && $this->series === self::NEW && self::blank($this->newSeriesName)) {
            $context->buildViolation('This value should not be blank.')->atPath('newSeriesName')->addViolation();
        }

        if ($this->partOf && $this->organization === self::NEW && self::blank($this->newOrganizationName)) {
            $context->buildViolation('This value should not be blank.')->atPath('newOrganizationName')->addViolation();
        }
    }

    /**
     * The chosen existing series id - null for none or a new one
     */
    public function existingSeriesId(): null|string
    {
        return $this->partOf && $this->series !== null && $this->series !== '' && $this->series !== self::NEW ? $this->series : null;
    }

    public function wantsNewSeries(): bool
    {
        return $this->partOf && $this->series === self::NEW;
    }

    public function existingOrganizationId(): null|string
    {
        return $this->partOf && $this->organization !== null && $this->organization !== '' && $this->organization !== self::NEW ? $this->organization : null;
    }

    public function wantsNewOrganization(): bool
    {
        return $this->partOf && $this->organization === self::NEW;
    }

    private static function blank(null|string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
