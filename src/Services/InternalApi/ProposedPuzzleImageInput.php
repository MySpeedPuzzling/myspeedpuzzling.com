<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\InternalApi;

use RuntimeException;
use SpeedPuzzling\Web\Exceptions\InvalidProposedPuzzleImage;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The `image` of a change proposal filed through the internal API (docs/features/internal-api.md "Puzzle change
 * requests"), there being no file upload in a JSON API: base64 of a JPEG, PNG or WebP (a `data:image/…;base64,` prefix
 * is allowed), at most 15 MB decoded. The bytes become a temporary file handed on as an upload, so the web form's
 * proposed photo rule (the constraints of ProposePuzzleChangesFormData::$photo) checks it and
 * SubmitPuzzleChangeRequestHandler stores it the same way - optimized, `proposal-<id>.<ext>`, its ratio kept.
 */
final readonly class ProposedPuzzleImageInput
{
    public const int MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * A temporary file - the caller removes it (remove()) once the message is handled.
     *
     * @throws InvalidProposedPuzzleImage
     */
    public function decode(mixed $value): UploadedFile
    {
        if (is_string($value) === false || trim($value) === '') {
            throw new InvalidProposedPuzzleImage(['image' => 'must be a base64-encoded JPEG, PNG or WebP image.']);
        }

        $base64 = preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', trim($value)) ?? $value;

        // Checked before decoding: 4 base64 characters carry 3 bytes
        if (strlen($base64) > (int) ceil(self::MAX_BYTES / 3) * 4) {
            throw new InvalidProposedPuzzleImage(['image' => 'can be at most 15 MB.']);
        }

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidProposedPuzzleImage(['image' => 'must be a base64-encoded JPEG, PNG or WebP image.']);
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidProposedPuzzleImage(['image' => 'can be at most 15 MB.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'puzzle-proposal-');

        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Could not write the proposed image into a temporary file.');
        }

        $file = new UploadedFile($path, 'proposal', null, UPLOAD_ERR_OK, test: true);

        // The web form's rule for a proposed photo, the very constraints of its field
        $violations = $this->validator->validatePropertyValue(ProposePuzzleChangesFormData::class, 'photo', $file);

        if (count($violations) > 0) {
            self::remove($file);

            throw new InvalidProposedPuzzleImage(['image' => (string) $violations->get(0)->getMessage()]);
        }

        return $file;
    }

    public static function remove(null|UploadedFile $file): void
    {
        if ($file !== null && is_file($file->getPathname())) {
            unlink($file->getPathname());
        }
    }
}
