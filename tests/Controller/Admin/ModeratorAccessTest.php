<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Query\GetModerators;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A community moderator reaches the puzzle review queues, a puzzle's direct edit and
 * its history, and nothing else under /admin - least of all the page that appoints
 * moderators.
 */
final class ModeratorAccessTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function moderatorPages(): iterable
    {
        yield 'puzzle change requests' => ['/admin/puzzle-change-requests'];
        yield 'puzzle merge requests' => ['/admin/puzzle-merge-requests'];
        yield 'puzzle approvals' => ['/admin/puzzle-approvals'];
        yield 'puzzle approval detail' => ['/admin/puzzle-approvals/' . PuzzleFixture::PUZZLE_UNAPPROVED];
        yield 'puzzle merge request detail' => ['/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING];
        yield 'puzzle edit' => ['/admin/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/edit'];
        yield 'puzzle history' => ['/admin/puzzles/' . PuzzleFixture::PUZZLE_500_01 . '/history'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminOnlyPages(): iterable
    {
        yield 'moderators' => ['/admin/moderators'];
        yield 'moderation' => ['/admin/moderation'];
        yield 'referrals' => ['/admin/referrals'];
        yield 'competition approvals' => ['/admin/competition-approvals'];
        yield 'email audit' => ['/admin/email-audit'];
        yield 'oauth2 requests' => ['/admin/oauth2-requests'];
        yield 'vouchers' => ['/admin/vouchers'];
    }

    #[DataProvider('moderatorPages')]
    public function testModeratorReachesThePuzzleReviewQueues(string $path): void
    {
        $browser = $this->signedInModerator();

        $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    public function testModeratorDecidesARequestAndIsCreditedAsReviewer(): void
    {
        $browser = $this->signedInModerator();

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertResponseIsSuccessful();

        $browser->request('POST', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING . '/reject', [
            'rejection_reason' => 'Not the same edition',
        ]);
        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $changeRequest = $browser->getContainer()->get(PuzzleChangeRequestRepository::class)->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Rejected, $changeRequest->status);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $changeRequest->reviewedBy?->id->toString());
    }

    #[DataProvider('adminOnlyPages')]
    public function testModeratorIsForbiddenEverywhereElseInAdmin(string $path): void
    {
        $browser = $this->signedInModerator();

        $browser->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testModeratorCannotAppointModerators(): void
    {
        $browser = $this->signedInModerator();

        $browser->request('POST', '/admin/moderators', [
            'add_moderators_form' => ['players' => PlayerFixture::PLAYER_PRIVATE],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(1, $browser->getContainer()->get(GetModerators::class)->all());
    }

    public function testModeratorCannotRevokeModerators(): void
    {
        $browser = $this->signedInModerator();

        $browser->request('POST', '/admin/moderators/' . PlayerFixture::PLAYER_REGULAR . '/revoke');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(1, $browser->getContainer()->get(GetModerators::class)->all());
    }

    public function testModeratorSeesTheRoleNotification(): void
    {
        $browser = $this->signedInModerator();

        $browser->request('GET', '/en/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'You are now a community moderator');
    }

    public function testModeratorSeesOnlyTheReviewQueuesInTheMenu(): void
    {
        $browser = $this->signedInModerator();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests');

        self::assertCount(1, $crawler->filter('a[href="/admin/puzzle-merge-requests"]'));
        self::assertCount(1, $crawler->filter('a[href="/admin/puzzle-approvals"]'));
        self::assertCount(0, $crawler->filter('a[href="/admin/moderators"]'));
        self::assertCount(0, $crawler->filter('a[href="/admin/vouchers"]'));
    }

    public function testAdminAppointsAndRevokesAModerator(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/moderators');
        self::assertResponseIsSuccessful();

        $browser->submitForm('Grant moderator role', [
            'add_moderators_form[players]' => PlayerFixture::PLAYER_REGULAR . ',' . PlayerFixture::PLAYER_PRIVATE,
        ]);
        self::assertResponseRedirects('/admin/moderators');

        $moderators = $browser->getContainer()->get(GetModerators::class)->all();
        self::assertCount(2, $moderators);

        $crawler = $browser->followRedirect();
        $browser->submit($crawler->filter('form[action$="/' . PlayerFixture::PLAYER_PRIVATE . '/revoke"]')->form());
        self::assertResponseRedirects('/admin/moderators');

        $moderators = $browser->getContainer()->get(GetModerators::class)->all();
        self::assertCount(1, $moderators);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $moderators[0]->playerId);
    }

    public function testEmptySelectionIsRejected(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/moderators');
        $browser->submitForm('Grant moderator role', ['add_moderators_form[players]' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testModeratorApprovesANewPuzzleAndIsCreditedAsApprover(): void
    {
        $browser = $this->signedInModerator();

        $crawler = $browser->request('GET', '/admin/puzzle-approvals/' . PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Approve puzzle')->form([
            'approve_puzzle_form[name]' => 'Puzzle 20 corrected',
            'approve_puzzle_form[brandChoice]' => 'approve',
        ]);
        $browser->submit($form);
        self::assertResponseRedirects('/admin/puzzle-approvals');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertTrue($puzzle->approved);
        self::assertSame('Puzzle 20 corrected', $puzzle->name);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $puzzle->approvedBy?->id->toString());
    }

    public function testMergeFromTheApprovalQueueOpensTheMergeReview(): void
    {
        $browser = $this->signedInModerator();

        $crawler = $browser->request('GET', '/admin/puzzle-approvals/' . PuzzleFixture::PUZZLE_UNAPPROVED);
        $form = $crawler->filter('form[action$="/merge"]')->last()->form([
            'target_puzzle' => 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_1000_01,
            'name_language' => 'cs',
        ]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/admin/puzzle-merge-requests/', $location);

        // The moderator said the new puzzle's name is Czech
        $mergeRequestId = substr((string) parse_url($location, PHP_URL_PATH), strlen('/admin/puzzle-merge-requests/'));
        $mergeRequest = $browser->getContainer()->get(PuzzleMergeRequestRepository::class)->get($mergeRequestId);
        self::assertSame([PuzzleFixture::PUZZLE_UNAPPROVED => 'cs'], $mergeRequest->reportedNameLanguages);

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        // The review posts back to its own address - the way back to the approval queue rides along
        self::assertSelectorExists('form[data-controller~="merge-review"][action*="return=/admin/puzzle-approvals"]');
    }

    private function signedInModerator(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }
}
