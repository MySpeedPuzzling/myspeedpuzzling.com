<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use SpeedPuzzling\Web\Value\StashedPhoto;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * PhotoStash for the add/edit time forms: a refused submit keeps the photos, the next submit gets them back.
 *
 * 1. restore() before handleRequest(): an empty file input with a kept-photo token gets the photo back, so
 *    the form validates it like a fresh upload. A newly chosen file always wins over the token.
 * 2. keep() when the form comes back with an error: every photo that passed its own validation is kept.
 * 3. forget() after a successful save.
 */
readonly final class FormPhotoStash
{
    public const string REQUEST_KEY = 'photo_stash';
    public const array FIELDS = ['puzzlePhoto', 'finishedPuzzlesPhoto'];

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
     * @return array<string, StashedPhoto>
     */
    public function keep(FormInterface $form, array $restored, string $playerId): array
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
            $photo = $token !== null
                ? $this->photoStash->describe($token, $playerId)
                : $this->photoStash->keep($file, $playerId);

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
