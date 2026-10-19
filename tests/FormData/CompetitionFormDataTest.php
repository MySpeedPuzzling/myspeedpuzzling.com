<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
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
        $messages = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
            $messages[] = (string) $violation->getMessage();
        }

        self::assertContains('dateTo', $paths, 'A 365-day event must be rejected as placeholder dates');
        self::assertContains(
            'The event cannot be longer than 92 days. Please enter the actual event dates.',
            $messages,
            'The violation message must resolve through the validators translation domain, not render as a raw key',
        );
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

    public function testSixWeekLeagueIsAccepted(): void
    {
        $data = new CompetitionFormData(
            name: 'Winter Puzzle League',
            location: 'Pewaukee',
            dateFrom: new DateTimeImmutable('2027-01-18'),
            dateTo: new DateTimeImmutable('2027-02-22'),
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

    public function testAnInPersonSeriesNeedsNoLocation(): void
    {
        // Every edition has its own location - a series does not have to name one
        $data = new CompetitionFormData(
            name: 'Lantern Nights League',
            isOnline: false,
            isRecurring: true,
        );

        self::assertNotContains('location', self::violationPaths($this->validator->validate($data)));
    }

    public function testAnInPersonOneTimeEventStillNeedsALocation(): void
    {
        $data = new CompetitionFormData(
            name: 'Lantern Nights Open',
            dateFrom: new DateTimeImmutable('2026-11-14'),
            dateTo: new DateTimeImmutable('2026-11-14'),
            isOnline: false,
        );

        self::assertContains('location', self::violationPaths($this->validator->validate($data)));
    }

    /**
     * @return list<string>
     */
    private static function violationPaths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
