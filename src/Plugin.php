<?php

namespace sidecar\craftga;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use sidecar\craftga\models\Settings;
use sidecar\craftga\services\AnalyticsService;
use sidecar\craftga\widgets\AnalyticsWidget;
use yii\base\Event;

/**
 * @property-read AnalyticsService $analytics
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.1.0';

    public static function config(): array
    {
        return [
            'components' => [
                'analytics' => ['class' => AnalyticsService::class],
            ],
        ];
    }

    public const PERMISSION_VIEW = 'craft-ga:view';
    public const PERMISSION_MANAGE = 'craft-ga:manage';

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->registerCpRoutes();
        $this->registerWidgets();
        $this->registerPermissions();
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user && !$user->admin && !$user->can(self::PERMISSION_VIEW)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('craft-ga', 'Craft GA');
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-ga/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['craft-ga'] = 'craft-ga/analytics/index';
                $event->rules['craft-ga/oauth/callback'] = 'craft-ga/analytics/callback';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('craft-ga', 'Analytics'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('craft-ga', 'View Analytics'),
                            'nested' => [
                                self::PERMISSION_MANAGE => [
                                    'label' => Craft::t('craft-ga', 'Manage Analytics'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    private function registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = AnalyticsWidget::class;
            }
        );
    }
}
