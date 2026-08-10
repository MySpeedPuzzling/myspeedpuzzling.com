<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CompetitionFormDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testYearLongPlaceholderDatesAreRejected(): void
    {
        $data = new CompetitionFormData(
            name: 'Bucharest Jigsaw Puzzle Championship',
            location: 'Bucharest',
            dateFrom: new DateTimeImmutable('2026-01-01'),
            dateTo: new DateTimeImmutable('2026-12-31'),
            isOnline: false,
        );

        $violations = $this->validator->validate($data);

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains('dateTo', $paths, 'A 365-day event must be rejected as placeholder dates');
    }

    public function testReasonableMultiDayEventIsAccepted(): void
    {
        $data = new CompetitionFormData(
            name: 'World Jigsaw Puzzle Championship',
            location: 'Valladolid',
            dateFrom: new DateTimeImmutable('2026-09-15'),
            dateTo: new DateTimeImmutable('2026-09-21'),
            isOnline: false,
        );

        $violations = $this->validator->validate($data);

        self::assertCount(0, $violations);
    }

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        $data = new CompetitionFormData(
            name: 'Backwards Event',
            location: 'Prague',
            dateFrom: new DateTimeImmutable('2026-09-21'),
            dateTo: new DateTimeImmutable('2026-09-15'),
            isOnline: false,
        );

        $violations = $this->validator->validate($data);

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertContains('dateTo', $paths);
    }
}
