<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Message\PlanResultReviewEmails;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DuplicateResultsControllerTest extends WebTestCase
{
    public function testAdminSeesTheNumbersAndTheOpenCases(): void
    {
        $browser = $this->adminWithDetectedCases();

        $crawler = $browser->request('GET', '/admin/duplicate-results');

        $this->assertResponseIsSuccessful();
        // The Tier A copy was removed automatically: Dana Twin has three open cases left, her teammate one
        self::assertSame('4', $crawler->filter('.card .h3')->first()->text());
        self::assertSame('2', $crawler->filter('.card .h3')->eq(1)->text());
        self::assertCount(4, $crawler->filter('#cases ~ .table-responsive tbody tr'));
        self::assertCount(1, $crawler->filter('[data-testid="admin-auto-removals"] tbody tr'));
        self::assertStringContainsString('Twins Puzzle', $crawler->filter('#cases ~ .table-responsive')->text());
    }

    public function testTheContactsFunnelShowsTheCaps(): void
    {
        $browser = $this->adminWithDetectedCases();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new PlanResultReviewEmails());

        $crawler = $browser->request('GET', '/admin/duplicate-results');

        $this->assertResponseIsSuccessful();
        $contacts = $crawler->filter('[data-testid="admin-result-review-contacts"]');
        self::assertCount(1, $contacts);
        self::assertStringContainsString('at most 10 per run and 20 per day', $contacts->text());
        self::assertStringContainsString('Nothing sent yet.', $contacts->text());
    }

    public function testCasesFilterByTierAndKind(): void
    {
        $browser = $this->adminWithDetectedCases();

        $crawler = $browser->request('GET', '/admin/duplicate-results?tier=strong&kind=teammate_copy');

        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#cases ~ .table-responsive tbody tr'));

        $crawler = $browser->request('GET', '/admin/duplicate-results?tab=gone');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('No cases here.', $crawler->text());
    }

    public function testPlayersHaveNoBusinessHere(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/admin/duplicate-results');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testGuestIsSentToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/admin/duplicate-results');

        $this->assertResponseRedirects('/login?return=/admin/duplicate-results');
    }

    private function adminWithDetectedCases(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        return $browser;
    }
}
