<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Competition toolkit P1F: picking your own participant again (the name list, the connect form, an import or sheet
 * write planned before the link) keeps the link's moment - a link newer than a round's start may not propose times
 * (TimeProposalOwnership), so a fresh connectedAt would lock the rightful competitor out. Another player's pick still
 * links anew.
 */
final class RepickedOwnParticipantKeepsLinkTest extends KernelTestCase
{
    private const string LINKED_AT = '2026-01-02 03:04:05';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testJoiningByPickingYourOwnNameAgainKeepsTheLink(): void
    {
        // PARTICIPANT_SELF_JOINED is linked to PLAYER_WITH_FAVORITES
        self::linkedAt(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);

        self::bus()->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            participantId: CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED,
        ));

        self::assertSame(['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'connected_at' => self::LINKED_AT], self::link(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED));
    }

    public function testConnectingYourOwnParticipantAgainKeepsTheLink(): void
    {
        self::linkedAt(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);

        self::bus()->dispatch(new ConnectCompetitionParticipant(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED,
        ));

        self::assertSame(['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'connected_at' => self::LINKED_AT], self::link(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED));
    }

    public function testConnectingAnotherParticipantStillMovesTheLink(): void
    {
        self::linkedAt(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);

        self::bus()->dispatch(new ConnectCompetitionParticipant(
            CompetitionFixture::COMPETITION_WJPC_2024,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        self::assertNull(self::link(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED)['player_id']);
        $new = self::link(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $new['player_id']);
        self::assertNotSame(self::LINKED_AT, $new['connected_at']);
    }

    public function testAnImportConnectingTheSamePlayerAgainKeepsTheLink(): void
    {
        // Anna is linked to PLAYER_ADMIN
        self::linkedAt(OfficialResultsFixture::PARTICIPANT_ANNA);

        self::apply(OfficialResultsFixture::PARTICIPANT_ANNA, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(['player_id' => PlayerFixture::PLAYER_ADMIN, 'connected_at' => self::LINKED_AT], self::link(OfficialResultsFixture::PARTICIPANT_ANNA));
    }

    public function testAnImportConnectingAnotherPlayerLinksAnew(): void
    {
        self::linkedAt(OfficialResultsFixture::PARTICIPANT_ANNA);

        self::apply(OfficialResultsFixture::PARTICIPANT_ANNA, PlayerFixture::PLAYER_REGULAR);

        $link = self::link(OfficialResultsFixture::PARTICIPANT_ANNA);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $link['player_id']);
        self::assertNotSame(self::LINKED_AT, $link['connected_at']);
    }

    private static function apply(string $participantId, string $playerId): void
    {
        $name = self::db()->fetchOne('SELECT name FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        $country = self::db()->fetchOne('SELECT country FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        assert(is_string($name) && (is_string($country) || $country === null));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::getContainer()->get(ParticipantImportApplier::class)->applyOperations(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            new ParticipantImportOperations(participants: [[
                'key' => $participantId,
                'name' => $name,
                'country' => $country,
                'externalId' => null,
                'connectPlayerId' => $playerId,
                'markAsImported' => false,
                'restore' => false,
                'softDelete' => false,
                'changed' => true,
            ]]),
        );
        $entityManager->flush();
        $entityManager->clear();
    }

    private static function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private static function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function linkedAt(string $participantId): void
    {
        self::db()->executeStatement('UPDATE competition_participant SET connected_at = :at WHERE id = :id', ['at' => self::LINKED_AT, 'id' => $participantId]);
    }

    /**
     * @return array{player_id: null|string, connected_at: null|string}
     */
    private static function link(string $participantId): array
    {
        /** @var array{player_id: null|string, connected_at: null|string} $row */
        $row = self::db()->fetchAssociative('SELECT player_id, connected_at FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $row;
    }
}
