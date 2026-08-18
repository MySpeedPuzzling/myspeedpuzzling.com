<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\AccountDeletionRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The e-mailed link (docs/features/account-deletion.md). The load-bearing
 * properties: opening the link deletes NOTHING (mail clients prefetch links),
 * only the explicit POST with the checkbox does; every dead link says so and
 * deletes nothing; and a browser signed in as the account is signed out when
 * the account goes.
 */
final class ConfirmAccountDeletionControllerTest extends WebTestCase
{
    public function testOpeningTheLinkShowsTheLastChancePageAndDeletesNothing(): void
    {
        $browser = self::createClient();
        [$userAccount, $player] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $crawler = $browser->request('GET', '/delete-account/' . $token, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('main')->text();
        self::assertStringContainsString('Sorry to see you go', $main);
        self::assertStringContainsString($userAccount->email, $main, 'It names the account about to go');
        self::assertStringContainsString('Leaving Soon', $main, '... and the player');
        self::assertCount(1, $crawler->filter('a[href$="/export-puzzler-data/' . $player->id->toString() . '"]'), 'Export CTA on the last-chance page');
        self::assertCount(1, $crawler->filter('form input[name="confirm"][type="checkbox"]'));
        self::assertCount(1, $crawler->filter('form[action*="/delete-account/"] button[type="submit"]'));

        self::assertNotNull($this->reloadAccount($browser, $userAccount));
        self::assertNotNull($this->reloadPlayer($browser, $player));

        // #164 + token hygiene: no session for an anonymous visitor, never shared-cached,
        // and the token must not leak through the Referer to anything off-site
        // (same-origin, not no-referrer: the latter would null the Origin header of
        // the page's own POST - see the controller docblock)
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('same-origin', $browser->getResponse()->headers->get('Referrer-Policy'));
    }

    public function testTheLastChancePageSpellsOutWhatGoesWithTheAccount(): void
    {
        $browser = self::createClient();
        [$userAccount, $player] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $connection = $browser->getContainer()->get(EntityManagerInterface::class)->getConnection();
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');
        // 3 results (2 puzzles, 3 x 500 pieces, 1h + 30m + 15m), 2 puzzles catalogued
        foreach ([[PuzzleFixture::PUZZLE_500_01, 3600], [PuzzleFixture::PUZZLE_500_02, 1800], [PuzzleFixture::PUZZLE_500_02, 900]] as [$puzzleId, $seconds]) {
            $connection->insert('puzzle_solving_time', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => $player->id->toString(),
                'puzzle_id' => $puzzleId,
                'seconds_to_solve' => $seconds,
                'tracked_at' => $now,
                'finished_at' => $now,
                'verified' => 'true',
                'first_attempt' => 'false',
                'unboxed' => 'false',
                'puzzling_type' => 'solo',
            ]);
        }
        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02] as $puzzleId) {
            $connection->insert('collection_item', [
                'id' => Uuid::uuid7()->toString(),
                'player_id' => $player->id->toString(),
                'puzzle_id' => $puzzleId,
                'collection_id' => null,
                'added_at' => $now,
            ]);
        }

        $crawler = $browser->request('GET', '/delete-account/' . $token, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        $summary = $crawler->filter('#deletion-summary');
        self::assertCount(1, $summary);
        $tiles = $summary->filter('.card')->each(static fn ($tile): string => preg_replace('/\s+/', ' ', $tile->text()) ?? '');
        self::assertCount(4, $tiles);
        self::assertStringContainsString('3 solving times', $tiles[0]);
        self::assertStringContainsString('pieces solved', $tiles[1]);
        self::assertStringContainsString('1 500', str_replace("\u{a0}", ' ', $tiles[1]), '3 x 500 pieces, cs-style thousands grouping like the rest of the site');
        self::assertStringContainsString('01h 45m spent puzzling', $tiles[2]);
        self::assertStringContainsString('2 puzzles in collections', $tiles[3]);
    }

    public function testAnAccountWithNothingToLoseGetsNoSummaryBlock(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $crawler = $browser->request('GET', '/delete-account/' . $token, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#deletion-summary'));
    }

    public function testConfirmingDeletesTheAccountAndLandsOnTheGoodbyePage(): void
    {
        $browser = self::createClient();
        [$userAccount, $player] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $this->confirm($browser, $token, ticked: true);

        self::assertResponseRedirects('/account-deleted');
        self::assertNull($this->reloadAccount($browser, $userAccount));
        self::assertNull($this->reloadPlayer($browser, $player));

        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Your account has been deleted', $crawler->filter('main')->text());
    }

    public function testTheCheckboxIsMandatory(): void
    {
        $browser = self::createClient();
        [$userAccount, $player] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $crawler = $this->confirm($browser, $token, ticked: false);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('tick the box', $crawler->filter('main')->text());
        self::assertNotNull($this->reloadAccount($browser, $userAccount));
        self::assertNotNull($this->reloadPlayer($browser, $player));
    }

    public function testAMissingCsrfTokenIsRefused(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $browser->request('POST', '/delete-account/' . $token, ['confirm' => '1']);

        // Access denied for an anonymous visitor means the entry point (→ /login),
        // the same shape as every other CSRF failure on the site; the point is that
        // nothing happened
        self::assertResponseStatusCodeSame(302);
        self::assertNotNull($this->reloadAccount($browser, $userAccount));
    }

    public function testTheBrowserSignedInAsTheAccountIsSignedOut(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);
        $browser->loginUser($userAccount, 'main');

        $this->confirm($browser, $token, ticked: true);

        self::assertResponseRedirects('/account-deleted');
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        // ... and stays out: a protected page bounces to login instead of 500-ing on
        // a session that points at an account that no longer exists
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseStatusCodeSame(302);
    }

    public function testABrowserSignedInAsSomebodyElseStaysSignedIn(): void
    {
        $browser = self::createClient();
        [$leaving] = $this->seedAccountWithPlayer($browser);
        [$bystander] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $leaving);
        $browser->loginUser($bystander, 'main');

        $this->confirm($browser, $token, ticked: true);

        self::assertResponseRedirects('/account-deleted');
        self::assertNull($this->reloadAccount($browser, $leaving));
        self::assertNotNull($this->reloadAccount($browser, $bystander));

        $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();
    }

    public function testAnExpiredLinkSaysSoAndDeletesNothing(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount, expiresAt: new DateTimeImmutable('-1 minute'));

        $crawler = $browser->request('GET', '/delete-account/' . $token, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('expired', $crawler->filter('main')->text());
        self::assertStringContainsString('Nothing has been deleted', $crawler->filter('main')->text());
        self::assertCount(0, $crawler->filter('form input[name="confirm"]'), 'No delete button on a dead link');

        // Even a POST against it must be a no-op
        $this->confirm($browser, $token, ticked: true);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->reloadAccount($browser, $userAccount));
    }

    public function testAForgedOrTruncatedLinkDoesNotWork(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        foreach ([substr($token, 0, 40), $token[0] === 'a' ? 'b' . substr($token, 1) : 'a' . substr($token, 1), str_repeat('f', 64)] as $bad) {
            $crawler = $browser->request('GET', '/delete-account/' . $bad, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('does not work', $crawler->filter('main')->text());
        }

        self::assertNotNull($this->reloadAccount($browser, $userAccount));
    }

    public function testAUsedLinkIsDeadAfterwards(): void
    {
        $browser = self::createClient();
        [$userAccount] = $this->seedAccountWithPlayer($browser);
        $token = $this->issueToken($browser, $userAccount);

        $this->confirm($browser, $token, ticked: true);
        self::assertResponseRedirects('/account-deleted');

        $crawler = $browser->request('GET', '/delete-account/' . $token, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('does not work', $crawler->filter('main')->text());
    }

    public function testTheGoodbyePageIsOpenToAnyone(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/account-deleted', server: ['HTTP_ACCEPT_LANGUAGE' => 'cs']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Váš účet byl smazán', $crawler->filter('main')->text());
        self::assertSame([], $browser->getResponse()->headers->getCookies());
    }

    private function confirm(KernelBrowser $browser, string $token, bool $ticked): \Symfony\Component\DomCrawler\Crawler
    {
        // Stateless (same-origin) CSRF, like every other anonymous form of the site
        $fields = ['_token' => 'csrf-token'];

        if ($ticked) {
            $fields['confirm'] = '1';
        }

        return $browser->request('POST', '/delete-account/' . $token, $fields, [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCEPT_LANGUAGE' => 'en',
        ]);
    }

    private function issueToken(KernelBrowser $browser, UserAccount $userAccount, null|DateTimeImmutable $expiresAt = null): string
    {
        $expiresAt ??= new DateTimeImmutable('+30 minutes');
        $token = AccountDeletionToken::generate();

        $browser->getContainer()->get(AccountDeletionRequestRepository::class)->save(new AccountDeletionRequest(
            Uuid::uuid7(),
            $userAccount,
            $token->selector,
            $token->hashedVerifier(),
            $expiresAt->modify('-60 minutes'),
            $expiresAt,
        ));
        $browser->getContainer()->get(EntityManagerInterface::class)->flush();

        return $token->toString();
    }

    private function reloadAccount(KernelBrowser $browser, UserAccount $userAccount): null|UserAccount
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(UserAccount::class, $userAccount->id);
    }

    private function reloadPlayer(KernelBrowser $browser, Player $player): null|Player
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Player::class, $player->id);
    }

    /**
     * @return array{UserAccount, Player}
     */
    private function seedAccountWithPlayer(KernelBrowser $browser): array
    {
        $userId = 'msp|' . bin2hex(random_bytes(4));
        $email = sprintf('confirm.page+%s@example.com', bin2hex(random_bytes(4)));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash('a-properly-long-passphrase', PASSWORD_ARGON2ID));
        $player = new Player(Uuid::uuid7(), 'CP' . bin2hex(random_bytes(2)), $userId, $email, 'Leaving Soon', new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist($player);
        $entityManager->flush();

        return [$userAccount, $player];
    }
}
