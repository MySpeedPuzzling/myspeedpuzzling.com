<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PartnerCardData;
use SpeedPuzzling\Web\Results\PartnerCardFace;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\HintType;
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * The partner card and the "Find a partner (N)" button of an event or edition page for a signed-in viewer
 * (docs/features/partner-finder.md "Part B") - ONE statement: the open listings count, up to four faces, the viewer's
 * own open listing with the requests it received, whether the viewer is in a pair/team entry and whether they closed
 * the card (HintType::PartnerFinderCard). Null when the event has no partner finder (PartnerFinderEligibility, drafts
 * and every other non-public event included). Guests never run it - they get a plain link.
 *
 * Privacy: the count is an aggregate (like "N going") and stays unfiltered. The faces leave out the players the viewer
 * has hidden (HiddenPlayers, inside the ranked subquery, before the LIMIT) and private players hidden from the viewer
 * (PrivateProfileAccess) - their listing is counted, they get no face. The requests count leaves out hidden senders.
 *
 * @phpstan-import-type PartnerCardFaceJsonRow from PartnerCardFace
 */
readonly final class GetPartnerCardData
{
    public const int FACES = 4;

    public function __construct(
        private Connection $database,
        private PartnerFinderEligibility $eligibility,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    public function forViewer(string $competitionId, string $viewerPlayerId): null|PartnerCardData
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($viewerPlayerId) === false) {
            return null;
        }

        $qualifies = PartnerFinderEligibility::SQL_QUALIFIES;
        $entered = PartnerFinderEligibility::sqlPlayerEntered('c.id', ':viewerId');
        $hiddenFaces = $this->hiddenPlayers->sqlExclude('face_player.id');
        $hiddenSenders = $this->hiddenPlayers->sqlExclude('own_request.from_player_id');
        $privateFace = $this->privateProfileAccess->sqlIsPrivate('face_player');
        $faces = self::FACES;

        // Faces: the open listings of the event (partial index custom_partner_listing_open_by_event), newest first, cut
        // to FACES after the blocks and the privacy rule - only then a primary-key lookup per face
        $query = <<<SQL
SELECT
    (
        SELECT COUNT(*)
        FROM partner_listing open_listing
        WHERE open_listing.competition_id = c.id
            AND open_listing.closed_at IS NULL
    ) AS listing_count,
    (
        SELECT json_agg(json_build_object(
            'id', face.player_id,
            'name', face.name,
            'code', face.code,
            'avatar', face.avatar,
            'country', face.country
        ) ORDER BY face.created_at DESC, face.listing_id DESC)
        FROM (
            SELECT face_listing.id AS listing_id,
                face_listing.created_at,
                face_player.id AS player_id,
                face_player.name,
                face_player.code,
                face_player.avatar,
                face_player.country
            FROM partner_listing face_listing
            JOIN player face_player ON face_player.id = face_listing.player_id
            WHERE face_listing.competition_id = c.id
                AND face_listing.closed_at IS NULL
                AND face_listing.player_id <> :viewerId
                AND NOT {$privateFace}{$hiddenFaces}
            ORDER BY face_listing.created_at DESC, face_listing.id DESC
            LIMIT {$faces}
        ) face
    ) AS faces,
    (
        SELECT json_build_object(
            'id', own_listing.id,
            'looking_for', own_listing.looking_for,
            'group_size', own_listing.group_size,
            'needed', own_listing.needed,
            'requests', (
                SELECT COUNT(*)
                FROM partner_request own_request
                WHERE own_request.listing_id = own_listing.id{$hiddenSenders}
            )
        )
        FROM partner_listing own_listing
        WHERE own_listing.competition_id = c.id
            AND own_listing.player_id = :viewerId
            AND own_listing.closed_at IS NULL
        LIMIT 1
    ) AS own_listing,
    {$entered} AS viewer_entered,
    EXISTS (
        SELECT 1
        FROM dismissed_hint card_hint
        WHERE card_hint.player_id = :viewerId
            AND card_hint.type = :cardHint
    ) AS card_dismissed
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
    AND {$qualifies}
SQL;

        /**
         * @var false|array{
         *     listing_count: int|string,
         *     faces: null|string,
         *     own_listing: null|string,
         *     viewer_entered: bool,
         *     card_dismissed: bool,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'viewerId' => $viewerPlayerId,
                'cardHint' => HintType::PartnerFinderCard->value,
                ...$this->eligibility->todayParameter(),
            ])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $faceRows = [];

        if ($row['faces'] !== null) {
            /** @var list<PartnerCardFaceJsonRow> $faceRows */
            $faceRows = json_decode($row['faces'], true, flags: JSON_THROW_ON_ERROR);
        }

        $own = null;

        if ($row['own_listing'] !== null) {
            /** @var array{id: string, looking_for: list<string>, group_size: int, needed: null|int, requests: int} $own */
            $own = json_decode($row['own_listing'], true, flags: JSON_THROW_ON_ERROR);
        }

        return new PartnerCardData(
            listingCount: (int) $row['listing_count'],
            faces: array_map(
                static fn (array $faceRow): PartnerCardFace => PartnerCardFace::fromJsonRow($faceRow),
                $faceRows,
            ),
            ownListingId: $own['id'] ?? null,
            ownLookingFor: $own !== null ? array_values(array_filter(array_map(PartnerKind::tryFrom(...), $own['looking_for']))) : [],
            ownGroupSize: $own['group_size'] ?? null,
            ownNeeded: $own['needed'] ?? null,
            ownRequestsCount: $own['requests'] ?? 0,
            viewerEntered: (bool) $row['viewer_entered'],
            cardDismissed: (bool) $row['card_dismissed'],
        );
    }
}
