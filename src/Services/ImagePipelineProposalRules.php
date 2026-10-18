<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleImageAlreadyProposed;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * What a change proposal filed through the internal API must meet besides its values (docs/features/internal-api.md
 * "Puzzle change requests"), checked by the controller to answer early and by SubmitPuzzleChangeRequestHandler under
 * the puzzle's lock - the one that counts:
 * - a puzzle whose picture is hidden (hide_until / hide_image_until in the future) is unknown to the image pipeline and
 *   to an image proposal of the internal API - `404`, like the image analysis API;
 * - `sourceImageFingerprint` must name the puzzle's picture now - `409 image_changed`;
 * - the image pipeline proposes a picture once per tag, ever: a request of the same puzzle, fingerprint and tag in any
 *   status - rejected ones included - refuses it (`409 already_proposed`).
 */
readonly final class ImagePipelineProposalRules
{
    public function __construct(
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $tags
     *
     * @throws PuzzleNotFound
     * @throws PuzzleImageChangedMeanwhile
     * @throws PuzzleImageAlreadyProposed
     */
    public function check(
        Puzzle $puzzle,
        PuzzleChangeRequestSource $source,
        null|string $sourceImageFingerprint,
        array $tags,
        bool $proposesImage,
    ): void {
        // A player's proposal is gated by the web form (SecretPuzzleAccess - an organiser may suggest a photo of a secret
        // puzzle), only the internal API's are refused here
        $fromTheApi = $source !== PuzzleChangeRequestSource::Player;

        if ($fromTheApi && ($source === PuzzleChangeRequestSource::ImagePipeline || $proposesImage) && $puzzle->isImageHiddenAt($this->clock->now())) {
            throw new PuzzleNotFound('No such puzzle with an image to propose for.');
        }

        if ($sourceImageFingerprint === null) {
            return;
        }

        $currentFingerprint = $puzzle->image !== null ? PuzzleImageFingerprint::of($puzzle->image) : null;

        if ($currentFingerprint !== $sourceImageFingerprint) {
            throw new PuzzleImageChangedMeanwhile($currentFingerprint);
        }

        if ($source !== PuzzleChangeRequestSource::ImagePipeline) {
            return;
        }

        foreach ($this->puzzleChangeRequestRepository->findImagePipelineProposals($puzzle, $sourceImageFingerprint) as $proposal) {
            foreach ($tags as $tag) {
                if (in_array($tag, $proposal->tags ?? [], true)) {
                    throw new PuzzleImageAlreadyProposed($proposal->id->toString(), $tag);
                }
            }
        }
    }
}
