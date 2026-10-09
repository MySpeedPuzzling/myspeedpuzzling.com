<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\EventUrlRedirect;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\EventSubscriber\EventUrlRedirectSubscriber;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\EventUrlPath;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md, D6: an old event URL moved by a restructuring tool answers 301 to the target's
 * current URL - only when the page would 404; the live page always wins, and so does a draft (P5).
 */
final class EventUrlRedirectSubscriberTest extends WebTestCase
{
    public function testEveryKindOfOldPathLeadsToItsTargetsCurrentAddress(): void
    {
        $browser = self::createClient();

        $this->remember(EventUrlPath::event('gone-event'), Competition::class, CompetitionFixture::COMPETITION_WJPC_2024);
        $this->remember(EventUrlPath::series('gone-series'), CompetitionSeries::class, CompetitionSeriesFixture::SERIES_EJJ);
        $this->remember(EventUrlPath::edition('gone-series', 'gone-edition'), Competition::class, CompetitionSeriesFixture::EDITION_EJJ_69);
        $this->remember(EventUrlPath::eventRound('gone-event', 'gone-round'), CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $this->remember(EventUrlPath::editionRound('gone-series', 'gone-edition', 'gone-round'), CompetitionRound::class, CompetitionSeriesFixture::ROUND_EJJ_68);
        $this->remember(EventUrlPath::series('gone-association'), Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);

        $expected = [
            '/en/events/gone-event' => '/en/events/wjpc-2024',
            '/en/series/gone-series' => '/en/series/euro-jigsaw-jam-series',
            '/en/series/gone-series/gone-edition' => '/en/series/euro-jigsaw-jam-series/ejj-69-may-2026',
            '/en/events/gone-event/results/gone-round' => '/en/events/wjpc-2024/results/final-round',
            '/en/series/gone-series/gone-edition/results/gone-round' => '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round',
            '/en/series/gone-association' => '/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG,
            // The request's language and query string are kept
            '/serie/gone-series?utm_source=newsletter' => '/serie/euro-jigsaw-jam-series?utm_source=newsletter',
        ];

        foreach ($expected as $oldPath => $newPath) {
            $browser->request('GET', $oldPath);
            self::assertResponseRedirects($newPath, 301, $oldPath);
        }

        // A path without a row still answers 404
        $browser->request('GET', '/en/series/never-existed');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAChainedMoveLeadsToWhereThePageIsNow(): void
    {
        $browser = self::createClient();

        $this->remember(EventUrlPath::edition('gone-series', 'gone-edition'), Competition::class, OrganizationFixture::EDITION_LANTERN_1);

        // The edition moves again afterwards - the row follows it
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MoveEditionToSeries(
            competitionId: OrganizationFixture::EDITION_LANTERN_1,
            targetSeriesId: OrganizationFixture::SERIES_RIVERBEND_VIRTUAL,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));

        $browser->request('GET', '/en/series/gone-series/gone-edition');
        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/lantern-night-one', 301);

        // The address it had right before the second move too
        $browser->request('GET', '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one');
        self::assertResponseRedirects('/en/series/' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG . '/lantern-night-one', 301);
    }

    public function testAnOldPathNeverLeadsToADraft(): void
    {
        $browser = self::createClient();

        // An edition moved into a draft series is hidden with the series - its old path stays a 404
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MoveEditionToSeries(
            competitionId: OrganizationFixture::EDITION_LANTERN_1,
            targetSeriesId: OrganizationFixture::SERIES_QUIET_PINES_DRAFT,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));

        $oldPath = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one';

        $browser->request('GET', $oldPath);
        self::assertResponseStatusCodeSame(404);

        // Its team too: no redirect to the draft, they reach it from "You organize"
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $oldPath);
        self::assertResponseStatusCodeSame(404);

        // Every kind of draft target: an organization, a series, an event, a round of a draft event
        $this->remember(EventUrlPath::series('gone-association'), Organization::class, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);
        $this->remember(EventUrlPath::series('gone-series'), CompetitionSeries::class, OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        $this->remember(EventUrlPath::event('gone-event'), Competition::class, OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        $this->remember(EventUrlPath::eventRound('gone-event', 'gone-round'), CompetitionRound::class, OrganizationFixture::ROUND_DRAFT_NIGHT);

        foreach (['/en/series/gone-association', '/en/series/gone-series', '/en/events/gone-event', '/en/events/gone-event/results/gone-round'] as $path) {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testTheLivePageWins(): void
    {
        $browser = self::createClient();

        $this->remember(EventUrlPath::series('euro-jigsaw-jam-series'), Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');
        self::assertResponseIsSuccessful();
    }

    public function testADraftsOwnNotFoundIsNeverRedirected(): void
    {
        self::bootKernel();
        $this->remember(EventUrlPath::edition('gone-series', 'gone-edition'), Competition::class, CompetitionSeriesFixture::EDITION_EJJ_69);

        $subscriber = self::getContainer()->get(EventUrlRedirectSubscriber::class);

        $draft = $this->exceptionEvent(new DraftNotVisible());
        $subscriber->onKernelException($draft);
        self::assertNull($draft->getResponse());

        $gone = $this->exceptionEvent(new NotFoundHttpException());
        $subscriber->onKernelException($gone);
        self::assertSame(301, $gone->getResponse()?->getStatusCode());
    }

    private function exceptionEvent(\Throwable $exception): ExceptionEvent
    {
        $request = Request::create('/en/series/gone-series/gone-edition');
        $request->attributes->set('_route', 'edition_detail.en');
        $request->attributes->set('_canonical_route', 'edition_detail');
        $request->attributes->set('_route_params', ['seriesSlug' => 'gone-series', 'editionSlug' => 'gone-edition', '_locale' => 'en']);
        $request->setLocale('en');

        return new ExceptionEvent(self::$kernel ?? self::fail('No kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    /**
     * @param class-string<Organization|CompetitionSeries|Competition|CompetitionRound> $class
     */
    private function remember(EventUrlPath $path, string $class, string $id): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $target = $entityManager->find($class, $id);
        self::assertNotNull($target);

        $entityManager->persist(EventUrlRedirect::to(Uuid::uuid7(), $path, $target, self::getContainer()->get(ClockInterface::class)->now()));
        $entityManager->flush();
    }
}
