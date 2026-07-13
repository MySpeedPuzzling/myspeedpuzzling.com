<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\NonUniquePlayerCode;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormData\FeaturesOptionsFormData;
use SpeedPuzzling\Web\FormData\MessagingSettingsFormData;
use SpeedPuzzling\Web\FormData\PlayerCodeFormData;
use SpeedPuzzling\Web\FormData\PlayerVisibilityFormData;
use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\FormType\FeaturesOptionsFormType;
use SpeedPuzzling\Web\FormType\MessagingSettingsFormType;
use SpeedPuzzling\Web\FormType\PlayerCodeFormType;
use SpeedPuzzling\Web\FormType\PlayerVisibilityFormType;
use SpeedPuzzling\Web\Message\EditFeaturesOptions;
use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Message\EditPlayerVisibility;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Query\GetOauthIdentities;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Query\GetApiUsage;
use SpeedPuzzling\Web\Query\GetOAuth2ClientRequests;
use SpeedPuzzling\Web\Query\GetPlayerOAuth2Consents;
use SpeedPuzzling\Web\Query\GetPlayerPersonalAccessTokens;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditProfileController extends AbstractController
{
    public const string JUST_CONNECTED_FLASH = 'social_link_just_connected';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
        readonly private GetPlayerOAuth2Consents $getPlayerOAuth2Consents,
        readonly private GetPlayerPersonalAccessTokens $getPlayerPersonalAccessTokens,
        readonly private GetOAuth2ClientRequests $getOAuth2ClientRequests,
        readonly private GetOauthIdentities $getOauthIdentities,
        readonly private GetUserBlocks $getUserBlocks,
        readonly private GetApiUsage $getApiUsage,
        readonly private ClockInterface $clock,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-profil',
            'en' => '/en/edit-profile',
            'es' => '/es/editar-perfil',
            'ja' => '/ja/プロフィール編集',
            'fr' => '/fr/modifier-profil',
            'de' => '/de/profil-bearbeiten',
        ],
        name: 'edit_profile',
    )]
    public function __invoke(Request $request, #[CurrentUser] UserAccount $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $socialLinkResult = $request->query->get('social_link_result');

        if (is_string($socialLinkResult)) {
            return $this->socialLinkResultToFlash($socialLinkResult, $request->query->get('social_link_provider'));
        }

        $defaultData = EditProfileFormData::fromPlayerProfile($player);

        $editProfileForm = $this->createForm(EditProfileFormType::class, $defaultData);
        $editProfileForm->handleRequest($request);

        if ($editProfileForm->isSubmitted() && $editProfileForm->isValid()) {
            $data = $editProfileForm->getData();

            $this->messageBus->dispatch(
                EditProfile::fromFormData($player->playerId, $data),
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        $editCodeFormData = PlayerCodeFormData::fromPlayerProfile($player);

        $editCodeForm = $this->createForm(PlayerCodeFormType::class, $editCodeFormData);
        $editCodeForm->handleRequest($request);

        if ($editCodeForm->isSubmitted() && $editCodeForm->isValid()) {
            if ($player->activeMembership !== true) {
                $this->addFlash('warning', $this->translator->trans('flashes.exclusive_membership_feature'));

                return $this->redirectToRoute('membership');
            }

            try {
                $this->messageBus->dispatch(
                    new EditPlayerCode($player->playerId, $editCodeFormData->code)
                );

                $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof NonUniquePlayerCode) {
                    $this->addFlash('danger', $this->translator->trans('flashes.non_unique_player_code'));
                } else {
                    $this->logger->error('Changing player code failed', [
                        'exception' => $exception,
                    ]);

                    $this->addFlash('danger', $this->translator->trans('flashes.unknown_error'));
                }

                return $this->redirectToRoute('edit_profile');
            }

            return $this->redirectToRoute('my_profile');
        }

        $editVisibilityFormData = PlayerVisibilityFormData::fromPlayerProfile($player);

        $editVisibilityForm = $this->createForm(PlayerVisibilityFormType::class, $editVisibilityFormData);
        $editVisibilityForm->handleRequest($request);

        if ($editVisibilityForm->isSubmitted() && $editVisibilityForm->isValid()) {
            if ($player->activeMembership !== true) {
                $this->addFlash('warning', $this->translator->trans('flashes.exclusive_membership_feature'));

                return $this->redirectToRoute('membership');
            }

            $this->messageBus->dispatch(
                new EditPlayerVisibility($player->playerId, $editVisibilityFormData->isPrivate)
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        $messagingSettingsFormData = MessagingSettingsFormData::fromPlayerProfile($player);

        $xpSurfacesVisible = $this->xpFeatureGate->isVisibleFor($player);

        $messagingSettingsForm = $this->createForm(MessagingSettingsFormType::class, $messagingSettingsFormData, [
            'show_content_digest' => $xpSurfacesVisible,
        ]);
        $messagingSettingsForm->handleRequest($request);

        if ($messagingSettingsForm->isSubmitted() && $messagingSettingsForm->isValid()) {
            $this->messageBus->dispatch(
                new EditMessagingSettings(
                    $player->playerId,
                    $messagingSettingsFormData->allowDirectMessages,
                    $messagingSettingsFormData->emailNotificationsEnabled,
                    $messagingSettingsFormData->emailNotificationFrequency,
                    $messagingSettingsFormData->newsletterEnabled,
                    $messagingSettingsFormData->resultEmailsEnabled,
                    $xpSurfacesVisible ? $messagingSettingsFormData->contentDigestFrequency : null,
                )
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        $featuresOptionsFormData = FeaturesOptionsFormData::fromPlayerProfile($player);

        $featuresOptionsForm = $this->createForm(FeaturesOptionsFormType::class, $featuresOptionsFormData, [
            'show_experience_system' => $xpSurfacesVisible,
        ]);
        $featuresOptionsForm->handleRequest($request);

        if ($featuresOptionsForm->isSubmitted() && $featuresOptionsForm->isValid()) {
            $this->messageBus->dispatch(
                new EditFeaturesOptions(
                    $player->playerId,
                    $featuresOptionsFormData->streakOptedOut,
                    $featuresOptionsFormData->rankingOptedOut,
                    $featuresOptionsFormData->timePredictionsOptedOut,
                    $xpSurfacesVisible ? $featuresOptionsFormData->experienceSystemOptedOut : null,
                )
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        $oauth2Consents = $this->getPlayerOAuth2Consents->byPlayerId($player->playerId);
        $personalAccessTokens = $this->getPlayerPersonalAccessTokens->byPlayerId($player->playerId);
        $myApplications = $this->getOAuth2ClientRequests->byPlayerId($player->playerId);
        $ownClientIdentifiers = array_values(array_filter(array_map(
            static fn ($application): null|string => $application->clientIdentifier,
            $myApplications,
        )));

        return $this->render('edit-profile.html.twig', [
            'player' => $player,
            'edit_profile_form' => $editProfileForm,
            'edit_code_form' => $editCodeForm,
            'edit_visibility_form' => $editVisibilityForm,
            'messaging_settings_form' => $messagingSettingsForm,
            'features_options_form' => $featuresOptionsForm,
            'oauth2_consents' => $oauth2Consents,
            'personal_access_tokens' => $personalAccessTokens,
            'my_applications' => $myApplications,
            'api_usage' => $this->getApiUsage->recentForPlayer($player->playerId, $ownClientIdentifiers, $this->clock->now()),
            'account_email' => $user->email,
            'account_email_verified' => $user->emailVerifiedAt !== null,
            // Connected sign-in methods (auth hardening PR 2): social-only
            // accounts (null password) get the set-password door instead of
            // change-password, and the connect/disconnect list needs the rows
            'account_has_password' => $user->password !== null,
            'connected_oauth_identities' => $this->getOauthIdentities->byUserId($user->userId),
            'blocked_users' => $this->getUserBlocks->forPlayer($player->playerId),
        ]);
    }

    /**
     * The link flows end here with the outcome in the query: the Apple callback
     * is a cross-site POST without session cookies, so it cannot flash (see
     * SocialLoginCallbackController). This request carries the session, so the
     * outcome becomes the site-wide flash at the top of the page, and the
     * redirect to the clean URL keeps a reload from repeating it. The card far
     * down the page marks the freshly connected provider's row via its own
     * flash label, which base.html.twig never renders.
     */
    private function socialLinkResultToFlash(string $result, mixed $providerValue): Response
    {
        $provider = OauthProvider::tryFrom(is_string($providerValue) ? $providerValue : '');

        match ($result) {
            'connected' => $this->addFlash('success', $provider === null
                ? $this->translator->trans('edit_profile.social.result_connected')
                : $this->translator->trans('edit_profile.social.result_connected_provider', [
                    '%provider%' => $provider->displayName(),
                ])),
            'already_linked' => $this->addFlash('warning', $this->translator->trans('edit_profile.social.result_already_linked')),
            'cancelled' => $this->addFlash('warning', $this->translator->trans('edit_profile.social.result_cancelled')),
            'failed' => $this->addFlash('danger', $this->translator->trans('edit_profile.social.result_failed')),
            default => null,
        };

        if ($result === 'connected' && $provider !== null) {
            $this->addFlash(self::JUST_CONNECTED_FLASH, $provider->value);
        }

        return $this->redirectToRoute('edit_profile');
    }
}
