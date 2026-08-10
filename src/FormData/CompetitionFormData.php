<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Competition;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback('validateOfflineFields')]
#[Assert\Callback('validateDates')]
final class CompetitionFormData
{
    private const int MAX_DURATION_DAYS = 30;

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
    ) {
    }

    public function validateOfflineFields(ExecutionContextInterface $context): void
    {
        if ($this->isOnline !== false) {
            return;
        }

        if ($this->location === null || $this->location === '') {
            $context->buildViolation('This value should not be blank.')
                ->atPath('location')
                ->addViolation();
        }

        if ($this->isRecurring) {
            return;
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

        $maintainerIds = [];
        foreach ($competition->maintainers as $maintainer) {
            $maintainerIds[] = $maintainer->id->toString();
        }
        $data->maintainers = $maintainerIds;

        return $data;
    }
}
