<?php

namespace sidecar\craftanalytics\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use sidecar\craftanalytics\Plugin;
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

        $summary = $isConfigured ? $service->getSummaryStats() : [];
        $topPages = $isConfigured ? $service->getTopPages() : [];
        $trafficSources = $isConfigured ? $service->getTrafficSources() : [];
        $dailyStats = $isConfigured ? $service->getDailyStats() : [];
        $keyEvents = $isConfigured ? $service->getKeyEvents() : [];
        $engagement = $isConfigured ? $service->getEngagementStats() : [];
        $searchConsole = ($isConfigured && $service->isSearchConsoleEnabled()) ? $service->getSearchConsoleStats() : [];

        return $this->renderTemplate('craft-analytics/_cp/index', [
            'isConfigured' => $isConfigured,
            'canManage' => $canManage,
            'summary' => $summary,
            'topPages' => $topPages,
            'trafficSources' => $trafficSources,
            'dailyStats' => $dailyStats,
            'keyEvents' => $keyEvents,
            'engagement' => $engagement,
            'searchConsole' => $searchConsole,
        ]);
    }

    public function actionRefresh(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->clearCache();

        Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Analytics cache cleared.'));

        return $this->redirect(UrlHelper::cpUrl('craft-analytics'));
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
            $session->setError(Craft::t('craft-analytics', 'Google authorization denied.'));
            return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-analytics'));
        }

        $code = $request->getQueryParam('code');
        if (empty($code)) {
            $session->setError(Craft::t('craft-analytics', 'No authorization code received.'));
            return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-analytics'));
        }

        try {
            Plugin::$plugin->analytics->handleOAuthCallback($code);
            $session->setNotice(Craft::t('craft-analytics', 'Successfully connected to Google Analytics!'));
        } catch (\Throwable $e) {
            Craft::error('Analytics OAuth error: ' . $e->getMessage(), __METHOD__);
            $session->setError(Craft::t('craft-analytics', 'Connection failed. Check the logs for details.'));
        }

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-analytics'));
    }

    public function actionDisconnect(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->disconnect();

        Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Disconnected from Google Analytics.'));

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-analytics'));
    }
}