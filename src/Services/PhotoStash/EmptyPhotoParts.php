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
 * saved, a new puzzle still gets its "photo required". The player is told by a flash, shown on whichever page comes next.
 *
 * Request handling only - nothing here reaches a message or a handler. Never throws: should anything fail, the request
 * goes on untouched and the form answers as it did before.
 */
readonly final class EmptyPhotoParts
{
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
     * @return list<string> the photo fields left out
     */
    public function leaveOut(Request $request, FormInterface $form): array
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
        } catch (\Throwable) {
            return [];
        }

        try {
            // Routine, counted next to the browser's own `photo_dropped` reports (PhotoUploadReportController)
            $this->logger->info('Photo the device could not read was taken out of the form', [
                'event' => 'photo_dropped',
                'reason' => 'empty',
                'detected_on' => 'server',
                'field' => implode(',', $leftOut),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 300),
            ]);

            $session = $request->hasSession() ? $request->getSession() : null;

            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', $this->translator->trans('photo_upload.arrived_empty'));
            }
        } catch (\Throwable) {
            // The photo is out of the request - that is what matters
        }

        return $leftOut;
    }
}
