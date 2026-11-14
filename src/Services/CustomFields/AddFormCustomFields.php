<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;
use SpeedPuzzling\Web\Message\SetSolvingTimeCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The player's own fields in the add-result form (docs/features/custom-fields.md "The add form"). The form is the
 * most important one of the app, so everything here is beside it and nothing here can stop it:
 *
 * - the fields are plain inputs next to the Symfony form, never its children - no value typed into one can make the
 *   form invalid, and a form rendered without them is the form as it always was;
 * - loading the fields fails open: any error = the form of a player without custom fields (a warning is logged);
 * - the values are saved after the result is saved, as a step of their own that never throws: a value that can not
 *   be stored is left out and the player is told which field, any other failure is logged and the player is told
 *   the result is saved and where to fill the fields in;
 * - the kill switch `CUSTOM_FIELDS_IN_ADD_FORM` (docs/features/feature_flags.md) turns both off.
 */
readonly final class AddFormCustomFields
{
    public function __construct(
        private ViewerResultFields $viewerResultFields,
        private GetCustomFields $getCustomFields,
        private CustomFieldLabels $customFieldLabels,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(param: 'customFieldsInAddForm')]
        private bool $enabled,
    ) {
    }

    /**
     * The "My fields" block of the form, with what a refused submit held - null for a player without fields for
     * results, while the switch is off, and whenever anything goes wrong.
     */
    public function forForm(Request $request, string $playerId): null|CustomFieldsForm
    {
        if ($this->enabled === false) {
            return null;
        }

        try {
            $fields = $this->viewerResultFields->of($request, $playerId);

            if ($fields === []) {
                return null;
            }

            return new CustomFieldsForm($fields, $request->isMethod('POST') ? SubmittedCustomValues::of($request) : []);
        } catch (\Throwable $exception) {
            $this->logger->warning('Custom fields could not be loaded for the add form - the form is shown without them', [
                'playerId' => $playerId,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * After the result is saved - also when the form was sent again and answered with the saved result (the write
     * stores the same twice). Never throws.
     */
    public function saveSubmitted(Request $request, string $solvingTimeId, string $playerId): void
    {
        if ($this->enabled === false) {
            return;
        }

        try {
            $submitted = array_filter(SubmittedCustomValues::of($request), static fn (string $value): bool => trim($value) !== '');

            if ($submitted === []) {
                return;
            }

            $fields = [];

            foreach ($this->getCustomFields->usableOnResults($playerId) as $field) {
                $fields[$field->fieldId] = $field;
            }

            $values = [];
            $notSaved = [];
            $unknown = false;

            foreach ($submitted as $fieldId => $value) {
                $field = $fields[$fieldId] ?? null;

                // Archived or removed since the form was opened
                if ($field === null) {
                    $unknown = true;

                    continue;
                }

                try {
                    CustomValues::normalise($field->type, $field->choices, $value);
                    $values[$fieldId] = $value;
                } catch (InvalidCustomValue) {
                    $notSaved[] = $this->customFieldLabels->label($field);
                }
            }

            if ($values !== []) {
                $this->messageBus->dispatch(new SetSolvingTimeCustomValues($solvingTimeId, $playerId, $values));
            }

            if ($notSaved !== []) {
                $this->flash($request, $this->translator->trans('my_fields.add_form.some_not_saved', [
                    '%count%' => count($notSaved),
                    '%fields%' => implode(', ', $notSaved),
                ]));
            } elseif ($unknown) {
                $this->flash($request, $this->translator->trans('my_fields.add_form.not_saved'));
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Custom fields of a saved result could not be saved', [
                'solvingTimeId' => $solvingTimeId,
                'playerId' => $playerId,
                'exception' => $exception,
            ]);

            try {
                $this->flash($request, $this->translator->trans('my_fields.add_form.not_saved'));
            } catch (\Throwable) {
                // The result is saved - nothing about the player's own fields may get in its way
            }
        }
    }

    private function flash(Request $request, string $message): void
    {
        if ($request->hasSession() === false) {
            return;
        }

        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $message);
        }
    }
}
