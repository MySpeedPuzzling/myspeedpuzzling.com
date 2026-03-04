<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Competition;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

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
        #[Assert\NotBlank]
        public null|string $location = null,
        public null|string $locationCountryCode = null,
        public null|DateTimeImmutable $dateFrom = null,
        public null|DateTimeImmutable $dateTo = null,
        public bool $isOnline = false,
        public null|UploadedFile $logo = null,
        /** @var array<string> */
        public array $maintainers = [],
    ) {
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
