<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The printed competition tags of the toolkit (R-review-and-plan.md §1.3, B-qr-competitor-page.md §3.3): the frozen
 * locale-less paths `/live/{cid}/g|t|e/{id}` (plus an optional language prefix), where each leads every kind of viewer
 * (CompetitionTagLanding), and that a broken id is a 404, never a 500. The name tag's `/{_locale}/live/{cid}/p/{id}` is
 * pinned by LiveResultsScanRedirectMatrixTest / LiveResultsLinksTest.
 */
final class LiveResultsTagRoutesTest extends WebTestCase
{
    use GoldenFile;

    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string UNKNOWN = '018d0020-0000-0000-0000-00000000ffff';

    public function testThePrintedPathsAreFrozen(): void
    {
        self::createClient();
        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        $cid = F::COMPETITION_RESULTS_CUP;

        self::assertSame('/live/' . $cid . '/g/' . self::UNKNOWN, $router->generate('live_results_lineup_tag', ['competitionId' => $cid, 'lineupId' => self::UNKNOWN]));
        self::assertSame('/live/' . $cid . '/t/12', $router->generate('live_results_table_tag', ['competitionId' => $cid, 'tableNumber' => 12]));
        self::assertSame('/live/' . $cid . '/e/' . F::ENTRY_A_BEN, $router->generate('live_results_entry_tag', ['competitionId' => $cid, 'entryId' => F::ENTRY_A_BEN]));
        self::assertSame('/cs/live/' . $cid . '/g/' . self::UNKNOWN, $router->generate('live_results_lineup_tag_localized', ['competitionId' => $cid, 'lineupId' => self::UNKNOWN, '_locale' => 'cs']));
        self::assertSame('/de/live/' . $cid . '/t/9999', $router->generate('live_results_table_tag_localized', ['competitionId' => $cid, 'tableNumber' => 9999, '_locale' => 'de']));
        self::assertSame('/ja/live/' . $cid . '/e/' . F::TEAM_SHARKS, $router->generate('live_results_entry_tag_localized', ['competitionId' => $cid, 'entryId' => F::TEAM_SHARKS, '_locale' => 'ja']));
        // The name tag's - never changes
        self::assertSame('/en/live/' . $cid . '/p/' . F::PARTICIPANT_ANNA, $router->generate('live_results_scan', ['competitionId' => $cid, 'participantId' => F::PARTICIPANT_ANNA, '_locale' => 'en']));
    }

    public function testCompetitorPagesOffEveryViewerAndEveryTagKind(): void
    {
        self::assertMatchesGoldenFile(__DIR__ . '/golden/live-results-tag-matrix-off.txt', $this->matrix(competitorPages: false), 'The tag scan matrix, competitor pages off');
    }

    /**
     * With competitor pages on only the public branch changes: organisers and referees land where they did; signed out
     * and puzzlers open the tag's competitor page (`/en/competitor/{cid}/{kind}/{id}`, CompetitorPageController) for a
     * known tag (every table is one), the event page for an unknown one, the events list for a draft's.
     */
    public function testCompetitorPagesOnEveryViewerAndEveryTagKind(): void
    {
        self::assertMatchesGoldenFile(__DIR__ . '/golden/live-results-tag-matrix-on.txt', $this->matrix(competitorPages: true), 'The tag scan matrix, competitor pages on');
    }

    public function testALanguagePrefixIsKeptAndALinkWithoutOnePicksTheViewersLanguage(): void
    {
        $browser = self::createClient();
        $cid = F::COMPETITION_RESULTS_CUP;
        $eventPage = self::getContainer()->get(CompetitionDetailUrl::class);

        // A guest's browser speaks German first, then English
        $browser->request('GET', '/live/' . $cid . '/t/1', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8']);
        self::assertResponseRedirects($eventPage->of($cid, 'de'));

        // A language the site does not speak: English
        $browser->request('GET', '/live/' . $cid . '/e/' . F::ENTRY_A_BEN, server: ['HTTP_ACCEPT_LANGUAGE' => 'pl']);
        self::assertResponseRedirects($eventPage->of($cid, 'en'));

        // The prefix wins over the browser
        $browser->request('GET', '/fr/live/' . $cid . '/t/1', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseRedirects($eventPage->of($cid, 'fr'));

        // An organiser gets the live entry in the picked language - Czech paths of its own
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/live/' . $cid . '/t/5', server: ['HTTP_ACCEPT_LANGUAGE' => 'cs']);
        self::assertResponseRedirects('/zadavani-vysledku/' . F::ROUND_GROUP_A . '?table=5');

        $browser->request('GET', '/es/live/' . $cid . '/e/' . F::TEAM_CORNERS);
        self::assertResponseRedirects('/es/live-results/' . F::ROUND_PAIRS . '?entry=team:' . F::TEAM_CORNERS);
    }

    public function testTheLiveEntryOpensWhatTheTagMeans(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // The landing parameters the live entry reads (LiveResultsController: ?table=, ?entry=)
        $browser->request('GET', '/live/' . F::COMPETITION_RESULTS_CUP . '/e/' . F::ENTRY_A_BEN);
        self::assertResponseRedirects('/en/live-results/' . F::ROUND_GROUP_A . '?entry=participant_round:' . F::ENTRY_A_BEN);

        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('participant_round:' . F::ENTRY_A_BEN, $crawler->filter('[data-controller~="live-results"]')->attr('data-live-results-entry-value'));
    }

    public function testABrokenTagIsNotFound(): void
    {
        $browser = self::createClient();
        $cid = F::COMPETITION_RESULTS_CUP;

        foreach (
            [
            '/live/' . $cid . '/t/0',
            '/live/' . $cid . '/t/012',
            '/live/' . $cid . '/t/10000',
            '/live/' . $cid . '/t/-1',
            '/live/' . $cid . '/t/abc',
            '/live/' . $cid . '/t/',
            '/live/' . $cid . '/e/not-an-id',
            '/live/' . $cid . '/e/' . substr(F::ENTRY_A_BEN, 0, -1),
            '/live/' . $cid . '/g/12',
            '/live/' . $cid . '/x/1',
            '/live/not-an-event/t/1',
            '/live/' . $cid . '/p/' . F::PARTICIPANT_ANNA,
            '/pl/live/' . $cid . '/t/1',
            '/xx/live/' . $cid . '/e/' . F::ENTRY_A_BEN,
            ] as $path
        ) {
            $browser->request('GET', $path);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $path);
        }
    }

    private function matrix(bool $competitorPages): string
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $connection = self::getContainer()->get(Connection::class);

        $connection->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => F::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => PlayerFixture::PLAYER_WITH_STRIPE,
            'added_at' => '2026-10-01 10:00:00',
        ]);

        if ($competitorPages) {
            $connection->executeStatement('UPDATE competition SET competitor_pages = true WHERE id IN (:ids)', [
                'ids' => [F::COMPETITION_RESULTS_CUP, CompetitionFixture::COMPETITION_UNAPPROVED, OrganizationFixture::EDITION_LANTERN_DRAFT],
            ], ['ids' => ArrayParameterType::STRING]);
        }

        $viewers = [
            'signed out' => null,
            'puzzler' => PlayerFixture::PLAYER_REGULAR,
            'organiser' => PlayerFixture::PLAYER_WITH_STRIPE,
            'referee' => self::REFEREE,
            'admin' => PlayerFixture::PLAYER_ADMIN,
        ];

        $cup = F::COMPETITION_RESULTS_CUP;
        $sharks = CompetitionLineupId::of($cup, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);
        $tags = [
            'table used by several rounds' => '/live/' . $cup . '/t/1',
            'table of one round' => '/live/' . $cup . '/t/5',
            'table nobody sits at' => '/live/' . $cup . '/t/99',
            'solo round entry' => '/live/' . $cup . '/e/' . F::ENTRY_A_BEN,
            'pair round entry' => '/live/' . $cup . '/e/' . F::TEAM_CORNERS,
            'unknown round entry' => '/live/' . $cup . '/e/' . self::UNKNOWN,
            'line-up' => '/live/' . $cup . '/g/' . $sharks,
            'unknown line-up' => '/live/' . $cup . '/g/' . self::UNKNOWN,
            'person (name tag)' => '/en/live/' . $cup . '/p/' . F::PARTICIPANT_IVAN,
            'event without rounds' => '/live/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/t/1',
            'draft event' => '/live/' . OrganizationFixture::EDITION_LANTERN_DRAFT . '/e/' . self::UNKNOWN,
            'unknown event' => '/live/' . self::UNKNOWN . '/g/' . $sharks,
        ];

        $lines = [];
        foreach ($viewers as $viewer => $playerId) {
            foreach ($tags as $tag => $path) {
                $browser->restart();
                if ($playerId !== null) {
                    TestingLogin::asPlayer($browser, $playerId);
                }

                $browser->request('GET', $path);
                $response = $browser->getResponse();
                $session = $browser->getRequest()->getSession();
                assert($session instanceof FlashBagAwareSessionInterface);
                $flashes = $session->getFlashBag()->peekAll();

                $lines[] = sprintf(
                    '%s | %s | %d %s | %s | %s',
                    $viewer,
                    $tag,
                    $response->getStatusCode(),
                    (string) $response->headers->get('Location'),
                    $flashes === [] ? 'no flash' : 'flash ' . implode(',', array_keys($flashes)),
                    (string) $response->headers->get('X-Robots-Tag'),
                );
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $viewer . ' ' . $tag);
                self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'), $viewer . ' ' . $tag);
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
