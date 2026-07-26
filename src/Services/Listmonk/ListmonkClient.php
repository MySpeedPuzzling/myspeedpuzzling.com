<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Listmonk;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\ListmonkRequestFailed;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the Listmonk REST API. The integration is
 * closed-by-default: with an empty LISTMONK_API_TOKEN env var isEnabled()
 * returns false and callers must skip their work.
 */
readonly class ListmonkClient
{
    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $listmonkApiUrl,
        private string $listmonkApiUser,
        private string $listmonkApiToken,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->listmonkApiUrl !== '' && $this->listmonkApiUser !== '' && $this->listmonkApiToken !== '';
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws ListmonkRequestFailed
     */
    public function getLists(): array
    {
        $data = $this->request('GET', '/api/lists', ['query' => ['per_page' => 'all']]);
        $results = $this->extractData($data)['results'] ?? null;

        return $this->listOfArrays($results);
    }

    /**
     * @param list<string> $tags
     * @return array<mixed>
     *
     * @throws ListmonkRequestFailed
     */
    public function createList(string $name, string $type, string $optin, array $tags): array
    {
        $data = $this->request('POST', '/api/lists', [
            'json' => [
                'name' => $name,
                'type' => $type,
                'optin' => $optin,
                'tags' => $tags,
            ],
        ]);

        return $this->extractData($data);
    }

    /**
     * @return array{results: list<array<mixed>>, total: int}
     *
     * @throws ListmonkRequestFailed
     */
    public function getSubscribersPage(int $page, int $perPage): array
    {
        $data = $this->request('GET', '/api/subscribers', [
            'query' => [
                'page' => (string) $page,
                'per_page' => (string) $perPage,
                'order_by' => 'id',
                'order' => 'ASC',
            ],
        ]);

        $payload = $this->extractData($data);
        $total = $payload['total'] ?? 0;

        return [
            'results' => $this->listOfArrays($payload['results'] ?? null),
            'total' => is_numeric($total) ? (int) $total : 0,
        ];
    }

    /**
     * @return null|array<mixed>
     *
     * @throws ListmonkRequestFailed
     */
    public function findSubscriberByEmail(string $email): null|array
    {
        $escaped = str_replace("'", "''", mb_strtolower(trim($email)));

        $data = $this->request('GET', '/api/subscribers', [
            'query' => [
                'query' => sprintf("LOWER(subscribers.email) = '%s'", $escaped),
                'page' => '1',
                'per_page' => '1',
            ],
        ]);

        $results = $this->listOfArrays($this->extractData($data)['results'] ?? null);

        return $results[0] ?? null;
    }

    /**
     * @param list<int> $listIds
     * @param array<string, string> $attribs
     * @return array<mixed>
     *
     * @throws ListmonkRequestFailed
     */
    public function createSubscriber(string $email, string $name, array $listIds, array $attribs): array
    {
        $data = $this->request('POST', '/api/subscribers', [
            'json' => [
                'email' => $email,
                'name' => $name,
                'status' => 'enabled',
                'lists' => $listIds,
                'attribs' => (object) $attribs,
                'preconfirm_subscriptions' => true,
            ],
        ]);

        return $this->extractData($data);
    }

    /**
     * @param list<int> $listIds
     * @param array<string, string> $attribs
     *
     * @throws ListmonkRequestFailed
     */
    public function updateSubscriber(int $subscriberId, string $email, string $name, array $listIds, array $attribs): void
    {
        $this->request('PUT', sprintf('/api/subscribers/%d', $subscriberId), [
            'json' => [
                'email' => $email,
                'name' => $name,
                'status' => 'enabled',
                'lists' => $listIds,
                'attribs' => (object) $attribs,
                'preconfirm_subscriptions' => true,
            ],
        ]);
    }

    /**
     * @param list<int> $subscriberIds
     * @param list<int> $listIds
     *
     * @throws ListmonkRequestFailed
     */
    public function unsubscribeFromLists(array $subscriberIds, array $listIds): void
    {
        if ($subscriberIds === [] || $listIds === []) {
            return;
        }

        $this->request('PUT', '/api/subscribers/lists', [
            'json' => [
                'ids' => $subscriberIds,
                'action' => 'unsubscribe',
                'target_list_ids' => $listIds,
            ],
        ]);
    }

    /**
     * @param list<int> $subscriberIds
     * @param list<int> $listIds
     *
     * @throws ListmonkRequestFailed
     */
    public function confirmListSubscriptions(array $subscriberIds, array $listIds): void
    {
        if ($subscriberIds === [] || $listIds === []) {
            return;
        }

        $this->request('PUT', '/api/subscribers/lists', [
            'json' => [
                'ids' => $subscriberIds,
                'action' => 'add',
                'target_list_ids' => $listIds,
                'status' => 'confirmed',
            ],
        ]);
    }

    /**
     * @param list<int> $subscriberIds
     * @param list<int> $listIds
     *
     * @throws ListmonkRequestFailed
     */
    public function removeFromLists(array $subscriberIds, array $listIds): void
    {
        if ($subscriberIds === [] || $listIds === []) {
            return;
        }

        $this->request('PUT', '/api/subscribers/lists', [
            'json' => [
                'ids' => $subscriberIds,
                'action' => 'remove',
                'target_list_ids' => $listIds,
            ],
        ]);
    }

    /**
     * @throws ListmonkRequestFailed
     */
    public function deleteSubscriber(int $subscriberId): void
    {
        $this->request('DELETE', sprintf('/api/subscribers/%d', $subscriberId));
    }

    /**
     * Bulk CSV import - used for large initial imports instead of thousands of
     * single-subscriber API calls. Listmonk processes the import in the
     * background; poll getImportStatus() until it finishes.
     *
     * @param list<int> $listIds
     *
     * @throws ListmonkRequestFailed
     */
    public function importSubscribers(string $csvContent, array $listIds, bool $markConfirmed): void
    {
        $params = json_encode([
            'mode' => 'subscribe',
            'delim' => ',',
            'lists' => $listIds,
            'overwrite' => false,
            'subscription_status' => $markConfirmed ? 'confirmed' : 'unconfirmed',
        ], JSON_THROW_ON_ERROR);

        $formData = new FormDataPart([
            'params' => $params,
            'file' => new DataPart($csvContent, 'subscribers.csv', 'text/csv'),
        ]);

        $this->request('POST', '/api/import/subscribers', [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
        ]);
    }

    /**
     * @return array<mixed>
     *
     * @throws ListmonkRequestFailed
     */
    public function getImportStatus(): array
    {
        return $this->extractData($this->request('GET', '/api/import/subscribers'));
    }

    /**
     * @throws ListmonkRequestFailed
     */
    public function stopImport(): void
    {
        $this->request('DELETE', '/api/import/subscribers');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     *
     * @throws ListmonkRequestFailed
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = rtrim($this->listmonkApiUrl, '/') . $path;

        $headers = $options['headers'] ?? [];

        if (!is_array($headers)) {
            $headers = [];
        }

        $headers['Authorization'] = sprintf('token %s:%s', $this->listmonkApiUser, $this->listmonkApiToken);
        $options['headers'] = $headers;

        try {
            $response = $this->client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(throw: false);
        } catch (TransportException | ExceptionInterface $e) {
            $this->logger->error('Listmonk API request failed on transport level', [
                'method' => $method,
                'path' => $path,
                'exception' => $e,
            ]);

            throw new ListmonkRequestFailed(sprintf('Listmonk request %s %s failed: %s', $method, $path, $e->getMessage()), previous: $e);
        }

        if ($statusCode >= 400) {
            $this->logger->error('Listmonk API request returned an error status', [
                'method' => $method,
                'path' => $path,
                'status_code' => $statusCode,
                'response_excerpt' => mb_substr($content, 0, 500),
            ]);

            throw new ListmonkRequestFailed(sprintf('Listmonk request %s %s returned HTTP %d', $method, $path, $statusCode));
        }

        if ($content === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ListmonkRequestFailed(sprintf('Listmonk request %s %s returned invalid JSON', $method, $path), previous: $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $response
     * @return array<mixed>
     */
    private function extractData(array $response): array
    {
        $data = $response['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
