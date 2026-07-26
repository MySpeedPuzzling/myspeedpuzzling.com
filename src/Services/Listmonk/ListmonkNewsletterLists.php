<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Listmonk;

use SpeedPuzzling\Web\Exceptions\ListmonkRequestFailed;

/**
 * The per-locale newsletter lists in Listmonk, mirroring the website locales
 * 1:1. Lists are looked up by their exact name and created when missing, so no
 * ids need to be configured anywhere.
 */
readonly final class ListmonkNewsletterLists
{
    /** @var list<string> */
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    public const string DEFAULT_LOCALE = 'en';

    public function __construct(
        private ListmonkClient $listmonkClient,
    ) {
    }

    public static function normalizeLocale(null|string $locale): string
    {
        if ($locale !== null && in_array($locale, self::LOCALES, true)) {
            return $locale;
        }

        return self::DEFAULT_LOCALE;
    }

    public static function listName(string $locale): string
    {
        return 'Newsletter ' . strtoupper($locale);
    }

    /**
     * Returns the locale => list id map, creating any missing list. Lists are
     * private (not offered on Listmonk's public pages - the website footer form
     * is the only public entry) and single opt-in, because MySpeedPuzzling
     * handles the double opt-in itself and always syncs with preconfirmed
     * subscriptions.
     *
     * @return array<string, int>
     *
     * @throws ListmonkRequestFailed
     */
    public function ensureListsExist(): array
    {
        $existingByName = [];

        foreach ($this->listmonkClient->getLists() as $list) {
            $name = $list['name'] ?? null;
            $id = $list['id'] ?? null;

            if (is_string($name) && is_numeric($id)) {
                $existingByName[$name] = (int) $id;
            }
        }

        $listIdByLocale = [];

        foreach (self::LOCALES as $locale) {
            $name = self::listName($locale);

            if (isset($existingByName[$name])) {
                $listIdByLocale[$locale] = $existingByName[$name];
                continue;
            }

            $created = $this->listmonkClient->createList($name, type: 'private', optin: 'single', tags: ['newsletter']);
            $createdId = $created['id'] ?? null;

            if (!is_numeric($createdId)) {
                throw new ListmonkRequestFailed(sprintf('Creating Listmonk list "%s" did not return an id', $name));
            }

            $listIdByLocale[$locale] = (int) $createdId;
        }

        return $listIdByLocale;
    }
}
