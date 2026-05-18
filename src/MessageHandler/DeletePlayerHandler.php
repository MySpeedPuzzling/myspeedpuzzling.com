<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\ConversationReport;
use SpeedPuzzling\Web\Entity\DigestEmailLog;
use SpeedPuzzling\Web\Entity\DismissedHint;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Entity\FeatureRequestComment;
use SpeedPuzzling\Web\Entity\FeatureRequestCommentReport;
use SpeedPuzzling\Web\Entity\FeatureRequestVote;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2ClientRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Entity\TransactionRating;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DeletePlayerHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlayerRepository $playerRepository,
        private readonly StripeClient $stripeClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(DeletePlayer $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $playerId = $player->id->toString();
        $playerName = $player->name ?? $player->code;

        $this->cancelStripeSubscription($playerId);

        $this->handlePuzzleSolvingTimes($player, $playerName);
        $this->scrubFavoritePlayers($playerId);
        $this->deletePlayerOwnedRows($playerId);
        $this->deleteUserBlocks($playerId);
        $this->anonymizeConversations($player, $playerName);
        $this->anonymizeChatMessages($player, $playerName);
        $this->anonymizeFeatureRequests($player, $playerName);
        $this->anonymizeFeatureRequestComments($player, $playerName);
        $this->anonymizeFeatureRequestVotes($playerId);
        $this->anonymizeReportsAndModeration($playerId);
        $this->anonymizeSoldSwappedItems($player, $playerName);
        $this->anonymizeTransactionRatings($player, $playerName);
        $this->anonymizeCompetitionSeries($playerId);
        $this->anonymizeBulkSimpleFks($playerId);
        $this->hashEmailAuditLog($player);

        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy(['player' => $player]);

        if ($membership !== null) {
            $this->entityManager->remove($membership);
        }

        $this->entityManager->remove($player);

        $this->logger->info('Player deleted via GDPR request', [
            'player_id' => $playerId,
        ]);
    }

    private function cancelStripeSubscription(string $playerId): void
    {
        /** @var null|Membership $membership */
        $membership = $this->entityManager->getRepository(Membership::class)->findOneBy(['player' => $playerId]);

        if ($membership === null || $membership->stripeSubscriptionId === null) {
            return;
        }

        try {
            $this->stripeClient->subscriptions->cancel($membership->stripeSubscriptionId);
        } catch (ApiErrorException $e) {
            $this->logger->warning('Failed to cancel Stripe subscription during player deletion', [
                'player_id' => $playerId,
                'stripe_subscription_id' => $membership->stripeSubscriptionId,
                'exception' => $e,
            ]);
        }
    }

    private function handlePuzzleSolvingTimes(Player $player, string $playerName): void
    {
        $playerId = $player->id->toString();

        $ownedTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)->findBy(['player' => $player]);

        foreach ($ownedTimes as $time) {
            if ($time->team === null) {
                $this->entityManager->remove($time);
                continue;
            }

            $newOwner = $this->findNewOwner($time->team, $playerId);

            if ($newOwner === null) {
                $this->entityManager->remove($time);
                continue;
            }

            $newPuzzlers = $this->anonymizePuzzlerInGroup($time->team, $playerId, $playerName);
            $time->transferOwnership($newOwner, new PuzzlersGroup($time->team->teamId, $newPuzzlers));
        }

        $teamMemberTimes = $this->findTimesWherePlayerIsTeamMember($playerId);

        foreach ($teamMemberTimes as $time) {
            if ($time->team === null) {
                continue;
            }

            $newPuzzlers = $this->anonymizePuzzlerInGroup($time->team, $playerId, $playerName);
            $time->replaceTeam(new PuzzlersGroup($time->team->teamId, $newPuzzlers));
        }
    }

    /**
     * @return array<PuzzleSolvingTime>
     */
    private function findTimesWherePlayerIsTeamMember(string $playerId): array
    {
        $sql = "SELECT id FROM puzzle_solving_time
                WHERE team::jsonb -> 'puzzlers' @> :needle::jsonb
                AND player_id != :playerId";
        $needle = json_encode([['player_id' => $playerId]]);

        $ids = $this->entityManager->getConnection()
            ->executeQuery($sql, ['needle' => $needle, 'playerId' => $playerId])
            ->fetchFirstColumn();

        if (count($ids) === 0) {
            return [];
        }

        return $this->entityManager->getRepository(PuzzleSolvingTime::class)->findBy(['id' => $ids]);
    }

    private function findNewOwner(PuzzlersGroup $team, string $deletedPlayerId): null|Player
    {
        foreach ($team->puzzlers as $puzzler) {
            if ($puzzler->playerId !== null && $puzzler->playerId !== $deletedPlayerId) {
                $candidate = $this->entityManager->find(Player::class, $puzzler->playerId);

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return non-empty-array<Puzzler>
     */
    private function anonymizePuzzlerInGroup(PuzzlersGroup $team, string $deletedPlayerId, string $deletedPlayerName): array
    {
        $puzzlers = [];

        foreach ($team->puzzlers as $puzzler) {
            if ($puzzler->playerId === $deletedPlayerId) {
                $puzzlers[] = new Puzzler(
                    playerId: null,
                    playerName: $deletedPlayerName,
                    playerCode: null,
                    playerCountry: null,
                    isPrivate: false,
                );
                continue;
            }

            $puzzlers[] = $puzzler;
        }

        return $puzzlers;
    }

    private function scrubFavoritePlayers(string $playerId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE player SET favorite_players = (
                SELECT COALESCE(json_agg(elem), '[]'::json)
                FROM json_array_elements_text(favorite_players) elem
                WHERE elem <> :playerId
            ) WHERE favorite_players::jsonb @> :needle::jsonb",
            [
                'playerId' => $playerId,
                'needle' => json_encode([$playerId]),
            ],
        );
    }

    private function deletePlayerOwnedRows(string $playerId): void
    {
        $em = $this->entityManager;

        $em->createQuery('DELETE FROM ' . Stopwatch::class . ' s WHERE s.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . Notification::class . ' n WHERE n.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . DismissedHint::class . ' d WHERE d.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . DigestEmailLog::class . ' d WHERE d.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . WishListItem::class . ' w WHERE w.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . SellSwapListItem::class . ' s WHERE s.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . OAuth2ClientRequest::class . ' o WHERE o.player = :p')->setParameter('p', $playerId)->execute();

        $em->createQuery('DELETE FROM ' . CollectionItem::class . ' ci WHERE ci.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . Collection::class . ' c WHERE c.player = :p')->setParameter('p', $playerId)->execute();
        $em->createQuery('DELETE FROM ' . Badge::class . ' b WHERE b.player = :p')->setParameter('p', $playerId)->execute();
    }

    private function deleteUserBlocks(string $playerId): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM ' . UserBlock::class . ' ub WHERE ub.blocker = :p OR ub.blocked = :p',
        )->setParameter('p', $playerId)->execute();
    }

    private function anonymizeConversations(Player $player, string $playerName): void
    {
        $asInitiator = $this->entityManager->getRepository(Conversation::class)->findBy(['initiator' => $player]);

        foreach ($asInitiator as $conversation) {
            $conversation->anonymizeInitiator($playerName);
        }

        $asRecipient = $this->entityManager->getRepository(Conversation::class)->findBy(['recipient' => $player]);

        foreach ($asRecipient as $conversation) {
            $conversation->anonymizeRecipient($playerName);
        }
    }

    private function anonymizeChatMessages(Player $player, string $playerName): void
    {
        $messages = $this->entityManager->getRepository(ChatMessage::class)->findBy(['sender' => $player]);

        foreach ($messages as $message) {
            $message->anonymizeSender($playerName);
        }
    }

    private function anonymizeFeatureRequests(Player $player, string $playerName): void
    {
        $requests = $this->entityManager->getRepository(FeatureRequest::class)->findBy(['author' => $player]);

        foreach ($requests as $request) {
            $request->anonymizeAuthor($playerName);
        }
    }

    private function anonymizeFeatureRequestComments(Player $player, string $playerName): void
    {
        $comments = $this->entityManager->getRepository(FeatureRequestComment::class)->findBy(['author' => $player]);

        foreach ($comments as $comment) {
            $comment->anonymizeAuthor($playerName);
        }
    }

    private function anonymizeFeatureRequestVotes(string $playerId): void
    {
        $this->entityManager->createQuery(
            'UPDATE ' . FeatureRequestVote::class . ' v SET v.voter = NULL WHERE v.voter = :p',
        )->setParameter('p', $playerId)->execute();
    }

    private function anonymizeReportsAndModeration(string $playerId): void
    {
        $em = $this->entityManager;

        $em->createQuery('UPDATE ' . ConversationReport::class . ' r SET r.reporter = NULL WHERE r.reporter = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . ConversationReport::class . ' r SET r.resolvedBy = NULL WHERE r.resolvedBy = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . FeatureRequestCommentReport::class . ' r SET r.reporter = NULL WHERE r.reporter = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . FeatureRequestCommentReport::class . ' r SET r.resolvedBy = NULL WHERE r.resolvedBy = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . ModerationAction::class . ' m SET m.targetPlayer = NULL WHERE m.targetPlayer = :p')
            ->setParameter('p', $playerId)->execute();
    }

    private function anonymizeSoldSwappedItems(Player $player, string $playerName): void
    {
        $sellerSide = $this->entityManager->getRepository(SoldSwappedItem::class)->findBy(['seller' => $player]);

        foreach ($sellerSide as $item) {
            $item->anonymizeSeller($playerName);
        }

        $buyerSide = $this->entityManager->getRepository(SoldSwappedItem::class)->findBy(['buyerPlayer' => $player]);

        foreach ($buyerSide as $item) {
            $item->anonymizeBuyer($playerName);
        }
    }

    private function anonymizeTransactionRatings(Player $player, string $playerName): void
    {
        $reviewerSide = $this->entityManager->getRepository(TransactionRating::class)->findBy(['reviewer' => $player]);

        foreach ($reviewerSide as $rating) {
            $rating->anonymizeReviewer($playerName);
        }

        $reviewedSide = $this->entityManager->getRepository(TransactionRating::class)->findBy(['reviewedPlayer' => $player]);

        foreach ($reviewedSide as $rating) {
            $rating->anonymizeReviewedPlayer($playerName);
        }
    }

    private function anonymizeCompetitionSeries(string $playerId): void
    {
        $em = $this->entityManager;

        $em->createQuery('UPDATE ' . CompetitionSeries::class . ' s SET s.addedByPlayer = NULL WHERE s.addedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . CompetitionSeries::class . ' s SET s.approvedByPlayer = NULL WHERE s.approvedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . CompetitionSeries::class . ' s SET s.rejectedByPlayer = NULL WHERE s.rejectedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();

        $em->createQuery('UPDATE ' . Competition::class . ' c SET c.addedByPlayer = NULL WHERE c.addedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . Competition::class . ' c SET c.approvedByPlayer = NULL WHERE c.approvedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();
        $em->createQuery('UPDATE ' . Competition::class . ' c SET c.rejectedByPlayer = NULL WHERE c.rejectedByPlayer = :p')
            ->setParameter('p', $playerId)->execute();

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM competition_series_maintainer WHERE player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('DELETE FROM competition_maintainer WHERE player_id = :p', ['p' => $playerId]);
    }

    private function anonymizeBulkSimpleFks(string $playerId): void
    {
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement('UPDATE puzzle SET added_by_user_id = NULL WHERE added_by_user_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE manufacturer SET added_by_user_id = NULL WHERE added_by_user_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE competition_participant SET player_id = NULL WHERE player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE wjpc_participant SET player_id = NULL WHERE player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE lent_puzzle SET owner_player_id = NULL WHERE owner_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE lent_puzzle SET current_holder_player_id = NULL WHERE current_holder_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE lent_puzzle_transfer SET from_player_id = NULL WHERE from_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE lent_puzzle_transfer SET to_player_id = NULL WHERE to_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE lent_puzzle_transfer SET owner_player_id = NULL WHERE owner_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE sell_swap_list_item SET reserved_for_player_id = NULL WHERE reserved_for_player_id = :p', ['p' => $playerId]);
        $conn->executeStatement('UPDATE oauth2_client_request SET reviewed_by_id = NULL WHERE reviewed_by_id = :p', ['p' => $playerId]);
    }

    private function hashEmailAuditLog(Player $player): void
    {
        if ($player->email === null) {
            return;
        }

        $hash = hash('sha256', strtolower($player->email));

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE email_audit_log SET recipient_email = :hash WHERE LOWER(recipient_email) = :email',
            ['hash' => $hash, 'email' => strtolower($player->email)],
        );
    }
}
