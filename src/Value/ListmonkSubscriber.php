<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Snapshot of a subscriber as it exists in Listmonk, parsed from the API
 * response into the few fields the sync cares about.
 */
readonly final class ListmonkSubscriber
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        /** enabled | disabled | blocklisted */
        public string $status,
        /** @var array<int, string> list id -> subscription status (unconfirmed | confirmed | unsubscribed) */
        public array $listStatuses,
        /** @var array<string, mixed> */
        public array $attribs,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromApi(array $data): null|self
    {
        $id = $data['id'] ?? null;
        $email = $data['email'] ?? null;

        if (!is_numeric($id) || !is_string($email) || $email === '') {
            return null;
        }

        $listStatuses = [];
        $lists = $data['lists'] ?? null;

        if (is_array($lists)) {
            foreach ($lists as $list) {
                if (!is_array($list)) {
                    continue;
                }

                $listId = $list['id'] ?? null;
                $subscriptionStatus = $list['subscription_status'] ?? null;

                if (is_numeric($listId) && is_string($subscriptionStatus)) {
                    $listStatuses[(int) $listId] = $subscriptionStatus;
                }
            }
        }

        $attribs = $data['attribs'] ?? null;
        $name = $data['name'] ?? null;
        $status = $data['status'] ?? null;

        /** @var array<string, mixed> $attribsArray */
        $attribsArray = is_array($attribs) ? $attribs : [];

        return new self(
            id: (int) $id,
            email: mb_strtolower(trim($email)),
            name: is_string($name) ? $name : '',
            status: is_string($status) ? $status : 'enabled',
            listStatuses: $listStatuses,
            attribs: $attribsArray,
        );
    }

    public function isBlocklisted(): bool
    {
        return $this->status === 'blocklisted';
    }

    /**
     * @param list<int> $newsletterListIds
     * @return list<int>
     */
    public function newsletterListIds(array $newsletterListIds): array
    {
        return array_values(array_intersect(array_keys($this->listStatuses), $newsletterListIds));
    }

    /**
     * @param list<int> $newsletterListIds
     * @return list<int>
     */
    public function foreignListIds(array $newsletterListIds): array
    {
        return array_values(array_diff(array_keys($this->listStatuses), $newsletterListIds));
    }

    /**
     * @param list<int> $newsletterListIds
     */
    public function isUnsubscribedFromAnyNewsletterList(array $newsletterListIds): bool
    {
        foreach ($this->newsletterListIds($newsletterListIds) as $listId) {
            if ($this->listStatuses[$listId] === 'unsubscribed') {
                return true;
            }
        }

        return false;
    }
}
