<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\ClosePartnerListing;
use SpeedPuzzling\Web\Message\EditPartnerListing;
use SpeedPuzzling\Web\Message\ListForPartner;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use SpeedPuzzling\Web\Value\PartnerStrength;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class PartnerListingHandlersTest extends KernelTestCase
{
    private const string EVENT = PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN;

    private MessageBusInterface $messageBus;
    private PartnerListingRepository $listingRepository;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->listingRepository = self::getContainer()->get(PartnerListingRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testListingSavesTheListingAndMarksThePlayerGoing(): void
    {
        $listingId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch($this->listMe($listingId, PlayerFixture::PLAYER_ADMIN, custom: ['sorting', 'Night  skies', 'night skies']));

        $listing = $this->listingRepository->get($listingId);
        self::assertTrue($listing->isOpen());
        self::assertSame([PartnerKind::Pair], $listing->lookingFor());
        // A custom chip reading like a preset becomes it; customs folding equal are kept once
        self::assertSame([PartnerStrength::Edges, PartnerStrength::Sorting], $listing->strengths());
        self::assertSame(['Night skies'], $listing->customStrengths());
        self::assertSame(['cs', 'en'], $listing->languages());
        self::assertSame(1, $this->goingRows(PlayerFixture::PLAYER_ADMIN));
    }

    public function testTheSameFormSentTwiceSavesOnce(): void
    {
        $listingId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch($this->listMe($listingId, PlayerFixture::PLAYER_ADMIN, note: 'First'));
        $this->messageBus->dispatch($this->listMe($listingId, PlayerFixture::PLAYER_ADMIN, note: 'Second'));
        $this->entityManager->clear();

        self::assertSame(1, $this->openListings(PlayerFixture::PLAYER_ADMIN));
        self::assertSame('First', $this->listingRepository->get($listingId)->note);
    }

    public function testASecondListingWhileOneIsOpenEditsTheOpenOne(): void
    {
        $this->messageBus->dispatch($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_REGULAR, note: 'From another tab'));
        $this->entityManager->clear();

        self::assertSame(1, $this->openListings(PlayerFixture::PLAYER_REGULAR));
        self::assertSame('From another tab', $this->listingRepository->get(PartnerFinderFixture::LISTING_REGULAR_PAIR)->note);
    }

    public function testAnEditPostedForAListingClosedMeanwhileListsThePlayerAfreshWithANewId(): void
    {
        // PLAYER_WITH_STRIPE's listing was closed (in another tab, by an auto-close) while her edit form was open
        $this->messageBus->dispatch($this->listMe(PartnerFinderFixture::LISTING_STRIPE_CLOSED, PlayerFixture::PLAYER_WITH_STRIPE, note: 'Still looking'));
        $this->entityManager->clear();

        $closed = $this->listingRepository->get(PartnerFinderFixture::LISTING_STRIPE_CLOSED);
        self::assertFalse($closed->isOpen(), 'the closed listing stays closed');
        self::assertNotSame('Still looking', $closed->note);

        $open = $this->listingRepository->findOpenFor(self::EVENT, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($open, 'the player is listed - as the page tells them');
        self::assertNotSame(PartnerFinderFixture::LISTING_STRIPE_CLOSED, $open->id->toString());
        self::assertSame('Still looking', $open->note);

        // The same form sent again edits that open listing - still one
        $this->messageBus->dispatch($this->listMe(PartnerFinderFixture::LISTING_STRIPE_CLOSED, PlayerFixture::PLAYER_WITH_STRIPE, note: 'Resent'));
        $this->entityManager->clear();
        self::assertSame(1, $this->openListings(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAGroupCannotLookForAPair(): void
    {
        // A group of two or more is a pair already (D1)
        $exception = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_ADMIN, kinds: [PartnerKind::Pair], groupSize: 2, needed: 1));

        self::assertInstanceOf(PartnerListingInvalid::class, $exception);
        self::assertSame(PartnerListingInvalid::FIELD_LOOKING_FOR, $exception->field);
        self::assertSame(0, $this->openListings(PlayerFixture::PLAYER_ADMIN));
        self::assertSame(0, $this->goingRows(PlayerFixture::PLAYER_ADMIN), 'nothing changed');
    }

    public function testAKindTheEventDoesNotOfferIsRefused(): void
    {
        $this->database->executeStatement('DELETE FROM competition_round WHERE id = :id', ['id' => PartnerFinderFixture::ROUND_TEAMS]);

        $exception = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_ADMIN, kinds: [PartnerKind::Team]));

        self::assertInstanceOf(PartnerListingInvalid::class, $exception);
        self::assertSame(PartnerListingInvalid::FIELD_LOOKING_FOR, $exception->field);
        self::assertSame(0, $this->goingRows(PlayerFixture::PLAYER_ADMIN), 'nothing changed');
    }

    public function testAnEventWithoutAFinderIsRefused(): void
    {
        $message = new ListForPartner(
            listingId: Uuid::uuid7()->toString(),
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            groupSize: 1,
            lookingFor: [PartnerKind::Pair],
            needed: null,
            coMemberIds: [],
            goal: null,
            strengths: [],
            customStrengths: [],
            languages: [],
            note: null,
        );

        self::assertInstanceOf(PartnerFinderNotAvailable::class, $this->refused($message));
    }

    public function testALanguageThatIsNoIsoCodeIsRefusedBeforeAnythingChanges(): void
    {
        $exception = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_ADMIN, languages: ['cs', 'xx']));

        self::assertInstanceOf(PartnerListingInvalid::class, $exception);
        self::assertSame(PartnerListingInvalid::FIELD_LANGUAGES, $exception->field);
        self::assertSame(0, $this->goingRows(PlayerFixture::PLAYER_ADMIN));
        self::assertSame(0, $this->openListings(PlayerFixture::PLAYER_ADMIN));
    }

    public function testCoMembersMustBePlayersTheListerMayName(): void
    {
        // PLAYER_REGULAR blocks PLAYER_PRIVATE (UserBlockFixture)
        $blocked = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_REGULAR, kinds: [PartnerKind::Team], groupSize: 2, needed: 2, coMembers: [PlayerFixture::PLAYER_PRIVATE]));
        self::assertInstanceOf(PartnerListingInvalid::class, $blocked);
        self::assertSame(PartnerListingInvalid::FIELD_CO_MEMBERS, $blocked->field);

        // A private player who has not allowed the lister (PLAYER_ADMIN is not on her allow list)
        $private = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_ADMIN, kinds: [PartnerKind::Team], groupSize: 2, needed: 2, coMembers: [PlayerFixture::PLAYER_PRIVATE]));
        self::assertInstanceOf(PartnerListingInvalid::class, $private);

        // Nobody at all
        $unknown = $this->refused($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_ADMIN, kinds: [PartnerKind::Team], groupSize: 2, needed: 2, coMembers: [Uuid::uuid7()->toString()]));
        self::assertInstanceOf(PartnerListingInvalid::class, $unknown);

        // Her friend may (PLAYER_WITH_FAVORITES is on her allow list) - his open listing is edited
        $this->messageBus->dispatch($this->listMe(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_WITH_FAVORITES, kinds: [PartnerKind::Team], groupSize: 2, needed: 2, coMembers: [strtoupper(PlayerFixture::PLAYER_PRIVATE)]));
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], $this->listingRepository->get(PartnerFinderFixture::LISTING_FAVORITES_TEAM)->coMemberIds());
    }

    public function testTheOwnerEditsTheListingAndNobodyElse(): void
    {
        $refused = $this->refused($this->edit(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_ADMIN));
        self::assertInstanceOf(PartnerListingNotFound::class, $refused);

        $this->messageBus->dispatch($this->edit(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_REGULAR));

        $listing = $this->listingRepository->get(PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $listing->lookingFor());
        self::assertSame(PartnerGoal::FunFirst, $listing->goal);
        self::assertSame('Edited', $listing->note);

        // Co-members not named in the message stay as they are
        $this->messageBus->dispatch($this->edit(PartnerFinderFixture::LISTING_FAVORITES_TEAM, PlayerFixture::PLAYER_WITH_FAVORITES, kinds: [PartnerKind::Team], groupSize: 2, needed: 1));
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->listingRepository->get(PartnerFinderFixture::LISTING_FAVORITES_TEAM)->coMemberIds());

        // A closed listing is not there to edit
        self::assertInstanceOf(PartnerListingNotFound::class, $this->refused($this->edit(PartnerFinderFixture::LISTING_STRIPE_CLOSED, PlayerFixture::PLAYER_WITH_STRIPE)));
    }

    public function testFoundMyPartnerCountsAGroupDownThenCloses(): void
    {
        $this->messageBus->dispatch(new ClosePartnerListing(PartnerFinderFixture::LISTING_FAVORITES_TEAM, PlayerFixture::PLAYER_WITH_FAVORITES, PartnerListingClosedReason::Found));
        $listing = $this->listingRepository->get(PartnerFinderFixture::LISTING_FAVORITES_TEAM);
        self::assertTrue($listing->isOpen());
        self::assertSame(1, $listing->needed);

        $this->messageBus->dispatch(new ClosePartnerListing(PartnerFinderFixture::LISTING_FAVORITES_TEAM, PlayerFixture::PLAYER_WITH_FAVORITES, PartnerListingClosedReason::Found));
        self::assertFalse($listing->isOpen());
        self::assertSame(PartnerListingClosedReason::Found, $listing->closedReason);
    }

    public function testRemoveClosesAsWithdrawnAndOnlyForTheOwner(): void
    {
        $refused = $this->refused(new ClosePartnerListing(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_ADMIN, PartnerListingClosedReason::Withdrawn));
        self::assertInstanceOf(PartnerListingNotFound::class, $refused);

        $this->messageBus->dispatch(new ClosePartnerListing(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_REGULAR, PartnerListingClosedReason::Withdrawn));

        $listing = $this->listingRepository->get(PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertFalse($listing->isOpen());
        self::assertSame(PartnerListingClosedReason::Withdrawn, $listing->closedReason);
    }

    public function testFoundMyPartnerAsksOnlyACandidateToCloseTheirsToo(): void
    {
        // Michael (an open team listing) asked John - a candidate; Stripe asked John too, but her listing is closed;
        // the admin never exchanged anything with John
        $this->database->executeStatement(
            "INSERT INTO partner_request (id, listing_id, from_player_id, kind, created_at) VALUES (:id, :listing, :from, 'pair', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'listing' => PartnerFinderFixture::LISTING_REGULAR_PAIR, 'from' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $this->messageBus->dispatch(new ClosePartnerListing(
            PartnerFinderFixture::LISTING_REGULAR_PAIR,
            PlayerFixture::PLAYER_REGULAR,
            PartnerListingClosedReason::Found,
            teamedUpWith: [strtoupper(PlayerFixture::PLAYER_WITH_FAVORITES), PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN],
        ));

        self::assertFalse($this->listingRepository->get(PartnerFinderFixture::LISTING_REGULAR_PAIR)->isOpen());
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->notified(NotificationType::PartnerTeamedUp, PlayerFixture::PLAYER_REGULAR));
        // Asked, never closed for them
        self::assertTrue($this->listingRepository->get(PartnerFinderFixture::LISTING_FAVORITES_TEAM)->isOpen());
    }

    public function testNobodyIsAskedWhenTheListingIsRemovedOrBlockedEitherWay(): void
    {
        $this->database->executeStatement(
            "INSERT INTO partner_request (id, listing_id, from_player_id, kind, created_at) VALUES (:id, :listing, :from, 'pair', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'listing' => PartnerFinderFixture::LISTING_REGULAR_PAIR, 'from' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        $this->messageBus->dispatch(new ClosePartnerListing(
            PartnerFinderFixture::LISTING_REGULAR_PAIR,
            PlayerFixture::PLAYER_REGULAR,
            PartnerListingClosedReason::Found,
            teamedUpWith: [PlayerFixture::PLAYER_WITH_FAVORITES],
        ));

        self::assertSame([], $this->notified(NotificationType::PartnerTeamedUp, PlayerFixture::PLAYER_REGULAR));
    }

    public function testAddedCoMembersAreToldOnce(): void
    {
        $listingId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch($this->listMe($listingId, PlayerFixture::PLAYER_ADMIN, kinds: [PartnerKind::Team], groupSize: 3, needed: 1, coMembers: [PlayerFixture::PLAYER_REGULAR]));
        self::assertSame([PlayerFixture::PLAYER_REGULAR], $this->notified(NotificationType::PartnerGroupMember, PlayerFixture::PLAYER_ADMIN));

        // An edit keeping John and adding Stripe tells only Stripe
        $edit = new EditPartnerListing(
            listingId: $listingId,
            playerId: PlayerFixture::PLAYER_ADMIN,
            groupSize: 3,
            lookingFor: [PartnerKind::Team],
            needed: 1,
            coMemberIds: [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
            goal: null,
            strengths: [],
            customStrengths: [],
            languages: ['en'],
            note: null,
        );
        $this->messageBus->dispatch($edit);

        $told = $this->notified(NotificationType::PartnerGroupMember, PlayerFixture::PLAYER_ADMIN);
        sort($told);
        $expected = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];
        sort($expected);
        self::assertSame($expected, $told);
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE], $this->listingRepository->get($listingId)->coMemberIds());
    }

    /**
     * @param list<PartnerKind> $kinds
     * @param list<string> $custom
     * @param list<string> $languages
     * @param list<string> $coMembers
     */
    private function listMe(
        string $listingId,
        string $playerId,
        array $kinds = [PartnerKind::Pair],
        int $groupSize = 1,
        null|int $needed = null,
        array $coMembers = [],
        array $custom = [],
        array $languages = ['CS', 'en'],
        null|string $note = null,
    ): ListForPartner {
        return new ListForPartner(
            listingId: $listingId,
            competitionId: self::EVENT,
            playerId: $playerId,
            groupSize: $groupSize,
            lookingFor: $kinds,
            needed: $needed,
            coMemberIds: $coMembers,
            goal: PartnerGoal::Either,
            strengths: ['edges'],
            customStrengths: $custom,
            languages: $languages,
            note: $note,
        );
    }

    /**
     * @param list<PartnerKind> $kinds
     */
    private function edit(string $listingId, string $playerId, array $kinds = [PartnerKind::Pair, PartnerKind::Team], int $groupSize = 1, null|int $needed = null): EditPartnerListing
    {
        return new EditPartnerListing(
            listingId: $listingId,
            playerId: $playerId,
            groupSize: $groupSize,
            lookingFor: $kinds,
            needed: $needed,
            coMemberIds: null,
            goal: PartnerGoal::FunFirst,
            strengths: ['sky'],
            customStrengths: [],
            languages: ['de'],
            note: 'Edited',
        );
    }

    private function refused(object $message): \Throwable
    {
        // HTTP-flavoured exceptions come unwrapped (UnwrapHttpExceptionMiddleware)
        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $this->entityManager->clear();

            return $exception->getPrevious() ?? $exception;
        } catch (\Throwable $exception) {
            $this->entityManager->clear();

            return $exception;
        }

        self::fail('The message was not refused.');
    }

    /**
     * Who got a notification of that type from that actor
     *
     * @return list<string>
     */
    private function notified(NotificationType $type, string $actorId): array
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn(
            'SELECT player_id FROM notification WHERE type = :type AND actor_player_id = :actor ORDER BY notified_at, id',
            ['type' => $type->value, 'actor' => $actorId],
        );

        return $playerIds;
    }

    private function goingRows(string $playerId): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :competition AND player_id = :player AND deleted_at IS NULL',
            ['competition' => self::EVENT, 'player' => $playerId],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function openListings(string $playerId): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM partner_listing WHERE competition_id = :competition AND player_id = :player AND closed_at IS NULL',
            ['competition' => self::EVENT, 'player' => $playerId],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
