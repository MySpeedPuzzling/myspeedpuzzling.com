<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who filed a puzzle change request (puzzle_change_request.source). Stored - never rename a value.
 * A row without one (null) was filed by a player through the release before the column existed.
 */
enum PuzzleChangeRequestSource: string
{
    // A player's "Suggest a change" or multiscan's EAN linking
    case Player = 'player';
    // Filed through POST /internal-api/puzzle-change-requests (an analysis, Claude Code) - the reviewer player is the reporter
    case InternalApi = 'internal_api';
    // The image pipeline (docs/features/insights/README.md "Artwork crops"): carries tags, confidence, model version and
    // the fingerprint of the picture it was made from - one proposal per (puzzle, picture, tag), ever
    case ImagePipeline = 'image_pipeline';
}
