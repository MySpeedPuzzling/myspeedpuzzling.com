<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The sweeper (`myspeedpuzzling:continue-import-batches`, every 5 minutes): running "Add"s and Undos nobody worked on
 * for a while - a restarted worker, a lost job, adding switched on again after a pause - are handed to the worker
 * again. While adding is paused, only Undos.
 */
readonly final class ContinueImportBatches
{
}
