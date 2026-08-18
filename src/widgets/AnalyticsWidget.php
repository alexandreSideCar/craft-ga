<?php

namespace sidecar\craftga\widgets;

use Craft;
use craft\base\Widget;
use sidecar\craftga\Plugin;

class AnalyticsWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('craft-ga', 'Craft GA');
    }

    public static function icon(): ?string
    {
        return 'chart-bar';
    }

    public static function isSelectable(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        return $user && ($user->admin || $user->can(Plugin::PERMISSION_VIEW));
    }

    public function getBodyHtml(): ?string
    {
        $service = Plugin::$plugin->analytics;
        $isConfigured = $service->isConfigured();
        $summary = $isConfigured ? $service->getSummaryStats() : [];

        return Craft::$app->getView()->renderTemplate('craft-ga/_widgets/body', [
            'isConfigured' => $isConfigured,
            'summary' => $summary,
        ]);
    }
}
