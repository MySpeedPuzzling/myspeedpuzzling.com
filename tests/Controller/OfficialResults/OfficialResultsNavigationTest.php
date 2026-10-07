<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/**
 * The organiser's tools link to each other (review2-business, naming + navigation): one name for the results desk,
 * the seating page reached and left like the desk and the live entry, the results overview reaching name tags, the
 * referees page and every round's stopwatch, the stopwatch control page reaching the live entry, and the public round
 * page's small way back for organisers.
 */
final class OfficialResultsNavigationTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testTheSeatingPageSitsUnderTheResultsOverviewWithTheRoundsOtherTools(): void
    {
        $crawler = $this->browser->request('GET', '/en/round-seating/' . OfficialResultsFixture::ROUND_GROUP_A);

        self::assertResponseIsSuccessful();
        $breadcrumb = $crawler->filter('.breadcrumb a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertContains('/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $breadcrumb);
        self::assertContains('/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $breadcrumb);

        $tools = $crawler->filter('[data-seating-tools]');
        self::assertCount(1, $tools->filter('[data-round-tool="live"]'));
        self::assertSame('Results desk', trim($tools->filter('[data-round-tool="desk"]')->text()));
        self::assertCount(0, $tools->filter('[data-round-tool="seating"]'));

        // The round switch goes to the other rounds' seating
        $options = $crawler->filter('[data-seating-round-switch] option');
        self::assertCount(5, $options);
        self::assertContains('/en/round-seating/' . OfficialResultsFixture::ROUND_FINAL, $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function testTheResultsOverviewReachesNameTagsAndEveryRoundsStopwatch(): void
    {
        $crawler = $this->browser->request('GET', '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
        self::assertSame('/en/name-tags/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-results-tool="name-tags"]')->attr('href'));
        self::assertCount(5, $crawler->filter('[data-overview-round] [data-round-tool="stopwatch"]'));
        self::assertSame(
            '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A,
            $crawler->filter('[data-overview-round="' . OfficialResultsFixture::ROUND_GROUP_A . '"] [data-round-tool="stopwatch"]')->attr('href'),
        );

        self::assertSame('/en/manage-event-referees/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-results-tool="referees"]')->attr('href'));
    }

    public function testTheStopwatchControlPageReachesTheLiveEntry(): void
    {
        $crawler = $this->browser->request('GET', '/en/manage-round-stopwatch/' . OfficialResultsFixture::ROUND_GROUP_A);

        self::assertResponseIsSuccessful();
        $tools = $crawler->filter('[data-stopwatch-tools]');
        self::assertSame('/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A, explode('?', (string) $tools->filter('[data-round-tool="live"]')->attr('href'))[0]);
        self::assertCount(1, $tools->filter('[data-round-tool="desk"]'));
        self::assertCount(1, $tools->filter('[data-round-tool="seating"]'));
    }

    public function testThePublicRoundPageLinksTheResultsDeskForOrganisersOnly(): void
    {
        self::assertSame('', $this->renderOrganiserLinks(null));
        self::assertSame('', $this->renderOrganiserLinks(PlayerFixture::PLAYER_REGULAR));

        $html = new Crawler($this->renderOrganiserLinks(PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame('/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $html->filter('[data-round-tool="desk"]')->attr('href'));
        self::assertSame('Results desk', $html->filter('[data-round-tool="desk"]')->text());
    }

    private function renderOrganiserLinks(null|string $playerId): string
    {
        self::ensureKernelShutdown();
        $container = self::bootKernel()->getContainer();
        $container->get('request_stack')->push(Request::create('/en/'));
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        if ($playerId !== null) {
            $player = self::getContainer()->get(PlayerRepository::class)->get($playerId);
            assert($player->userId !== null);
            $account = self::getContainer()->get(UserAccountRepository::class)->findByUserId($player->userId)
                ?? new UserAccount(Uuid::uuid7(), $player->userId, $player->code . '@test.local', new DateTimeImmutable());
            self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));
        }

        return trim(self::getContainer()->get(Environment::class)->render('official_results/_organiser_round_links.html.twig', [
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'round_id' => OfficialResultsFixture::ROUND_GROUP_A,
        ]));
    }
}
