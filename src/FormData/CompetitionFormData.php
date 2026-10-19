<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Value\EventType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback('validateOfflineFields')]
#[Assert\Callback('validateDates')]
final class CompetitionFormData
{
    // A season (a weekly league of 6-12 weeks, its weeks = rounds) fits, a placeholder year does not
    private const int MAX_DURATION_DAYS = 92;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public null|string $name = null,
        #[Assert\Length(max: 250)]
        public null|string $shortcut = null,
        public null|string $description = null,
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $link = null,
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $registrationLink = null,
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $resultsLink = null,
        #[Assert\Length(max: 250)]
        public null|string $location = null,
        public null|string $locationCountryCode = null,
        public null|DateTimeImmutable $dateFrom = null,
        public null|DateTimeImmutable $dateTo = null,
        #[Assert\NotNull]
        public null|bool $isOnline = null,
        public bool $isRecurring = false,
        public null|UploadedFile $logo = null,
        /** @var array<string> */
        public array $maintainers = [],
        // The "URL" field of the edit forms (CompetitionFormType `url_field`), checked by CompetitionUrlField
        public null|string $slug = null,
        // docs/features/organizations/README.md - the "Organization" select (one-time events and series only)
        public null|string $organizationId = null,
        // "Who can enter"
        #[Assert\Length(max: 120)]
        public null|string $eligibility = null,
        // What kind of event - none = not set (EventTypesType)
        /** @var list<EventType> */
        public array $eventTypes = [],
        // "When it happens" (series only)
        #[Assert\Length(max: 160)]
        public null|string $schedule = null,
    ) {
    }

    public function validateOfflineFields(ExecutionContextInterface $context): void
    {
        if ($this->isOnline !== false) {
            return;
        }

        // A series has no place of its own: every edition has its own location, dates, rounds, participants and
        // results - the series' location is optional, filled in only when it always takes place at the same venue
        if ($this->isRecurring) {
            return;
        }

        if ($this->location === null || trim($this->location) === '') {
            $context->buildViolation('This value should not be blank.')
                ->atPath('location')
                ->addViolation();
        }

        if ($this->dateFrom === null) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('dateFrom')
                ->addViolation();
        }

        if ($this->dateTo === null) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('dateTo')
                ->addViolation();
        }
    }

    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->dateFrom === null || $this->dateTo === null) {
            return;
        }

        if ($this->dateTo < $this->dateFrom) {
            $context->buildViolation('competition_date_to_before_date_from')
                ->atPath('dateTo')
                ->addViolation();

            return;
        }

        // Placeholder ranges like 1.1.–31.12. ("sometime this year") must not
        // enter the system — they would show as a live event the entire year.
        if ($this->dateFrom->diff($this->dateTo)->days > self::MAX_DURATION_DAYS) {
            $context->buildViolation('competition_duration_too_long', ['%limit%' => self::MAX_DURATION_DAYS])
                ->atPath('dateTo')
                ->addViolation();
        }
    }

    public static function fromCompetition(Competition $competition): self
    {
        $data = new self();
        $data->name = $competition->name;
        $data->shortcut = $competition->shortcut;
        $data->description = $competition->description;
        $data->link = $competition->link;
        $data->registrationLink = $competition->registrationLink;
        $data->resultsLink = $competition->resultsLink;
        $data->location = $competition->location;
        $data->locationCountryCode = $competition->locationCountryCode;
        $data->dateFrom = $competition->dateFrom;
        $data->dateTo = $competition->dateTo;
        $data->isOnline = $competition->isOnline;
        $data->eligibility = $competition->eligibility;
        // An edition without its own shows its series' - saved unchanged it keeps following them
        $data->eventTypes = $competition->eventTypes()->types ?? [];
        $data->organizationId = $competition->organization?->id->toString();

        $maintainerIds = [];
        foreach ($competition->maintainers as $maintainer) {
            $maintainerIds[] = $maintainer->id->toString();
        }
        $data->maintainers = $maintainerIds;

        return $data;
    }
}
