<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The members modal passes the teaser's placement on (docs/features/navigation README §6 rule 5, TEASE-01): the real
 * assets/controllers/members_modal_controller.js under Stimulus in jsdom (tests/members-modal-harness.mjs) on the
 * card's own page and a profile, rendered here - each open puts the clicked lock's data-members-placement into the
 * trial form's `placement` field and onto the membership links (?placement=), and dispatches one msp:members-modal
 * {placement} for the P0-03 beacon; a lock without a placement (or with junk) leaves both empty and counts as 'none'.
 */
final class MembersModalScriptTest extends WebTestCase
{
    use FreeTrialConditions;

    public function testTheModalReceivesThePlacementOfTheLockThatOpenedIt(): void
    {
        $browser = self::createClient();
        // An established player without a membership: the modal offers the trial (form + the membership link)
        $this->registeredDaysAgo($browser->getContainer()->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 8);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $facts = $this->runInNode([
            'card' => [
                'html' => $this->page($browser, '/en/puzzler-card/' . PlayerFixture::PLAYER_WITH_STRIPE),
                'opens' => ['[data-members-placement="card.tier"]', '#harness-plain-lock', '[data-members-placement="card.tier"]', '#harness-junk-lock', null],
            ],
            'profile' => [
                'html' => $this->page($browser, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE),
                'opens' => ['[data-members-placement="profile.tier"]'],
            ],
        ]);

        $card = $facts['card'];
        self::assertSame([], $card['errors']);
        self::assertTrue($card['controller']);

        $open = static fn (string $field, string $link, string $event): array => ['field' => [$field], 'links' => [$link], 'events' => [['placement' => $event]]];
        $seen = array_map(static fn (array $o): array => ['field' => $o['field'], 'links' => $o['links'], 'events' => $o['events']], $card['opens']);

        self::assertTrue($card['opens'][0]['lockFound'], 'The card has its locked tier chip');
        self::assertSame([
            $open('card.tier', '/en/membership?placement=card.tier', 'card.tier'),
            // Another lock afterwards: nothing of the previous one is left
            $open('', '/en/membership', 'none'),
            $open('card.tier', '/en/membership?placement=card.tier', 'card.tier'),
            $open('', '/en/membership', 'none'),
            // Opened by script (no lock)
            $open('', '/en/membership', 'none'),
        ], $seen);

        $profile = $facts['profile'];
        self::assertSame([], $profile['errors']);
        self::assertTrue($profile['opens'][0]['lockFound'], 'The profile header has its locked tier chip');
        self::assertSame(['profile.tier'], $profile['opens'][0]['field']);
        self::assertSame(['/en/membership?placement=profile.tier'], $profile['opens'][0]['links']);
        self::assertSame([['placement' => 'profile.tier']], $profile['opens'][0]['events']);
    }

    /**
     * A guest's modal has neither the form nor the membership link (it asks to sign in): the open is counted all the same
     */
    public function testAGuestsOpenIsCountedToo(): void
    {
        $browser = self::createClient();

        $facts = $this->runInNode([
            'guest card' => [
                'html' => $this->page($browser, '/en/puzzler-card/' . PlayerFixture::PLAYER_WITH_STRIPE),
                'opens' => ['[data-members-placement="card.tier"]'],
            ],
        ]);

        $card = $facts['guest card'];
        self::assertSame([], $card['errors']);
        self::assertTrue($card['opens'][0]['lockFound']);
        self::assertSame([], $card['opens'][0]['field']);
        self::assertSame([], $card['opens'][0]['links']);
        self::assertSame([['placement' => 'card.tier']], $card['opens'][0]['events']);
    }

    private function page(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $browser->getResponse()->getContent();
    }

    /**
     * @param array<string, array{html: string, opens: list<null|string>}> $pages
     * @return array<string, array{errors: list<string>, controller: bool, opens: list<array{lock: null|string, lockFound: bool, field: list<string>, links: list<string>, events: list<array{placement: string}>}>}>
     */
    private function runInNode(array $pages): array
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/members-modal-harness.mjs']);
        $process->setInput(json_encode(['pages' => $pages], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, array{errors: list<string>, controller: bool, opens: list<array{lock: null|string, lockFound: bool, field: list<string>, links: list<string>, events: list<array{placement: string}>}>}> $facts */
        $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $facts;
    }
}
