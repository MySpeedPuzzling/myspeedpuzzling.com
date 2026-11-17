<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Query\GetParticipantClaimPolicy;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;

/**
 * D3 + R9 (docs/features/competitions-management/participant-linking.md): NULL = automatic - `approval` while time
 * proposals are effective at an event that is not online by the series-aware rule, else `open`; a stored choice wins.
 */
final class ParticipantClaimPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{null|ParticipantClaimPolicy, bool, bool, bool, ParticipantClaimPolicy}>
     */
    public static function cases(): iterable
    {
        yield 'automatic, nothing on' => [null, false, false, false, ParticipantClaimPolicy::Open];
        yield 'automatic, pages without proposals' => [null, true, false, false, ParticipantClaimPolicy::Open];
        yield 'automatic, proposals on' => [null, true, true, false, ParticipantClaimPolicy::Approval];
        yield 'automatic, proposals on but online' => [null, true, true, true, ParticipantClaimPolicy::Open];
        yield 'automatic, proposals without pages (never effective)' => [null, false, true, false, ParticipantClaimPolicy::Open];
        yield 'chosen open beats proposals' => [ParticipantClaimPolicy::Open, true, true, false, ParticipantClaimPolicy::Open];
        yield 'chosen approval online' => [ParticipantClaimPolicy::Approval, false, false, true, ParticipantClaimPolicy::Approval];
    }

    #[DataProvider('cases')]
    public function testResolve(null|ParticipantClaimPolicy $stored, bool $pages, bool $proposals, bool $online, ParticipantClaimPolicy $expected): void
    {
        self::assertSame($expected, ParticipantClaimPolicy::resolve($stored, $pages, $proposals, $online));
        self::assertSame($expected, GetParticipantClaimPolicy::fromRow($stored?->value, $pages, $proposals, $online)['policy']);
    }

    public function testTheEntityResolvesTheSame(): void
    {
        $competition = self::competition(isOnline: false, series: null);
        self::assertSame(ParticipantClaimPolicy::Open, $competition->participantClaimPolicy());

        $competition->changeCompetitorPageSettings(new CompetitorPageSettings(competitorPages: true, timeProposals: true));
        self::assertSame(ParticipantClaimPolicy::Approval, $competition->participantClaimPolicy());

        $competition->changeParticipantClaimPolicy(ParticipantClaimPolicy::Open);
        self::assertSame(ParticipantClaimPolicy::Open, $competition->participantClaimPolicy());
    }

    /**
     * R9: an edition is online by its series' flag (EventIsOnline), not by its own copy of it
     */
    public function testAnEditionOfAnOnlineSeriesIsOpenAutomatically(): void
    {
        $series = new CompetitionSeries(Uuid::uuid7(), 'Online Cup', 'online-cup', null, null, null, isOnline: true);
        // The edition's own flag says in person (the series was switched online later)
        $edition = self::competition(isOnline: false, series: $series);
        $edition->changeCompetitorPageSettings(new CompetitorPageSettings(competitorPages: true, timeProposals: true));

        self::assertSame(ParticipantClaimPolicy::Open, $edition->participantClaimPolicy());

        $series->isOnline = false;
        self::assertSame(ParticipantClaimPolicy::Approval, $edition->participantClaimPolicy());
    }

    private static function competition(bool $isOnline, null|CompetitionSeries $series): Competition
    {
        return new Competition(
            id: Uuid::uuid7(),
            name: 'Cup',
            slug: 'cup',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: null,
            dateTo: null,
            tag: null,
            isOnline: $isOnline,
            series: $series,
        );
    }
}
