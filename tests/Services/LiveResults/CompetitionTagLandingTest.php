<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveResults;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\CompetitionTagLanding;
use SpeedPuzzling\Web\Services\CompetitionTagResolver;
use SpeedPuzzling\Web\Services\CompetitorPageUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The public branch of a tag scan with competitor pages on (B-qr-competitor-page.md §3.3 branch 2): the tag's own
 * competitor page for a tag of somebody in the event, the event page for anything else. A stand-in `competitor_page`
 * route of the agreed shape (CompetitorPageUrl) pins the contract; LiveResultsTagRoutesTest's "on" matrix covers the
 * real route end to end.
 */
final class CompetitionTagLandingTest extends KernelTestCase
{
    public function testAGuestOpensTheCompetitorPageOfAKnownTag(): void
    {
        $this->competitorPages(true, [F::COMPETITION_RESULTS_CUP]);
        $cup = F::COMPETITION_RESULTS_CUP;
        $sharks = CompetitionLineupId::of($cup, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);

        self::assertSame('/en/competitor/' . $cup . '/t/12', $this->landing(CompetitionTag::table(12), 'en'));
        self::assertSame('/de/competitor/' . $cup . '/e/' . F::ENTRY_A_BEN, $this->landing(CompetitionTag::entry(F::ENTRY_A_BEN), 'de'));
        self::assertSame('/cs/competitor/' . $cup . '/g/' . $sharks, $this->landing(CompetitionTag::lineup($sharks), 'cs'));
        self::assertSame('/en/competitor/' . $cup . '/p/' . F::PARTICIPANT_IVAN, $this->landing(CompetitionTag::participant(F::PARTICIPANT_IVAN), 'en'));
    }

    public function testATagOfNobodyInTheEventOpensTheEventPage(): void
    {
        $this->competitorPages(true, [F::COMPETITION_RESULTS_CUP]);
        $eventPage = self::getContainer()->get(CompetitionDetailUrl::class)->of(F::COMPETITION_RESULTS_CUP, 'en');
        $unknown = '018d0020-0000-0000-0000-00000000ffff';

        self::assertSame($eventPage, $this->landing(CompetitionTag::entry($unknown), 'en'));
        self::assertSame($eventPage, $this->landing(CompetitionTag::lineup($unknown), 'en'));
        self::assertSame($eventPage, $this->landing(CompetitionTag::participant($unknown), 'en'));
    }

    public function testCompetitorPagesOffOrADraftKeepTheirLandings(): void
    {
        self::assertSame(
            self::getContainer()->get(CompetitionDetailUrl::class)->of(F::COMPETITION_RESULTS_CUP, 'en'),
            $this->landing(CompetitionTag::table(1), 'en'),
        );

        $this->competitorPages(true, [OrganizationFixture::EDITION_LANTERN_DRAFT]);
        self::assertSame('/en/events', $this->landing(CompetitionTag::table(1), 'en', OrganizationFixture::EDITION_LANTERN_DRAFT));
    }

    private function landing(CompetitionTag $tag, string $locale, string $competitionId = F::COMPETITION_RESULTS_CUP): string
    {
        $container = self::getContainer();
        $routes = new RouteCollection();
        $routes->add(CompetitorPageUrl::ROUTE, new Route('/{_locale}/competitor/{competitionId}/{kind}/{id}'));

        $landing = new CompetitionTagLanding(
            $container->get(AuthorizationCheckerInterface::class),
            $container->get(CompetitionTagResolver::class),
            new CompetitorPageUrl(new UrlGenerator($routes, new RequestContext())),
            $container->get(CompetitionDetailUrl::class),
            $container->get(CompetitionRepository::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(RequestStack::class),
            $container->get(TranslatorInterface::class),
        );

        $response = $landing->redirect($competitionId, $tag, $locale);
        self::assertSame(302, $response->getStatusCode());

        return $response->getTargetUrl();
    }

    /**
     * @param list<string> $competitionIds
     */
    private function competitorPages(bool $on, array $competitionIds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = :on WHERE id IN (:ids)',
            ['on' => $on, 'ids' => $competitionIds],
            ['on' => ParameterType::BOOLEAN, 'ids' => ArrayParameterType::STRING],
        );
    }
}
