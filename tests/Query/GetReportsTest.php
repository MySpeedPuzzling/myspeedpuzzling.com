<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetReports;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationReportFixture;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetReportsTest extends KernelTestCase
{
    private GetReports $getReports;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getReports = self::getContainer()->get(GetReports::class);
    }

    public function testPendingReports(): void
    {
        $reports = $this->getReports->pending();

        self::assertNotEmpty($reports);

        foreach ($reports as $report) {
            self::assertSame(ReportStatus::Pending, $report->status);
        }
    }

    public function testAllReports(): void
    {
        $reports = $this->getReports->all();

        self::assertNotEmpty($reports);
        self::assertGreaterThanOrEqual(2, count($reports));
    }

    public function testReportById(): void
    {
        $report = $this->getReports->byId(ConversationReportFixture::REPORT_PENDING);

        self::assertSame(ConversationReportFixture::REPORT_PENDING, $report->reportId);
        self::assertSame(ReportStatus::Pending, $report->status);
        self::assertSame('Inappropriate messages', $report->reason);
    }

    public function testCountByStatus(): void
    {
        $counts = $this->getReports->countByStatus();

        self::assertGreaterThanOrEqual(1, $counts['pending']);
        self::assertGreaterThanOrEqual(2, $counts['all']);
    }
}
