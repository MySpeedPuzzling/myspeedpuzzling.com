<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\MyData;

use SpeedPuzzling\Web\Message\ChangeMyDataColumns;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The column chooser of "My data" (docs/features/my-data.md): which columns of one tab the signed-in player sees,
 * remembered on their player. The form posts every column the chooser offered (`columns[]`) and the ticked ones
 * (`shown[]`); the rest is hidden. Always answers with a redirect back to the table in the state it was in (`state`
 * is the table's own query string, read through MyDataCriteria - never an address).
 */
final class ChangeMyDataColumnsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'my_data_columns';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-data/columns',
        name: 'my_data_columns',
        requirements: ['_locale' => MyDataController::LOCALES],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        parse_str($request->request->getString('state'), $state);
        $criteria = MyDataCriteria::fromUserInput($state);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('my_data.columns.not_saved'));

            return $this->redirectToRoute('my_data', $criteria->query(['page' => $criteria->page > 1 ? $criteria->page : null]), Response::HTTP_SEE_OTHER);
        }

        $offered = array_filter($request->request->all('columns'), is_string(...));
        $shown = array_filter($request->request->all('shown'), is_string(...));

        $this->messageBus->dispatch(new ChangeMyDataColumns(
            playerId: $player->playerId,
            tab: $criteria->tab,
            hiddenColumns: array_values(array_diff($offered, $shown)),
        ));

        return $this->redirectToRoute('my_data', $criteria->query(['page' => $criteria->page > 1 ? $criteria->page : null]), Response::HTTP_SEE_OTHER);
    }
}
