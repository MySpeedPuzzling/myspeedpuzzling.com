<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use SpeedPuzzling\Web\FormData\CustomFieldFormData;
use SpeedPuzzling\Web\FormType\CustomFieldFormType;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldRefusalMessage;
use SpeedPuzzling\Web\Services\CustomFields\ViewerResultFields;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rename one of the player's own fields, change its choices or where it is for - never its type, never a ready-made
 * field (docs/features/custom-fields.md).
 */
final class EditCustomFieldController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCustomFields $getCustomFields,
        readonly private ViewerResultFields $viewerResultFields,
        readonly private CustomFieldRefusalMessage $refusalMessage,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/custom-fields/{fieldId}/edit',
        name: 'my_fields_edit',
        requirements: ['_locale' => CustomFieldsController::LOCALES, 'fieldId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $fieldId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $field = null;

        // Among the player's own fields only - somebody else's field does not exist here
        foreach ($this->getCustomFields->ofPlayer($player->playerId) as $candidate) {
            if ($candidate->fieldId === strtolower($fieldId)) {
                $field = $candidate;
            }
        }

        if ($field === null || $field->standard !== null) {
            throw $this->createNotFoundException();
        }

        $data = CustomFieldFormData::fromField($field);
        $form = $this->createForm(CustomFieldFormType::class, $data, [
            'with_type' => false,
            'with_choices' => $field->type === CustomFieldType::Choice,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(new EditCustomField(
                    customFieldId: $field->fieldId,
                    playerId: $player->playerId,
                    name: (string) $data->name,
                    choices: $data->choiceLines(),
                    forResults: $data->forResults,
                    forCollectionItems: $data->forCollectionItems,
                ));

                $this->viewerResultFields->fieldsChanged($request);
                $this->addFlash('success', $this->translator->trans('my_fields.flash.saved'));

                return $this->redirectToRoute('my_fields');
            } catch (HandlerFailedException $exception) {
                $form->addError(new FormError($this->refusalMessage->of($exception)));
            }
        }

        return $this->render('my_fields/edit.html.twig', [
            'form' => $form,
            'field' => $field,
        ]);
    }
}
