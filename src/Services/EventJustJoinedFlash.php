<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\EventJustJoined;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * The read side of EventJustJoined (F2 in docs/features/marketplace/11-events.md): did the viewer just click
 * "I'm going" on this competition? Takes the flash, so the highlight shows once.
 */
final class EventJustJoinedFlash
{
    public static function take(Request $request, string $competitionId): bool
    {
        // A visitor without a session has no flash either, and asking would start a session on an anonymous GET
        if (!$request->hasPreviousSession()) {
            return false;
        }

        $session = $request->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return false;
        }

        $flashBag = $session->getFlashBag();

        if ($flashBag->has(EventJustJoined::FLASH) === false) {
            return false;
        }

        $justJoined = false;
        // A flash of another event (another tab) stays for that event's page
        $otherEvents = [];

        foreach ($flashBag->get(EventJustJoined::FLASH) as $joinedCompetitionId) {
            if (is_string($joinedCompetitionId) && strtolower($joinedCompetitionId) === strtolower($competitionId)) {
                $justJoined = true;
            } else {
                $otherEvents[] = $joinedCompetitionId;
            }
        }

        if ($otherEvents !== []) {
            $flashBag->set(EventJustJoined::FLASH, $otherEvents);
        }

        return $justJoined;
    }
}
