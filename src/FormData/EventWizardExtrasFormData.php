<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Results\EventWizardExtrasState;
use SpeedPuzzling\Web\Value\DisplayPreset;
use SpeedPuzzling\Web\Value\QrTagMode;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The event creation wizard's "Extras" step (EventWizardExtrasFormType, W-creation-wizard-research.md §3.8): one switch
 * per opt-in feature, and only the switched-on feature's must-ask fields - a field of a row switched off is ignored,
 * also by the validation (Assert\When on the row's switch). Starts from what the event has now (fromState()), so coming
 * back shows the switches as they are.
 */
final class EventWizardExtrasFormData
{
    // Registration on MySpeedPuzzling (ChangeCompetitionRegistrationSettings) - the window is typed as a local wall
    // clock in the event's zone (the registration page's rule), the zone itself is not asked
    public bool $registration = false;

    #[Assert\When(expression: 'this.registration', constraints: [new Assert\Range(min: 1, max: ChangeCompetitionRegistrationSettings::CAPACITY_MAX)])]
    public null|int $capacity = null;

    public null|DateTimeImmutable $opensAt = null;

    public null|DateTimeImmutable $closesAt = null;

    #[Assert\When(expression: 'this.registration', constraints: [new Assert\Length(max: ChangeCompetitionRegistrationSettings::ENTRY_FEE_MAX_LENGTH)])]
    public null|string $entryFeeText = null;

    // Registration / results elsewhere (EditCompetition's links) - the classic form's constraints
    public bool $elsewhere = false;

    #[Assert\When(expression: 'this.elsewhere', constraints: [new Assert\Url(requireTld: true), new Assert\Length(max: 250)])]
    public null|string $registrationLink = null;

    #[Assert\When(expression: 'this.elsewhere', constraints: [new Assert\Url(requireTld: true), new Assert\Length(max: 250)])]
    public null|string $resultsLink = null;

    // Judges enter results on their phones (AddCompetitionReferee × n) - the players to add; the event's judges are
    // listed beside them
    public bool $judges = false;

    /** @var list<string> */
    public array $judgePlayers = [];

    // Competition toolkit rows (COMPETITION_TOOLKIT_MANAGE) - ChangeCompetitorPageSettings
    public bool $qrTags = false;

    public null|QrTagMode $qrTagMode = null;

    public bool $competitorPages = false;

    public bool $liveResultsBeforePublishing = false;

    public bool $timeProposals = false;

    // ... AddCompetitionDisplay, offered while the event has no display of the kind
    public bool $timerScreen = false;

    public DisplayPreset $screenPreset = DisplayPreset::ClassicTimer;

    public bool $overlay = false;

    public DisplayPreset $overlayPreset = DisplayPreset::ObsLowerThird;

    // ... CreateCompetitionStanding per category, offered for several rounds without standings
    public bool $standings = false;

    public static function fromState(EventWizardExtrasState $state): self
    {
        $data = new self();
        $data->registration = $state->registrationManaged;
        $data->capacity = $state->capacity;
        $data->opensAt = self::wallClock($state->registrationOpensAt, $state->registrationTimezone);
        $data->closesAt = self::wallClock($state->registrationClosesAt, $state->registrationTimezone);
        $data->entryFeeText = $state->entryFeeText;
        $data->elsewhere = $state->registrationLink !== null || $state->resultsLink !== null;
        $data->registrationLink = $state->registrationLink;
        $data->resultsLink = $state->resultsLink;
        $data->judges = $state->referees !== [];
        $data->qrTags = $state->competitorPageSettings->qrTagMode !== null;
        $data->qrTagMode = $state->competitorPageSettings->qrTagMode ?? QrTagMode::Entrant;
        $data->competitorPages = $state->competitorPageSettings->competitorPages;
        $data->liveResultsBeforePublishing = $state->competitorPageSettings->liveResultsBeforePublishing;
        $data->timeProposals = $state->competitorPageSettings->timeProposals;

        return $data;
    }

    #[Assert\Callback]
    public function validateSwitchedOnRows(ExecutionContextInterface $context): void
    {
        if ($this->registration && $this->opensAt !== null && $this->closesAt !== null && $this->closesAt <= $this->opensAt) {
            $context->buildViolation('competition_registration_closes_before_opens')
                ->atPath('closesAt')
                ->addViolation();
        }

        if ($this->elsewhere && self::trimmed($this->registrationLink) === null && self::trimmed($this->resultsLink) === null) {
            $context->buildViolation('event_wizard.extras.elsewhere_link_required')
                ->setTranslationDomain('messages')
                ->atPath('registrationLink')
                ->addViolation();
        }

        if ($this->qrTags && $this->qrTagMode === null) {
            $context->buildViolation('event_wizard.extras.qr_mode_required')
                ->setTranslationDomain('messages')
                ->atPath('qrTagMode')
                ->addViolation();
        }
    }

    public function trimmedEntryFee(): null|string
    {
        return self::trimmed($this->entryFeeText);
    }

    public function trimmedRegistrationLink(): null|string
    {
        return $this->elsewhere ? self::trimmed($this->registrationLink) : null;
    }

    public function trimmedResultsLink(): null|string
    {
        return $this->elsewhere ? self::trimmed($this->resultsLink) : null;
    }

    private static function wallClock(null|DateTimeImmutable $instant, string $timezone): null|DateTimeImmutable
    {
        if ($instant === null) {
            return null;
        }

        return new DateTimeImmutable(RoundTimezone::toLocal($instant, $timezone)->format('Y-m-d H:i:s'));
    }

    private static function trimmed(null|string $value): null|string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
