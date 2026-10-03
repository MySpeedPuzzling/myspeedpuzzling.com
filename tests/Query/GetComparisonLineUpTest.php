<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Query\GetComparisonLineUp;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetComparisonLineUpTest extends KernelTestCase
{
    private GetComparisonLineUp $getComparisonLineUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getComparisonLineUp = self::getContainer()->get(GetComparisonLineUp::class);
    }

    public function testEveryLineUpOldestFirst(): void
    {
        $container = self::getContainer();
        $stripe = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $team = $container->get(PuzzlingTeamResolver::class)->resolve(
            $container->get(PuzzlersGrouping::class)->assembleGroup($stripe, ['#admin', 'Granny']),
        );
        assert($team !== null);
        $container->get(MessageBusInterface::class)->dispatch(new AddComparisonSubject(
            PlayerFixture::PLAYER_WITH_STRIPE,
            ComparisonSubjectRef::team($team->id->toString())->toString(),
        ));

        $lineUp = $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(
            [
                [ComparisonSubjectFixture::STRIPE_SELF, 'p-' . PlayerFixture::PLAYER_WITH_STRIPE, 'solo', true],
                [ComparisonSubjectFixture::STRIPE_ADMIN, 'p-' . PlayerFixture::PLAYER_ADMIN, 'solo', false],
                [ComparisonSubjectFixture::STRIPE_REGULAR, 'p-' . PlayerFixture::PLAYER_REGULAR, 'solo', false],
                [ComparisonSubjectFixture::STRIPE_PAIR, $lineUp[3]->ref->toString(), 'pairs', false],
                [$lineUp[4]->rowId, 't-' . $team->id->toString(), 'teams', false],
            ],
            array_map(
                static fn (ComparisonLineUpItem $item): array => [$item->rowId, $item->ref->toString(), $item->kind->value, $item->isSelf],
                $lineUp,
            ),
        );
        self::assertFalse($lineUp[3]->ref->isPlayer());
        self::assertLessThan($lineUp[3]->addedAt, $lineUp[2]->addedAt);
    }

    public function testNothingForNobody(): void
    {
        self::assertSame([], $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame([], $this->getComparisonLineUp->forPlayer('not-a-uuid'));
    }
}
