<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Daily (`myspeedpuzzling:erase-participant-emails`): the organisers' addresses of the participants of every event whose
 * last day ended more than 60 days ago in the event's zone are erased (docs/features/competitions-management/
 * participant-linking.md D15). The handler returns how many addresses it erased.
 */
readonly final class EraseParticipantEmails
{
}
