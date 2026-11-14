<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\FormData\CustomFieldFormData;
use SpeedPuzzling\Web\FormType\CustomFieldFormType;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldRefusalMessage;
use SpeedPuzzling\Web\Services\CustomFields\ViewerResultFields;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "My fields": the signed-in player's own fields - the list, adding one of their own (this form), the ready-made
 * ones with one tap, order, archive (docs/features/custom-fields.md). Nobody else's fields are ever here: every read
 * and write takes the signed-in player's id.
 */
final class CustomFieldsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'my_fields';
    public const string LOCALES = 'en|cs|de|es|fr|ja';

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
        path: '/{_locale}/custom-fields',
        name: 'my_fields',
        requirements: ['_locale' => self::LOCALES],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $data = new CustomFieldFormData();
        $form = $this->createForm(CustomFieldFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(new AddCustomField(
                    customFieldId: Uuid::uuid7()->toString(),
                    playerId: $player->playerId,
                    name: (string) $data->name,
                    type: $data->type,
                    choices: $data->choiceLines(),
                    forResults: $data->forResults,
                    forCollectionItems: $data->forCollectionItems,
                ));

                $this->viewerResultFields->fieldsChanged($request);
                $this->addFlash('success', $this->translator->trans('my_fields.flash.added'));

                return $this->redirectToRoute('my_fields');
            } catch (HandlerFailedException $exception) {
                $form->addError(new FormError($this->refusalMessage->of($exception)));
            }
        }

        $fields = $this->getCustomFields->ofPlayer($player->playerId);
        $active = array_values(array_filter($fields, static fn (CustomFieldOverview $field): bool => $field->archived === false));
        $owned = array_map(static fn (CustomFieldOverview $field): null|StandardCustomField => $field->standard, $fields);

        // A field made on another device: opening this page here makes the add form and the results show it at once
        if (array_any($fields, static fn (CustomFieldOverview $field): bool => $field->isUsableOnResults())) {
            $this->viewerResultFields->fieldsChanged($request);
        }

        return $this->render('my_fields/index.html.twig', [
            'form' => $form,
            'active_fields' => $active,
            'archived_fields' => array_values(array_filter($fields, static fn (CustomFieldOverview $field): bool => $field->archived)),
            // The ready-made fields the player does not have - an archived one comes back from the archived list
            'ready_made' => array_values(array_filter(
                StandardCustomField::cases(),
                static fn (StandardCustomField $standard): bool => in_array($standard, $owned, true) === false,
            )),
            'limit' => CustomField::MAX_ACTIVE_PER_PLAYER,
            'limit_reached' => count($active) >= CustomField::MAX_ACTIVE_PER_PLAYER,
        ]);
    }
}
