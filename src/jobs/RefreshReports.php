<?php

namespace sidecar\craftga\jobs;

use Craft;
use craft\queue\BaseJob;
use sidecar\craftga\Plugin;

/**
 * Re-fetches a set of analytics reports in the background and replaces their
 * cached copies, so control panel requests can serve stale data instantly
 * instead of waiting on Google.
 *
 * @author Side-Car
 * @since 2.3.0
 */
class RefreshReports extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var array<string, array> Report key => parameters, as accepted by
     * {@see \sidecar\craftga\services\AnalyticsService::refreshReports()}.
     */
    public array $specs = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function execute($queue): void
    {
        if ($this->specs === []) {
            return;
        }

        Plugin::$plugin->analytics->refreshReports($this->specs);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Side-Car
     * @since 2.3.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('craft-ga', 'Refreshing Google Analytics reports');
    }
}
