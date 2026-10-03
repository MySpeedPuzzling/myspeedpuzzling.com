<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonLineUpFull;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotAvailable;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Query\GetComparisonLineUp;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonLimits;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fixture line-ups (ComparisonSubjectFixture): PLAYER_REGULAR (free) Solo = self + PLAYER_WITH_STRIPE; PLAYER_WITH_STRIPE
 * (member) Solo = self + PLAYER_ADMIN + PLAYER_REGULAR, Pairs = the PLAYER_REGULAR & PLAYER_PRIVATE pair. PLAYER_ADMIN
 * (member) and PLAYER_WITH_FAVORITES (free) start empty.
 */
final class AddComparisonSubjectHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetComparisonLineUp $getComparisonLineUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getComparisonLineUp = $container->get(GetComparisonLineUp::class);
    }

    public function testTheFirstSoloSubjectBringsTheOwnerInFirst(): void
    {
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN));

        $lineUp = $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(
            [
                ['p-' . PlayerFixture::PLAYER_WITH_FAVORITES, 'solo', true],
                ['p-' . PlayerFixture::PLAYER_ADMIN, 'solo', false],
            ],
            $this->describe($lineUp),
        );
    }

    public function testAddingYourselfToAnEmptyLineUpIsOneRow(): void
    {
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertSame(
            [['p-' . PlayerFixture::PLAYER_WITH_FAVORITES, 'solo', true]],
            $this->describe($this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );
    }

    public function testPairsAndTeamsGoToTheirOwnLineUpsWithoutTheOwner(): void
    {
        $pair = $this->team(PlayerFixture::PLAYER_WITH_FAVORITES, ['#admin']);
        $team = $this->team(PlayerFixture::PLAYER_WITH_FAVORITES, ['#admin', '#player4']);

        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::team($pair));
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::team($team));

        self::assertSame(
            [
                ['t-' . $pair, 'pairs', false],
                ['t-' . $team, 'teams', false],
            ],
            $this->describe($this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );
    }

    public function testAddingWhatIsAlreadyThereChangesNothing(): void
    {
        $before = $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        $pairId = $before[3]->ref->id;

        $this->add(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN));
        $this->add(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::player(strtoupper(PlayerFixture::PLAYER_WITH_STRIPE)));
        $this->add(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::team($pairId));

        self::assertEquals($before, $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE));

        // A full line-up too: already in beats "full"
        $this->add(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_STRIPE));

        self::assertCount(2, $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAMemberComparesTenSubjectsPerKind(): void
    {
        $owner = PlayerFixture::PLAYER_ADMIN;
        $others = $this->createPlayers(ComparisonLimits::MEMBER);

        // The first one brings the owner in: 1 + 9 = 10
        foreach (array_slice($others, 0, ComparisonLimits::MEMBER - 1) as $playerId) {
            $this->add($owner, ComparisonSubjectRef::player($playerId));
        }

        self::assertCount(ComparisonLimits::MEMBER, $this->getComparisonLineUp->forPlayer($owner));

        try {
            $this->add($owner, ComparisonSubjectRef::player($others[ComparisonLimits::MEMBER - 1]));
            self::fail('The eleventh subject should not fit');
        } catch (ComparisonLineUpFull $exception) {
            self::assertSame(ComparisonKind::Solo, $exception->kind);
            self::assertSame(ComparisonLimits::MEMBER, $exception->cap);
        }

        // Another kind has its own cap
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['Granny'])));

        // A member may swap themselves out
        $self = $this->getComparisonLineUp->forPlayer($owner)[0];
        self::assertTrue($self->isSelf);

        $this->add($owner, ComparisonSubjectRef::player($others[ComparisonLimits::MEMBER - 1]), $self->rowId);

        $solo = $this->soloOf($owner);
        self::assertCount(ComparisonLimits::MEMBER, $solo);
        self::assertNotContains('p-' . $owner, array_map(static fn (ComparisonLineUpItem $item): string => $item->ref->toString(), $solo));
    }

    public function testAFreePlayerComparesThemselvesAndOneOther(): void
    {
        try {
            $this->add(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN));
            self::fail('A free Solo line-up is you + 1 other');
        } catch (ComparisonLineUpFull $exception) {
            self::assertSame(ComparisonKind::Solo, $exception->kind);
            self::assertSame(ComparisonLimits::FREE, $exception->cap);
        }

        self::assertCount(2, $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAFreePlayerComparesTwoPairsAndTwoTeams(): void
    {
        $owner = PlayerFixture::PLAYER_WITH_FAVORITES;

        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['Granny'])));
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['#admin'])));

        try {
            $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['#player4'])));
            self::fail('A free player compares two pairs');
        } catch (ComparisonLineUpFull $exception) {
            self::assertSame(ComparisonKind::Pairs, $exception->kind);
        }

        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['Granny', 'Grandpa'])));
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['#admin', '#player4'])));

        $this->expectException(ComparisonLineUpFull::class);
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['#admin', 'Granny'])));
    }

    public function testSwapReplacesTheNamedRow(): void
    {
        $this->add(
            PlayerFixture::PLAYER_REGULAR,
            ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN),
            ComparisonSubjectFixture::REGULAR_STRIPE,
        );

        self::assertSame(
            [
                ['p-' . PlayerFixture::PLAYER_REGULAR, 'solo', true],
                ['p-' . PlayerFixture::PLAYER_ADMIN, 'solo', false],
            ],
            $this->describe($this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_REGULAR)),
        );
    }

    public function testAFreePlayerCanNotSwapThemselvesOut(): void
    {
        try {
            $this->add(
                PlayerFixture::PLAYER_REGULAR,
                ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN),
                ComparisonSubjectFixture::REGULAR_SELF,
            );
            self::fail('The owner\'s own row stays without a membership');
        } catch (CanNotRemoveYourselfFromComparison) {
        }

        self::assertSame(
            [ComparisonSubjectFixture::REGULAR_SELF, ComparisonSubjectFixture::REGULAR_STRIPE],
            array_map(static fn (ComparisonLineUpItem $item): string => $item->rowId, $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_REGULAR)),
        );
    }

    public function testTheSwappedRowMustBeOfTheSameOwnersSameLineUp(): void
    {
        $owner = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->add($owner, ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN));
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['Granny'])));
        $this->add($owner, ComparisonSubjectRef::team($this->team($owner, ['#admin'])));
        $before = $this->getComparisonLineUp->forPlayer($owner);
        $soloRowOfOwner = $before[1]->rowId;
        $thirdPair = ComparisonSubjectRef::team($this->team($owner, ['#player4']));

        foreach ([ComparisonSubjectFixture::STRIPE_PAIR, $soloRowOfOwner, Uuid::uuid7()->toString(), 'not-an-id'] as $replace) {
            try {
                $this->add($owner, $thirdPair, $replace);
                self::fail('Not a row of this line-up: ' . $replace);
            } catch (ComparisonSubjectNotFound) {
            }
        }

        self::assertEquals($before, $this->getComparisonLineUp->forPlayer($owner));
    }

    public function testASwapWithRoomLeftOnlyAdds(): void
    {
        $owner = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->add($owner, ComparisonSubjectRef::team($first = $this->team($owner, ['Granny'])));
        $firstRow = $this->getComparisonLineUp->forPlayer($owner)[0]->rowId;

        $this->add($owner, ComparisonSubjectRef::team($second = $this->team($owner, ['#admin'])), $firstRow);

        self::assertSame(
            [['t-' . $first, 'pairs', false], ['t-' . $second, 'pairs', false]],
            $this->describe($this->getComparisonLineUp->forPlayer($owner)),
        );
    }

    public function testSubjectsTheOwnerMayNotSeeAreRefused(): void
    {
        $refused = [
            // PLAYER_REGULAR blocks PLAYER_PRIVATE
            [PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::player(PlayerFixture::PLAYER_PRIVATE)->toString()],
            // Private, and PLAYER_WITH_STRIPE is not on her allow list
            [PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::player(PlayerFixture::PLAYER_PRIVATE)->toString()],
            // Nobody / nothing
            [PlayerFixture::PLAYER_ADMIN, 'p-' . Uuid::uuid7()->toString()],
            [PlayerFixture::PLAYER_ADMIN, 't-' . Uuid::uuid7()->toString()],
            [PlayerFixture::PLAYER_ADMIN, 'garbage'],
        ];

        foreach ($refused as [$owner, $ref]) {
            try {
                $this->messageBus->dispatch(new AddComparisonSubject($owner, $ref));
                self::fail(sprintf('%s should not be able to add %s', $owner, $ref));
            } catch (ComparisonSubjectNotAvailable) {
            }
        }

        // His own pair - but with the player he blocks
        $pairWithBlocked = $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE)[3]->ref;

        $this->expectException(ComparisonSubjectNotAvailable::class);
        $this->add(PlayerFixture::PLAYER_REGULAR, $pairWithBlocked);
    }

    public function testBeingBlockedBySomebodyDoesNotMakeThemUnavailable(): void
    {
        // PLAYER_REGULAR blocks PLAYER_PRIVATE - she must not be able to tell, so she compares him like anybody else
        $this->add(PlayerFixture::PLAYER_PRIVATE, ComparisonSubjectRef::player(PlayerFixture::PLAYER_REGULAR));

        self::assertSame(
            [
                ['p-' . PlayerFixture::PLAYER_PRIVATE, 'solo', true],
                ['p-' . PlayerFixture::PLAYER_REGULAR, 'solo', false],
            ],
            $this->describe($this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_PRIVATE)),
        );
    }

    public function testAPrivatePlayerWhoRevealedThemselvesCanBeCompared(): void
    {
        $this->add(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::player(PlayerFixture::PLAYER_PRIVATE));

        self::assertSame(
            ['p-' . PlayerFixture::PLAYER_WITH_FAVORITES, 'p-' . PlayerFixture::PLAYER_PRIVATE],
            array_map(static fn (ComparisonLineUpItem $item): string => $item->ref->toString(), $this->getComparisonLineUp->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );
    }

    private function add(string $ownerId, ComparisonSubjectRef $ref, null|string $replace = null): void
    {
        $this->messageBus->dispatch(new AddComparisonSubject($ownerId, $ref->toString(), $replace));
    }

    /**
     * @param list<ComparisonLineUpItem> $lineUp
     * @return list<array{string, string, bool}>
     */
    private function describe(array $lineUp): array
    {
        return array_map(
            static fn (ComparisonLineUpItem $item): array => [$item->ref->toString(), $item->kind->value, $item->isSelf],
            $lineUp,
        );
    }

    /**
     * @return list<ComparisonLineUpItem>
     */
    private function soloOf(string $ownerId): array
    {
        return array_values(array_filter(
            $this->getComparisonLineUp->forPlayer($ownerId),
            static fn (ComparisonLineUpItem $item): bool => $item->kind === ComparisonKind::Solo,
        ));
    }

    /**
     * @param list<string> $others "#code" or a guest name, like the add-time form
     */
    private function team(string $playerId, array $others): string
    {
        $container = self::getContainer();
        $player = $container->get(PlayerRepository::class)->get($playerId);
        $group = $container->get(PuzzlersGrouping::class)->assembleGroup($player, $others);
        $team = $container->get(PuzzlingTeamResolver::class)->resolve($group);
        assert($team !== null);

        return $team->id->toString();
    }

    /**
     * @return list<string>
     */
    private function createPlayers(int $count): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $player = new Player(Uuid::uuid7(), 'compare' . $i, null, 'Compared ' . $i, new DateTimeImmutable());
            $entityManager->persist($player);
            $ids[] = $player->id->toString();
        }

        $entityManager->flush();

        return $ids;
    }
}
