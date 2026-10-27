<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AskForListings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The listings a buyer ticked on somebody's sell/swap list or on the marketplace (docs/features/marketplace/12-listing-requests.md).
 * Read from the POST body (`listingIds[]`), never the URL. More than one send may hold is cut - the sheet says so.
 */
readonly final class ListingSelection
{
    public const string CSRF_TOKEN_ID = 'listing_selection';

    /**
     * @param list<string> $listingIds
     */
    private function __construct(
        public array $listingIds,
        public bool $truncated,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $listingIds = [];

        foreach ($request->request->all('listingIds') as $listingId) {
            if (is_string($listingId) && Uuid::isValid($listingId)) {
                $listingIds[strtolower($listingId)] = true;
            }
        }

        $listingIds = array_keys($listingIds);

        return new self(
            array_slice($listingIds, 0, AskForListings::MAX_LISTINGS),
            count($listingIds) > AskForListings::MAX_LISTINGS,
        );
    }
}
