<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleMergeRequestControllerTest extends WebTestCase
{
    public function testListIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/puzzle-merge-requests');

        $this->assertResponseRedirects('/login?return=/admin/puzzle-merge-requests');
    }

    public function testApproveIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-merge-requests/00000000-0000-0000-0000-000000000000/approve');

        $this->assertResponseRedirects('/login');
    }

    public function testRejectIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-merge-requests/00000000-0000-0000-0000-000000000000/reject');

        $this->assertResponseRedirects('/login');
    }

    public function testTheReviewKeepsThePuzzleWithTheMostTimesAndPrefillsTheMergedOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-merge-review-target="card"]');

        $values = $crawler->filter('form[data-controller~="merge-review"]')->form()->getValues();
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $values['survivor_puzzle_id']);
        self::assertSame('Puzzle 1', $values['merged_name']);
        // The names differ - each is one click away
        self::assertSelectorExists('[data-merge-review-target="choice"][data-input="merged_name"][data-value="Puzzle 2"]');
    }

    public function testApprovingKeepsTheNoteInTheHistory(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="merge-review"]')->form();

        $browser->submit($form, [
            'merged_name' => 'Merged In The Review',
            'decision_note' => 'Same EAN on both boxes',
        ]);

        self::assertResponseRedirects('/admin/puzzle-merge-requests');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $decision = $entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'mergeRequestId' => Uuid::fromString(PuzzleReportFixture::MERGE_REQUEST_PENDING),
        ]);
        self::assertNotNull($decision);
        self::assertSame('Same EAN on both boxes', $decision->note);
        self::assertSame('Merged In The Review', $decision->puzzleName);
    }
}
