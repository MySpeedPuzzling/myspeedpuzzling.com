<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Services\CustomFields\ViewerResultFields;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "My fields" block of the add-result form on its own (docs/features/custom-fields.md "Making and managing
 * fields from the add form"): the browser asks for it when the fields dialog closes and swaps it into the open form,
 * putting back what was typed. Empty inputs only - what a field holds is never in this answer - and an empty body
 * for a player without fields for results.
 *
 * Always read from the database: the "has none" memo of the session is for the pages, and it is cleared here, so the
 * next add form agrees with what the dialog just showed.
 */
final class AddFormMyFieldsBlockController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCustomFields $getCustomFields,
        readonly private ViewerResultFields $viewerResultFields,
        #[Autowire(param: 'customFieldsInAddForm')]
        readonly private bool $enabled,
    ) {
    }

    #[Route(
        path: '/{_locale}/custom-fields/add-form-block',
        name: 'my_fields_add_form_block',
        requirements: ['_locale' => CustomFieldsController::LOCALES],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        if ($this->enabled === false) {
            throw $this->createNotFoundException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $fields = $this->getCustomFields->usableOnResults($player->playerId);
        $this->viewerResultFields->fieldsChanged($request);

        $response = $fields === []
            ? new Response('')
            : $this->render('my_fields/_add_form_block.html.twig', [
                'my_fields' => new CustomFieldsForm($fields),
                // The player just came from managing them - shown, not behind the toggle
                'open' => true,
            ]);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
