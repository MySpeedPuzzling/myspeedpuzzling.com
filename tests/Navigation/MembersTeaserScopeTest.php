<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\MembersPlacement;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;

/**
 * Members-only teasers in this release (docs/features/navigation README §6, D13, TEASE-01): the card's locked tier chip
 * and the profile header's, both passive and carrying their placement (data-members-placement, MembersPlacement) for
 * the members modal; never a teaser in the navigation's partials, in notifications or in e-mails. Dismissable teasers
 * are a rule only (README §6 rule 3, docs/TODO.md).
 */
final class MembersTeaserScopeTest extends WebTestCase
{
    /** Where no members teaser may ever sit (README §6 rule 1) - a lock there opens #membersExclusiveModal */
    private const array NO_TEASER_TEMPLATES = [
        'navigation',
        'inbox',
        'emails',
        'notifications.html.twig',
    ];

    public function testNoTeaserInTheNavigationNotificationsOrEmails(): void
    {
        $templates = dirname(__DIR__, 2) . '/templates';
        $checked = 0;

        foreach (self::NO_TEASER_TEMPLATES as $path) {
            $files = is_dir($templates . '/' . $path)
                ? array_map(static fn ($file): string => $file->getPathname(), iterator_to_array(new Finder()->files()->in($templates . '/' . $path)->name('*.twig'), false))
                : [$templates . '/' . $path];

            foreach ($files as $file) {
                $content = (string) file_get_contents($file);
                $checked++;

                self::assertStringNotContainsString('membersExclusiveModal', $content, 'A members teaser in ' . $file);
                self::assertStringNotContainsString('data-members-placement', $content, 'A members teaser in ' . $file);
            }
        }

        self::assertGreaterThan(40, $checked, 'The guard reads the templates it means');
    }

    /**
     * Every placement in a template is one the server knows, and every one the server knows is used - once
     */
    public function testEveryPlacementIsKnownAndUsedOnce(): void
    {
        $used = [];

        foreach (new Finder()->files()->in(dirname(__DIR__, 2) . '/templates')->name('*.twig') as $file) {
            preg_match_all('~data-members-placement="([^"]*)"~', $file->getContents(), $matches);

            foreach ($matches[1] as $placement) {
                self::assertNotNull(MembersPlacement::tryFrom($placement), $placement . ' in ' . $file->getRelativePathname() . ' is no MembersPlacement');
                $used[] = $placement;
            }
        }

        $known = array_map(static fn (MembersPlacement $placement): string => $placement->value, MembersPlacement::cases());
        sort($used);
        sort($known);

        self::assertSame($known, $used);
    }

    /**
     * The header's locked tier chip carries profile.tier for whoever gets the lock (a guest, a player without a
     * membership); the chrome around it carries no teaser
     */
    public function testTheProfileHeadersLockCarriesItsPlacement(): void
    {
        $browser = self::createClient();

        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewerId) {
            if ($viewerId !== null) {
                TestingLogin::asPlayer($browser, $viewerId);
            }

            $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
            self::assertResponseIsSuccessful();

            self::assertCount(1, $crawler->filter('.player-chip[data-bs-target="#membersExclusiveModal"][data-members-placement="profile.tier"]'), (string) $viewerId);

            // The bars, the Menu and the footer: no teaser - but the top bar's search, once opened, offers the scan
            // locked (implementation plan decision 21: never in the closed bar, CSS shows it only in the open search)
            foreach (['.site-header', '#bottom-nav', '#site-menu', '#menu-layer', '.site-footer'] as $chrome) {
                $teasers = $crawler->filter($chrome . ' [data-bs-target="#membersExclusiveModal"]')
                    ->reduce(static fn (Crawler $teaser): bool => $teaser->closest('.site-search') === null);

                self::assertCount(0, $teasers, $chrome);
            }
        }
    }
}
