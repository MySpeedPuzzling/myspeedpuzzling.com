<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A photo part of the add/edit-time form that arrived without a single byte counts as no photo at all
 * (docs/features/photo-uploads/README.md "A photo the phone cannot read").
 *
 * 2026-11-14: a phone handed the browser a photo of 0 bytes. The page takes such a photo out of the form itself
 * (assets/photo_readability.js); this is the floor under it - a page loaded before that, another browser path. Left in
 * the request, the part fails the form ("An empty file is not allowed") although the player did nothing wrong and the
 * result needs no photo; left out, the form is judged as if no photo had been picked: an existing puzzle's result is
 * saved, a new puzzle still gets its "photo required".
 *
 * The player is told by a flash, shown on whichever page comes next - also when the page itself took the photo out
 * while saving and says so with the form (`photo_dropped[<field>]=<reason>`): its own note next to the field is gone
 * with the form in the same tap. Once per save: the flash is not added while the same one still waits to be shown (the
 * automatic resend, a second tap).
 *
 * Request handling only - nothing here reaches a message or a handler. Never throws: should anything fail, the request
 * goes on untouched and the form answers as it did before.
 */
readonly final class EmptyPhotoParts
{
    // `photo_dropped[<field>]`: a photo the page took out of the form while saving (submit_prevention_controller.js)
    public const string DROPPED_REQUEST_KEY = 'photo_dropped';
    // empty / unreadable = the device could not read it, not_sent = the player's "Save without the photo"
    private const array DROPPED_REASONS = ['empty', 'unreadable', 'not_sent'];
    private const string FLASH_TYPE = 'warning';

    public function __construct(
        private TranslatorInterface $translator,
        #[Autowire(service: 'monolog.logger.photo_upload')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Before FormPhotoStash::restore() and handleRequest() - a kept photo's token may then stand in for the empty part.
     *
     * @template T
     * @param FormInterface<T> $form
     * @return list<string> the photo fields whose empty part was left out
     */
    public function leaveOut(Request $request, FormInterface $form): array
    {
        $leftOut = $this->withoutEmptyParts($request, $form);

        try {
            if ($leftOut !== []) {
                // Routine, counted next to the browser's own `photo_dropped` reports (PhotoUploadReportController)
                $this->logger->info('Photo the device could not read was taken out of the form', [
                    'event' => 'photo_dropped',
                    'reason' => 'empty',
                    'detected_on' => 'server',
                    'field' => implode(',', $leftOut),
                    'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
                ]);
            }

            if ($leftOut !== [] || $this->droppedByThePage($request, $form) !== []) {
                $this->tellOnce($request);
            }
        } catch (\Throwable) {
            // The photo is out of the request - that is what matters
        }

        return $leftOut;
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @return list<string>
     */
    private function withoutEmptyParts(Request $request, FormInterface $form): array
    {
        try {
            if ($request->isMethod('POST') === false) {
                return [];
            }

            $files = $request->files->all($form->getName());
            $leftOut = [];

            foreach (FormPhotoStash::FIELDS as $field) {
                $file = $files[$field] ?? null;

                // Only a part that arrived whole and holds nothing - an upload error keeps its own message
                if ($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_OK && $file->getSize() === 0) {
                    unset($files[$field]);
                    $leftOut[] = $field;
                }
            }

            if ($leftOut === []) {
                return [];
            }

            $request->files->set($form->getName(), $files);

            return $leftOut;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What the page says it took out while saving. Read strictly - a field of this form, a known reason, and no photo
     * for the field in the request after all (picked again, or its upload's token): anything else is ignored.
     *
     * @template T
     * @param FormInterface<T> $form
     * @return array<string, string> field => reason
     */
    private function droppedByThePage(Request $request, FormInterface $form): array
    {
        if ($request->isMethod('POST') === false) {
            return [];
        }

        $posted = $request->request->all();
        $said = $posted[self::DROPPED_REQUEST_KEY] ?? null;

        if (!is_array($said)) {
            return [];
        }

        $tokens = $posted[FormPhotoStash::REQUEST_KEY] ?? null;
        $tokens = is_array($tokens) ? $tokens : [];
        $files = $request->files->all($form->getName());
        $dropped = [];

        foreach (FormPhotoStash::FIELDS as $field) {
            $reason = $said[$field] ?? null;

            if (!is_string($reason) || !in_array($reason, self::DROPPED_REASONS, true) || $form->has($field) === false) {
                continue;
            }

            $token = $tokens[$field] ?? null;

            if (($files[$field] ?? null) instanceof UploadedFile || (is_string($token) && $token !== '')) {
                continue;
            }

            $dropped[$field] = $reason;
        }

        return $dropped;
    }

    private function tellOnce(Request $request): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface === false) {
            return;
        }

        $message = $this->translator->trans('photo_upload.left_out');
        $flashes = $session->getFlashBag();

        // Still waiting to be shown: the same save sent again (the automatic resend, another tap)
        if (in_array($message, $flashes->peek(self::FLASH_TYPE), true) === false) {
            $flashes->add(self::FLASH_TYPE, $message);
        }
    }
}
