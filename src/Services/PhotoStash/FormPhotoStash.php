<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use SpeedPuzzling\Web\Services\BrowserImageSummary;
use SpeedPuzzling\Web\Value\StashedPhoto;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * PhotoStash for the forms with a photo upload (add/edit time, moderators' puzzle forms, "Suggest a change", a round's
 * new puzzle, an event's / series' logo): a refused submit keeps the photos, the next submit gets them back.
 *
 * 1. restore() before handleRequest(): an empty file input with a kept-photo token gets the photo back, so
 *    the form validates it like a fresh upload. A newly chosen file always wins over the token.
 * 2. keep() when the form comes back with an error: every photo that passed its own validation is kept.
 * 3. forget() after a successful save - except the add/edit-time form: a resend of it (the answer got lost, the automatic
 *    retry) must find its photos again to be answered as the saved result, so they stay until they expire
 *    (docs/features/photo-uploads/README.md).
 *
 * What the browser read from a photo before it shrank it (docs/features/upload-image-metadata.md "Read in the browser")
 * comes as `photo_metadata[<field>]` with the submit, or - for a photo that arrives as a token - from the stash, where
 * the upload's start or keep() put it: browserMetadata().
 *
 * The add/edit-time form's photos usually arrive as tokens only: the browser uploaded them in the background
 * (DirectPhotoUpload) and restore() treats them exactly like kept ones.
 */
readonly final class FormPhotoStash
{
    public const string REQUEST_KEY = 'photo_stash';
    // `photo_metadata[<field>]`: the browser's summary of the photo it sends (assets/photo_metadata.js), JSON
    public const string METADATA_REQUEST_KEY = 'photo_metadata';
    // The file fields of every form using it - "photo" is the one of "Suggest a change", "logo" the event/series forms'
    public const array FIELDS = ['puzzlePhoto', 'finishedPuzzlesPhoto', 'photo', 'logo'];

    public function __construct(
        private PhotoStash $photoStash,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @return array<string, null|string> field => token of the photo put back; null = its token does not work any more
     */
    public function restore(Request $request, FormInterface $form, string $playerId): array
    {
        if ($request->isMethod('POST') === false) {
            return [];
        }

        $tokens = $request->request->all(self::REQUEST_KEY);
        $files = $request->files->all($form->getName());
        $restored = [];

        foreach (self::FIELDS as $field) {
            $token = $tokens[$field] ?? null;

            if ($form->has($field) === false || is_string($token) === false || $token === '') {
                continue;
            }

            if (($files[$field] ?? null) instanceof UploadedFile) {
                continue;
            }

            $file = $this->photoStash->restore($token, $playerId);
            $restored[$field] = $file !== null ? $token : null;

            if ($file !== null) {
                $files[$field] = $file;
            }
        }

        if ($restored !== []) {
            $request->files->set($form->getName(), $files);
        }

        return $restored;
    }

    /**
     * What the browser read from each field's photo before shrinking it (BrowserImageSummary): the summary sent with
     * this submit, else the one kept with the photo's token. Only fields with a valid summary are listed - the handler
     * stores it when the photo that arrived has no EXIF of its own (UploadedImageMetadata::preferring()).
     *
     * @param array<string, null|string> $restored what restore() answered
     * @return array<string, UploadedImageMetadata>
     */
    public function browserMetadata(Request $request, array $restored, string $playerId): array
    {
        if ($request->isMethod('POST') === false) {
            return [];
        }

        $sent = $request->request->all()[self::METADATA_REQUEST_KEY] ?? [];
        $sent = is_array($sent) ? $sent : [];
        $metadata = [];

        foreach (self::FIELDS as $field) {
            $summary = is_string($sent[$field] ?? null) ? BrowserImageSummary::fromJson($sent[$field]) : null;
            $token = $restored[$field] ?? null;
            $summary ??= $token !== null ? $this->photoStash->browserMetadata($token, $playerId) : null;

            if ($summary !== null) {
                $metadata[$field] = $summary;
            }
        }

        return $metadata;
    }

    /**
     * A kept photo that could not be put back is said out loud - saving without it silently would lose it.
     *
     * @template T
     * @param FormInterface<T> $form
     * @param array<string, null|string> $restored
     */
    public function reportLost(FormInterface $form, array $restored): void
    {
        foreach ($restored as $field => $token) {
            if ($token === null && $form->isSubmitted() && $form->get($field)->getData() === null) {
                $form->get($field)->addError(new FormError($this->translator->trans('photo_stash.lost')));
            }
        }
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @param array<string, null|string> $restored
     * @param array<string, UploadedImageMetadata> $browserMetadata browserMetadata() - kept with the photo, so a token
     *        brings it back
     * @return array<string, StashedPhoto>
     */
    public function keep(FormInterface $form, array $restored, string $playerId, array $browserMetadata = []): array
    {
        if ($form->isSubmitted() === false) {
            return [];
        }

        $kept = [];

        foreach (self::FIELDS as $field) {
            if ($form->has($field) === false) {
                continue;
            }

            $child = $form->get($field);
            $file = $child->getData();

            // A photo that failed its own validation is not kept - the player picks another one
            if ($file instanceof UploadedFile === false || count($child->getErrors()) > 0) {
                continue;
            }

            $token = $restored[$field] ?? null;
            // A photo uploaded straight from the browser (DirectPhotoUpload) gets its preview now, from the restored file
            $photo = $token !== null
                ? $this->photoStash->addPreview($token, $playerId, $file, $browserMetadata[$field] ?? null)
                : $this->photoStash->keep($file, $playerId, $browserMetadata[$field] ?? null);

            if ($photo !== null) {
                $kept[$field] = $photo;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, null|string> $restored
     */
    public function forget(array $restored, string $playerId): void
    {
        foreach ($restored as $token) {
            if ($token !== null) {
                $this->photoStash->discard($token, $playerId);
            }
        }
    }
}
