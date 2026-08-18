<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\ListmonkRequestFailed;
use SpeedPuzzling\Web\Exceptions\NewsletterSubscriberNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SyncNewsletterSubscribers;
use SpeedPuzzling\Web\Query\GetNewsletterRecipients;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkClient;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\Listmonk\NewsletterAttributesBuilder;
use SpeedPuzzling\Web\Services\Listmonk\NewsletterSyncPlanner;
use SpeedPuzzling\Web\Value\DesiredNewsletterSubscriber;
use SpeedPuzzling\Web\Value\ListmonkSubscriber;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bidirectional reconciliation between MySpeedPuzzling (source of truth) and
 * Listmonk (mirror + send engine), run from cron every 15 minutes:
 *
 * - PULL: unsubscribes recorded only in Listmonk (RFC 8058 one-click header,
 *   archive page) are applied to MySpeedPuzzling.
 * - PUSH: new/changed recipients are created/updated, MySpeedPuzzling
 *   unsubscribes are marked unsubscribed in Listmonk, and addresses that no
 *   longer exist here (deleted players) are removed from Listmonk.
 *
 * Large batches of new subscribers (the initial import) go through Listmonk's
 * CSV import endpoint instead of thousands of single API calls.
 */
#[AsMessageHandler]
final class SyncNewsletterSubscribersHandler
{
    private const int SUBSCRIBERS_PER_PAGE = 500;
    private const int MAX_PAGES = 400;
    private const int BULK_IMPORT_THRESHOLD = 50;
    private const int IMPORT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly ListmonkClient $listmonkClient,
        private readonly ListmonkNewsletterLists $newsletterLists,
        private readonly NewsletterAttributesBuilder $attributesBuilder,
        private readonly NewsletterSyncPlanner $planner,
        private readonly GetNewsletterRecipients $getNewsletterRecipients,
        private readonly PlayerRepository $playerRepository,
        private readonly NewsletterSubscriberRepository $newsletterSubscriberRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ListmonkRequestFailed
     */
    public function __invoke(SyncNewsletterSubscribers $message): void
    {
        if ($this->listmonkClient->isEnabled() === false) {
            $this->logger->info('Listmonk integration is disabled, skipping newsletter sync');

            return;
        }

        $listIdByLocale = $this->newsletterLists->ensureListsExist();

        $actual = $this->fetchAllListmonkSubscribers();

        $desired = [];

        foreach ($this->getNewsletterRecipients->all() as $recipient) {
            $desired[] = new DesiredNewsletterSubscriber($recipient, $this->attributesBuilder->build($recipient));
        }

        $plan = $this->planner->plan($desired, $actual, $listIdByLocale);

        $this->applyPullUnsubscribes($plan->pullUnsubscribes);
        $this->applyCreates($plan->creates, $listIdByLocale);

        foreach ($plan->updates as $update) {
            $this->listmonkClient->updateSubscriber(
                $update->listmonkId,
                $update->desired->recipient->email,
                $update->desired->recipient->name,
                $update->targetListIds,
                $update->desired->attributes,
            );
        }

        foreach ($plan->listUnsubscribes as $listUnsubscribe) {
            $this->listmonkClient->unsubscribeFromLists([$listUnsubscribe->listmonkId], $listUnsubscribe->listIds);
        }

        foreach ($plan->deletions as $deletion) {
            if ($deletion->fullDelete) {
                $this->listmonkClient->deleteSubscriber($deletion->listmonkId);
            } else {
                $this->listmonkClient->removeFromLists([$deletion->listmonkId], $deletion->removeFromListIds);
            }
        }

        $this->logger->info('Newsletter Listmonk sync finished', [
            'desired_recipients' => count($desired),
            'listmonk_subscribers' => count($actual),
            'pulled_unsubscribes' => count($plan->pullUnsubscribes),
            'created' => count($plan->creates),
            'updated' => count($plan->updates),
            'unsubscribed_in_listmonk' => count($plan->listUnsubscribes),
            'deleted' => count($plan->deletions),
        ]);
    }

    /**
     * @return list<ListmonkSubscriber>
     *
     * @throws ListmonkRequestFailed
     */
    private function fetchAllListmonkSubscribers(): array
    {
        $subscribers = [];
        $page = 1;

        do {
            $result = $this->listmonkClient->getSubscribersPage($page, self::SUBSCRIBERS_PER_PAGE);

            foreach ($result['results'] as $data) {
                $subscriber = ListmonkSubscriber::fromApi($data);

                if ($subscriber !== null) {
                    $subscribers[] = $subscriber;
                }
            }

            $fetchedEverything = count($result['results']) < self::SUBSCRIBERS_PER_PAGE
                || count($subscribers) >= $result['total'];

            $page++;
        } while ($fetchedEverything === false && $page <= self::MAX_PAGES);

        return $subscribers;
    }

    /**
     * @param list<\SpeedPuzzling\Web\Results\NewsletterRecipient> $pullUnsubscribes
     */
    private function applyPullUnsubscribes(array $pullUnsubscribes): void
    {
        foreach ($pullUnsubscribes as $recipient) {
            try {
                if ($recipient->audience === NewsletterAudience::Player) {
                    $this->playerRepository->get($recipient->id)->changeNewsletterEnabled(false);
                } else {
                    $this->newsletterSubscriberRepository->get($recipient->id)->unsubscribe($this->clock->now());
                }
            } catch (PlayerNotFound | NewsletterSubscriberNotFound) {
                // Deleted between querying and applying - next run cleans Listmonk up
            }
        }
    }

    /**
     * @param list<DesiredNewsletterSubscriber> $creates
     * @param array<string, int> $listIdByLocale
     *
     * @throws ListmonkRequestFailed
     */
    private function applyCreates(array $creates, array $listIdByLocale): void
    {
        if (count($creates) > self::BULK_IMPORT_THRESHOLD) {
            $this->bulkImport($creates, $listIdByLocale);

            return;
        }

        foreach ($creates as $create) {
            $targetListId = $this->planner->targetListId($listIdByLocale, $create->recipient->locale);

            $this->listmonkClient->createSubscriber(
                $create->recipient->email,
                $create->recipient->name,
                [$targetListId],
                $create->attributes,
            );
        }
    }

    /**
     * @param list<DesiredNewsletterSubscriber> $creates
     * @param array<string, int> $listIdByLocale
     *
     * @throws ListmonkRequestFailed
     */
    private function bulkImport(array $creates, array $listIdByLocale): void
    {
        $createsByListId = [];

        foreach ($creates as $create) {
            $createsByListId[$this->planner->targetListId($listIdByLocale, $create->recipient->locale)][] = $create;
        }

        foreach ($createsByListId as $listId => $listCreates) {
            $rows = ['email,name,attributes'];

            foreach ($listCreates as $create) {
                $rows[] = implode(',', [
                    self::csvField($create->recipient->email),
                    self::csvField($create->recipient->name),
                    self::csvField(json_encode($create->attributes, JSON_THROW_ON_ERROR)),
                ]);
            }

            $this->logger->info('Bulk-importing newsletter subscribers into Listmonk', [
                'list_id' => $listId,
                'subscribers' => count($listCreates),
            ]);

            $this->listmonkClient->importSubscribers(implode("\n", $rows), [$listId], markConfirmed: true);
            $this->waitForImportToFinish();
        }
    }

    /**
     * @throws ListmonkRequestFailed
     */
    private function waitForImportToFinish(): void
    {
        $waited = 0;

        while ($waited < self::IMPORT_TIMEOUT_SECONDS) {
            $status = $this->listmonkClient->getImportStatus();
            $state = $status['status'] ?? null;

            if ($state === 'finished') {
                $this->listmonkClient->stopImport();

                return;
            }

            if ($state === 'failed' || $state === 'stopped') {
                $this->listmonkClient->stopImport();

                throw new ListmonkRequestFailed('Listmonk subscriber import failed');
            }

            sleep(1);
            $waited++;
        }

        $this->listmonkClient->stopImport();

        throw new ListmonkRequestFailed('Listmonk subscriber import timed out');
    }

    private static function csvField(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
