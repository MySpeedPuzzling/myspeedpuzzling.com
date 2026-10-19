<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidDigestPeriod;
use SpeedPuzzling\Web\Services\Digest\PlayerPeriodActivityReader;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Your week" - the signed-in player's own activity in one digest period (a week, two weeks or a month): the page the
 * activity digest's button opens and where every line of the e-mail lands (docs/features/content-digest/README.md
 * §"Your week page"). No player id in the URL: it is always the viewer's own, so there is nothing to hide from
 * anybody else. Without a period it shows the current week so far.
 */
final class MyActivityController extends AbstractController
{
    public const string PERIOD_REQUIREMENT = '\d{4}-(W\d{2}(-W\d{2})?|\d{2})';

    /** Nothing older to look at - the site's results start later */
    private const string EARLIEST = '2020-01-01';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerPeriodActivityReader $activityReader,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje/aktivita/{period}',
            'en' => '/en/my/activity/{period}',
            'es' => '/es/mi/actividad/{period}',
            'ja' => '/ja/マイ/アクティビティ/{period}',
            'fr' => '/fr/mon/activite/{period}',
            'de' => '/de/meine/aktivitaet/{period}',
        ],
        name: 'my_activity',
        requirements: ['period' => self::PERIOD_REQUIREMENT],
        defaults: ['period' => null],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(null|string $period): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        // Part of the XP launch: before its switch is on, admins only
        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $now = $this->clock->now();
        $digestPeriod = $period === null
            ? DigestPeriod::containing(DigestPeriodLength::Week, $now)
            : DigestPeriod::fromKey($period);

        if ($digestPeriod->hasStartedAt($now) === false || $digestPeriod->end <= new \DateTimeImmutable(self::EARLIEST)) {
            throw new InvalidDigestPeriod($digestPeriod->key);
        }

        $previous = $digestPeriod->previous();
        $next = $digestPeriod->next();

        $response = $this->render('my_activity.html.twig', [
            'activity' => $this->activityReader->read($profile->playerId, $digestPeriod, $profile->activeMembership, withFavorites: true),
            'period' => $digestPeriod,
            'in_progress' => $digestPeriod->hasEndedAt($now) === false,
            'previous_period' => $previous->end > new \DateTimeImmutable(self::EARLIEST) ? $previous : null,
            'next_period' => $next->hasStartedAt($now) ? $next : null,
            'lengths' => [
                DigestPeriodLength::Week->value => DigestPeriod::containing(DigestPeriodLength::Week, $digestPeriod->start),
                DigestPeriodLength::TwoWeeks->value => DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $digestPeriod->start),
                DigestPeriodLength::Month->value => DigestPeriod::containing(DigestPeriodLength::Month, $digestPeriod->start),
            ],
            'digest_frequency' => $profile->contentDigestFrequency,
            'digest_off' => $profile->contentDigestFrequency === ContentDigestFrequency::Off,
            'profile' => $profile,
        ]);

        // Personal numbers - never in a shared cache
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
