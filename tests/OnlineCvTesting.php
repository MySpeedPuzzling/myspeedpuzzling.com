<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Tests\TestDouble\OnlineCvHttpMock;

/**
 * The completeness check in tests (docs/features/online-competitions/README.md §5.8, Phase 3): .env.test keeps it off
 * like production - turnCompletenessCheckOn() switches it on for the kernels booted from then on, restore it in
 * tearDown(). The worker answers from OnlineCvHttpMock (config/services_test.php).
 */
trait OnlineCvTesting
{
    public const string ONLINE_CV_TEST_URL = 'http://online-cv.test:8000';

    /** @var false|null|string the env value before turnCompletenessCheckOn() - false: unset, null: not changed */
    private null|false|string $originalOnlineCvUrl = null;

    private function turnCompletenessCheckOn(): void
    {
        if ($this->originalOnlineCvUrl === null) {
            $original = $_ENV['ONLINE_CV_URL'] ?? false;
            $this->originalOnlineCvUrl = is_string($original) ? $original : false;
        }

        $_ENV['ONLINE_CV_URL'] = self::ONLINE_CV_TEST_URL;
        $_SERVER['ONLINE_CV_URL'] = self::ONLINE_CV_TEST_URL;
        OnlineCvHttpMock::reset();
    }

    private function restoreCompletenessCheck(): void
    {
        OnlineCvHttpMock::reset();

        if ($this->originalOnlineCvUrl === null) {
            return;
        }

        if ($this->originalOnlineCvUrl === false) {
            unset($_ENV['ONLINE_CV_URL'], $_SERVER['ONLINE_CV_URL']);
        } else {
            $_ENV['ONLINE_CV_URL'] = $this->originalOnlineCvUrl;
            $_SERVER['ONLINE_CV_URL'] = $this->originalOnlineCvUrl;
        }

        $this->originalOnlineCvUrl = null;
    }
}
