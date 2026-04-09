<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Competition;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback('validateOfflineFields')]
final class CompetitionFormData
{
    public function __construct(
        #[Assert\NotBlank]
        public null|string $name = null,
        public null|string $shortcut = null,
        public null|string $description = null,
        #[Assert\Url]
        public null|string $link = null,
        #[Assert\Url]
        public null|string $registrationLink = null,
        #[Assert\Url]
        public null|string $resultsLink = null,
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
