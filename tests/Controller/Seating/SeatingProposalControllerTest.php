<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The auto-assign proposal endpoint (docs/features/competitions-management/seating.md) - OfficialResultsApi's rules.
 */
final class SeatingProposalControllerTest extends WebTestCase
{
    private const string URL = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/seating-proposal';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInGets401(): void
    {
        $this->browser->request('GET', self::URL);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json());
    }

    public function testOnlyTheEventsOrganisersGetAProposal(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());
    }

    public function testTheProposalNumbersEveryEntrantAndWritesNothing(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::URL . '?source=name&first=10');

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $proposal = $this->json();
        self::assertSame('name', $proposal['source']);
        self::assertSame(10, $proposal['firstTable']);
        self::assertSame(6, $proposal['total']);
        self::assertIsArray($proposal['rows']);
        self::assertSame([10, 11, 12, 13, 14, 15], array_column($proposal['rows'], 'tableNumber'));
        $first = $proposal['rows'][0];
        self::assertIsArray($first);
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, $first['entry']);
        self::assertSame(1, $first['currentTableNumber']);
        self::assertSame(
            ['earlier_rounds', 'msp_times', 'random', 'name'],
            array_column(is_array($proposal['sources']) ? $proposal['sources'] : [], 'source'),
        );

        // Nothing written: Anna still sits at table 1
        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A);
        $state = $this->json();
        self::assertIsArray($state['entries']);
        $anna = $state['entries'][0];
        self::assertIsArray($anna);
        self::assertSame(1, $anna['tableNumber']);
    }

    public function testARandomDrawComesWithItsNumberToRepeatIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::URL . '?source=random');
        $draw = $this->json();
        self::assertIsInt($draw['randomSeed']);

        $this->browser->request('GET', self::URL . '?source=random&seed=' . $draw['randomSeed']);
        $again = $this->json();
        self::assertIsArray($draw['rows']);
        self::assertIsArray($again['rows']);
        self::assertSame(array_column($draw['rows'], 'entry'), array_column($again['rows'], 'entry'));
    }

    public function testUnreadableOptionsAreRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['?source=fastest', '?order=upside_down', '?seed=0', '?seed=abc', '?first=0', '?first=10000', '?first=9998'] as $query) {
            $this->browser->request('GET', self::URL . $query);

            self::assertResponseStatusCodeSame(400, $query);
            self::assertSame(['error' => 'invalid_seating_options'], $this->json());
        }
    }

    public function testARoundOfAnEventTheOrganiserDoesNotRunIsForbidden(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/official-results/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/seating-proposal');
        self::assertResponseStatusCodeSame(403);

        $this->browser->request('GET', '/en/official-results/rounds/018d0020-0000-0000-0000-000000009999/seating-proposal');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
