<?php

namespace sidecar\craftga\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use sidecar\craftga\Plugin;
use yii\console\ExitCode;

/**
 * Warms or clears the cached Google Analytics reports.
 *
 * Run "craft craft-ga/cache/warm" from a cron entry (every 5 to 15 minutes) on
 * sites where the queue is not running continuously, so the dashboard is always
 * served from a fresh cache and never waits on the Google APIs.
 *
 * @author Side-Car
 * @since 2.3.0
 */
class CacheController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $defaultAction = 'warm';

    // Public Methods
    // =========================================================================

    /**
     * Fetches every dashboard report from Google and replaces the cached copies.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function actionWarm(): int
    {
        $service = Plugin::$plugin->analytics;

        if (!$service->isConfigured()) {
            $this->stderr("Craft GA is not configured or not connected to Google.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $specs = $service->getDashboardSpecs();
        $this->stdout('Refreshing ' . count($specs) . " reports ... ", Console::FG_YELLOW);

        $start = microtime(true);
        $payloads = $service->refreshReports($specs);
        $elapsed = round(microtime(true) - $start, 1);

        $failed = array_keys(array_filter($payloads, static fn(array $payload): bool => isset($payload['error'])));

        if ($failed !== []) {
            $this->stdout("done in {$elapsed}s\n", Console::FG_YELLOW);
            $this->stderr('Failed: ' . implode(', ', $failed) . ". Check the logs for details.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("done in {$elapsed}s\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Clears every cached report. The next dashboard load fetches from Google synchronously.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function actionClear(): int
    {
        Plugin::$plugin->analytics->clearCache();
        $this->stdout("Craft GA cache cleared.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
