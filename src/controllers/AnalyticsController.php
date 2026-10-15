<?php

namespace sidecar\craftga\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use sidecar\craftga\Plugin;
use sidecar\craftga\services\AnalyticsService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class AnalyticsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * @var string Session key holding the OAuth `state` token used to guard against CSRF on the authorization flow.
     */
    private const OAUTH_STATE_SESSION_KEY = 'craftAnalytics.oauthState';

    private function requireViewPermission(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Plugin::PERMISSION_VIEW))) {
            throw new ForbiddenHttpException('User is not authorized to view analytics.');
        }
    }

    private function requireManagePermission(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Plugin::PERMISSION_MANAGE))) {
            throw new ForbiddenHttpException('User is not authorized to manage analytics.');
        }
    }

    public function actionIndex(): Response
    {
        $this->requireViewPermission();

        $service = Plugin::$plugin->analytics;
        $isConfigured = $service->isConfigured();
        $canManage = Craft::$app->getUser()->getIdentity()->admin
            || Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE);

        // One call so every uncached GA4 report travels in the same batched requests.
        $data = $isConfigured ? $service->getDashboardData() : [];

        return $this->renderTemplate('craft-ga/_cp/index', [
            'isConfigured' => $isConfigured,
            'canManage' => $canManage,
            'summary' => $data[AnalyticsService::REPORT_SUMMARY] ?? [],
            'topPages' => $data[AnalyticsService::REPORT_TOP_PAGES] ?? [],
            'trafficSources' => $data[AnalyticsService::REPORT_TRAFFIC_SOURCES] ?? [],
            'topReferrals' => $data[AnalyticsService::REPORT_TOP_REFERRALS] ?? [],
            'dailyStats' => $data[AnalyticsService::REPORT_DAILY] ?? [],
            'keyEvents' => $data[AnalyticsService::REPORT_KEY_EVENTS] ?? [],
            'engagement' => $data[AnalyticsService::REPORT_ENGAGEMENT] ?? [],
            'searchConsole' => $data[AnalyticsService::REPORT_SEARCH_CONSOLE] ?? [],
        ]);
    }

    public function actionRefresh(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->clearCache();

        Craft::$app->getSession()->setNotice(Craft::t('craft-ga', 'Analytics cache cleared.'));

        return $this->redirect(UrlHelper::cpUrl('craft-ga'));
    }

    public function actionConnect(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $state = StringHelper::randomString(32);
        Craft::$app->getSession()->set(self::OAUTH_STATE_SESSION_KEY, $state);

        $authUrl = Plugin::$plugin->analytics->getAuthUrl($state);

        return $this->redirect($authUrl);
    }

    public function actionCallback(): Response
    {
        $this->requireAdmin();

        $session = Craft::$app->getSession();
        $request = Craft::$app->getRequest();

        $expectedState = $session->get(self::OAUTH_STATE_SESSION_KEY);
        $session->remove(self::OAUTH_STATE_SESSION_KEY);

        $state = (string) $request->getQueryParam('state');
        if (empty($expectedState) || !hash_equals($expectedState, $state)) {
            throw new BadRequestHttpException('Invalid OAuth state.');
        }

        $error = $request->getQueryParam('error');
        if ($error) {
            $session->setError(Craft::t('craft-ga', 'Google authorization denied.'));
            return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ga'));
        }

        $code = $request->getQueryParam('code');
        if (empty($code)) {
            $session->setError(Craft::t('craft-ga', 'No authorization code received.'));
            return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ga'));
        }

        try {
            $refreshToken = Plugin::$plugin->analytics->handleOAuthCallback($code);
            // Shown once on the settings screen so it can be copied to .env; never stored by the plugin.
            $session->set(Plugin::PENDING_REFRESH_TOKEN_SESSION_KEY, $refreshToken);
            $session->setNotice(Craft::t('craft-ga', 'Google authorized. Add the refresh token to your .env file to finish connecting.'));
        } catch (\Throwable $e) {
            Craft::error('Analytics OAuth error: ' . $e->getMessage(), __METHOD__);
            $session->setError(Craft::t('craft-ga', 'Connection failed. Check the logs for details.'));
        }

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ga'));
    }

    public function actionDisconnect(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->disconnect();

        Craft::$app->getSession()->setNotice(Craft::t('craft-ga', 'Disconnected from Google Analytics.'));

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ga'));
    }
}