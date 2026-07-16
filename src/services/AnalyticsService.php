<?php

namespace sidecar\craftanalytics\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Client as GoogleClient;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use sidecar\craftanalytics\Plugin;
use yii\caching\TagDependency;

class AnalyticsService extends Component
{
    private ?BetaAnalyticsDataClient $_client = null;

    private const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';
    private const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const CACHE_PREFIX = 'craft-analytics:';
    private const CACHE_TAG = 'craft-analytics';

    public function isConfigured(): bool
    {
        $settings = Plugin::$plugin->getSettings();
        $propertyId = App::parseEnv($settings->propertyId);
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);

        return !empty($propertyId) && !empty($clientId) && !empty($clientSecret) && $settings->isConnected();
    }

    public function getAuthUrl(string $state): string
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $redirectUri = $this->getRedirectUri();

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->_getScopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function getRedirectUri(): string
    {
        $url = UrlHelper::cpUrl('craft-analytics/oauth/callback', null, 'https');
        
        // Supprime tout paramètre d'URL dynamique (ex: ?site=en) ajouté par Craft
        // pour garantir que l'URL soit toujours statique et acceptée par Google.
        if (($pos = strpos($url, '?')) !== false) {
            $url = substr($url, 0, $pos);
        }
        
        return $url;
    }

    public function handleOAuthCallback(string $code): void
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);
        $redirectUri = $this->getRedirectUri();

        $response = Craft::createGuzzleClient(['timeout' => 10])->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['refresh_token'])) {
            throw new \RuntimeException('No refresh token received. Please revoke access and try again.');
        }

        $settings->oauthAccessToken = $data['access_token'] ?? '';
        $settings->oauthRefreshToken = $data['refresh_token'];
        $settings->oauthExpiresAt = time() + ($data['expires_in'] ?? 3600);

        Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, $settings->toArray());
    }

    public function disconnect(): void
    {
        Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, [
            'oauthAccessToken' => '',
            'oauthRefreshToken' => '',
            'oauthExpiresAt' => 0,
        ]);
        $this->clearCache();
    }

    public function getSummaryStats(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'summary', function () {
            return $this->_fetchSummaryStats();
        }, $duration, $this->_cacheDependency());
    }

    public function getTrafficSources(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'traffic-sources', function () {
            return $this->_fetchTrafficSources();
        }, $duration, $this->_cacheDependency());
    }

    public function getTopPages(int $limit = 10): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'top-pages', function () use ($limit) {
            return $this->_fetchTopPages($limit);
        }, $duration, $this->_cacheDependency());
    }

    public function getDailyStats(int $days = 30): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . "daily-{$days}", function () use ($days) {
            return $this->_fetchDailyStats($days);
        }, $duration, $this->_cacheDependency());
    }

    public function getKeyEvents(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'key-events', function () {
            return $this->_fetchKeyEvents();
        }, $duration, $this->_cacheDependency());
    }

    public function getEngagementStats(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'engagement', function () {
            return $this->_fetchEngagementStats();
        }, $duration, $this->_cacheDependency());
    }

    public function getSearchConsoleStats(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'search-console', function () {
            return $this->_fetchSearchConsoleStats();
        }, $duration, $this->_cacheDependency());
    }

    public function clearCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * Returns the tag dependency applied to every cached report, so {@see clearCache()}
     * can invalidate all variants (including `daily-{$days}` for any `$days`) in one call.
     */
    private function _cacheDependency(): TagDependency
    {
        return new TagDependency(['tags' => self::CACHE_TAG]);
    }

    private function _fetchSummaryStats(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $periods = [
                'today' => ['today', 'today', 'yesterday', 'yesterday'],
                'week' => ['7daysAgo', 'today', '14daysAgo', '8daysAgo'],
                'month' => ['30daysAgo', 'today', '60daysAgo', '31daysAgo'],
            ];

            $metrics = [
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'screenPageViews']),
            ];

            $result = [];

            foreach ($periods as $key => [$start, $end, $prevStart, $prevEnd]) {
                $response = $client->runReport(new RunReportRequest([
                    'property' => $propertyId,
                    'date_ranges' => [
                        new DateRange(['start_date' => $start, 'end_date' => $end]),
                        new DateRange(['start_date' => $prevStart, 'end_date' => $prevEnd]),
                    ],
                    'metrics' => $metrics,
                ]));

                $rows = iterator_to_array($response->getRows());
                $current = $rows[0] ?? null;
                $previous = $rows[1] ?? null;

                $currentValues = [
                    'users' => $current ? (int) $current->getMetricValues()[0]->getValue() : 0,
                    'sessions' => $current ? (int) $current->getMetricValues()[1]->getValue() : 0,
                    'pageviews' => $current ? (int) $current->getMetricValues()[2]->getValue() : 0,
                ];

                $previousValues = [
                    'users' => $previous ? (int) $previous->getMetricValues()[0]->getValue() : 0,
                    'sessions' => $previous ? (int) $previous->getMetricValues()[1]->getValue() : 0,
                    'pageviews' => $previous ? (int) $previous->getMetricValues()[2]->getValue() : 0,
                ];

                $result[$key] = $currentValues;
                $result[$key]['change'] = [];
                foreach (['users', 'sessions', 'pageviews'] as $metric) {
                    $result[$key]['change'][$metric] = $this->_percentChange($currentValues[$metric], $previousValues[$metric]);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch summary stats', $e);
        }
    }

    private function _fetchTopPages(int $limit): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $dims = [
                new Dimension(['name' => 'pagePath']),
                new Dimension(['name' => 'pageTitle']),
            ];
            $metrics = [new Metric(['name' => 'screenPageViews'])];

            // Both periods in one request. With multiple date ranges GA4 appends a
            // synthetic `dateRange` dimension (date_range_0 = current, date_range_1 = previous)
            // as the last dimension on each row.
            $response = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => $this->_comparisonDateRanges(),
                'dimensions' => $dims,
                'metrics' => $metrics,
                'order_bys' => [
                    new OrderBy([
                        'metric' => new MetricOrderBy(['metric_name' => 'screenPageViews']),
                        'desc' => true,
                    ]),
                ],
                'limit' => $limit * 5,
            ]));

            $byPath = [];
            foreach ($response->getRows() as $row) {
                $dimValues = $row->getDimensionValues();
                $path = $dimValues[0]->getValue();
                $title = $dimValues[1]->getValue();
                $isPrevious = $dimValues[2]->getValue() === 'date_range_1';
                $views = (int) $row->getMetricValues()[0]->getValue();

                if (!isset($byPath[$path])) {
                    $byPath[$path] = ['title' => $title, 'path' => $path, 'views' => 0, 'prevViews' => 0, 'titleViews' => 0];
                }

                if ($isPrevious) {
                    $byPath[$path]['prevViews'] += $views;
                    continue;
                }

                $byPath[$path]['views'] += $views;
                if ($views > $byPath[$path]['titleViews']) {
                    $byPath[$path]['title'] = $title;
                    $byPath[$path]['titleViews'] = $views;
                }
            }

            // Keep only pages with current-period traffic, then take the top $limit.
            $byPath = array_filter($byPath, fn($p) => $p['views'] > 0);
            usort($byPath, fn($a, $b) => $b['views'] <=> $a['views']);
            $pages = array_slice($byPath, 0, $limit);

            return array_map(fn($p) => [
                'title' => $p['title'],
                'path' => $p['path'],
                'views' => $p['views'],
                'change' => $this->_percentChange($p['views'], $p['prevViews']),
            ], $pages);
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch top pages', $e);
        }
    }

    private function _fetchTrafficSources(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $dims = [new Dimension(['name' => 'sessionDefaultChannelGroup'])];
            $metrics = [new Metric(['name' => 'sessions'])];

            // Both periods in one request; the trailing `dateRange` dimension separates them.
            $response = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => $this->_comparisonDateRanges(),
                'dimensions' => $dims,
                'metrics' => $metrics,
                'order_bys' => [
                    new OrderBy([
                        'metric' => new MetricOrderBy(['metric_name' => 'sessions']),
                        'desc' => true,
                    ]),
                ],
            ]));

            $current = [];
            $prevLookup = [];
            foreach ($response->getRows() as $row) {
                $dimValues = $row->getDimensionValues();
                $source = $dimValues[0]->getValue();
                $sessions = (int) $row->getMetricValues()[0]->getValue();

                if ($dimValues[1]->getValue() === 'date_range_1') {
                    $prevLookup[$source] = $sessions;
                    continue;
                }

                $current[$source] = $sessions;
            }

            $sources = [];
            foreach ($current as $source => $sessions) {
                $sources[] = [
                    'source' => $source,
                    'sessions' => $sessions,
                    'change' => $this->_percentChange($sessions, $prevLookup[$source] ?? 0),
                ];
            }

            return $sources;
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch traffic sources', $e);
        }
    }

    private function _fetchDailyStats(int $days): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $response = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => [new DateRange(['start_date' => "{$days}daysAgo", 'end_date' => 'today'])],
                'dimensions' => [new Dimension(['name' => 'date'])],
                'metrics' => [
                    new Metric(['name' => 'activeUsers']),
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'screenPageViews']),
                ],
                'order_bys' => [
                    new OrderBy([
                        'dimension' => new OrderBy\DimensionOrderBy(['dimension_name' => 'date']),
                    ]),
                ],
            ]));

            $daily = [];
            foreach ($response->getRows() as $row) {
                $dateStr = $row->getDimensionValues()[0]->getValue();
                $daily[] = [
                    'date' => substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2),
                    'users' => (int) $row->getMetricValues()[0]->getValue(),
                    'sessions' => (int) $row->getMetricValues()[1]->getValue(),
                    'pageviews' => (int) $row->getMetricValues()[2]->getValue(),
                ];
            }

            return $daily;
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch daily stats', $e);
        }
    }

    private function _fetchKeyEvents(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $metrics = [new Metric(['name' => 'keyEvents'])];

            // Current + previous event counts in one request; the trailing `dateRange`
            // dimension (date_range_0 / date_range_1) separates the two periods.
            $response = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => $this->_comparisonDateRanges(),
                'dimensions' => [new Dimension(['name' => 'eventName'])],
                'metrics' => $metrics,
                'order_bys' => [
                    new OrderBy([
                        'metric' => new MetricOrderBy(['metric_name' => 'keyEvents']),
                        'desc' => true,
                    ]),
                ],
            ]));

            $currentCounts = [];
            $prevLookup = [];
            foreach ($response->getRows() as $row) {
                $dimValues = $row->getDimensionValues();
                $name = $dimValues[0]->getValue();
                $count = (int) $row->getMetricValues()[0]->getValue();

                if ($dimValues[1]->getValue() === 'date_range_1') {
                    $prevLookup[$name] = $count;
                    continue;
                }

                $currentCounts[$name] = $count;
            }

            $events = [];
            $total = 0;
            foreach ($currentCounts as $name => $count) {
                $total += $count;
                $events[] = [
                    'name' => $name,
                    'count' => $count,
                    'change' => $this->_percentChange($count, $prevLookup[$name] ?? 0),
                ];
            }

            // Previous total spans all events that had key events in the prior period,
            // including any with no current-period activity.
            $prevTotal = array_sum($prevLookup);

            // Fetch detail: event + page title
            $detailDims = [
                new Dimension(['name' => 'eventName']),
                new Dimension(['name' => 'pageTitle']),
                new Dimension(['name' => 'pagePath']),
            ];
            $detailResponse = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => [new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])],
                'dimensions' => $detailDims,
                'metrics' => $metrics,
                'order_bys' => [
                    new OrderBy([
                        'metric' => new MetricOrderBy(['metric_name' => 'keyEvents']),
                        'desc' => true,
                    ]),
                ],
                'limit' => 100,
            ]));

            $details = [];
            foreach ($detailResponse->getRows() as $row) {
                $eventName = $row->getDimensionValues()[0]->getValue();
                $pageTitle = $row->getDimensionValues()[1]->getValue();
                $pagePath = $row->getDimensionValues()[2]->getValue();
                $count = (int) $row->getMetricValues()[0]->getValue();

                if (!isset($details[$eventName])) {
                    $details[$eventName] = [];
                }
                $details[$eventName][] = [
                    'pageTitle' => $pageTitle,
                    'pagePath' => $pagePath,
                    'count' => $count,
                ];
            }

            // Attach details to events
            foreach ($events as &$event) {
                $event['details'] = $details[$event['name']] ?? [];
            }
            unset($event);

            return [
                'events' => $events,
                'total' => $total,
                'totalChange' => $this->_percentChange($total, $prevTotal),
            ];
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch key events', $e);
        }
    }

    private function _fetchEngagementStats(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $metrics = [
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'screenPageViewsPerSession']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'activeUsers']),
            ];

            // Both periods in one request (row 0 = current, row 1 = previous).
            $response = $client->runReport(new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => $this->_comparisonDateRanges(),
                'metrics' => $metrics,
            ]));

            $rows = iterator_to_array($response->getRows());
            $cur = $rows[0] ?? null;
            $prev = $rows[1] ?? null;

            $avgDuration = $cur ? (float) $cur->getMetricValues()[0]->getValue() : 0;
            $bounceRate = $cur ? (float) $cur->getMetricValues()[1]->getValue() : 0;
            $pagesPerSession = $cur ? (float) $cur->getMetricValues()[2]->getValue() : 0;
            $newUsers = $cur ? (int) $cur->getMetricValues()[3]->getValue() : 0;
            $totalUsers = $cur ? (int) $cur->getMetricValues()[4]->getValue() : 0;

            $prevAvgDuration = $prev ? (float) $prev->getMetricValues()[0]->getValue() : 0;
            $prevBounceRate = $prev ? (float) $prev->getMetricValues()[1]->getValue() : 0;
            $prevPagesPerSession = $prev ? (float) $prev->getMetricValues()[2]->getValue() : 0;
            $prevNewUsers = $prev ? (int) $prev->getMetricValues()[3]->getValue() : 0;

            $returningUsers = $totalUsers - $newUsers;

            return [
                'avgDuration' => round($avgDuration),
                'avgDurationChange' => $this->_percentChange((int) round($avgDuration), (int) round($prevAvgDuration)),
                'bounceRate' => round($bounceRate * 100, 1),
                'bounceRateChange' => $prevBounceRate > 0
                    ? round((($bounceRate - $prevBounceRate) / $prevBounceRate) * 100, 1)
                    : 0.0,
                'pagesPerSession' => round($pagesPerSession, 1),
                'pagesPerSessionChange' => $prevPagesPerSession > 0
                    ? round((($pagesPerSession - $prevPagesPerSession) / $prevPagesPerSession) * 100, 1)
                    : 0.0,
                'newUsers' => $newUsers,
                'newUsersChange' => $this->_percentChange($newUsers, $prevNewUsers),
                'returningUsers' => $returningUsers,
            ];
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch engagement stats', $e);
        }
    }

    private function _fetchSearchConsoleStats(): array
    {
        try {
            $service = $this->_getSearchConsoleService();
            $siteUrl = $this->_getSearchConsoleSiteUrl();

            $endDate = date('Y-m-d', strtotime('-2 days'));
            $startDate = date('Y-m-d', strtotime('-31 days'));
            $prevEndDate = date('Y-m-d', strtotime('-32 days'));
            $prevStartDate = date('Y-m-d', strtotime('-61 days'));

            // Current period — totals
            $totalRequest = new SearchAnalyticsQueryRequest();
            $totalRequest->setStartDate($startDate);
            $totalRequest->setEndDate($endDate);
            $totalRequest->setType('web');
            $totalResponse = $service->searchanalytics->query($siteUrl, $totalRequest);

            $currentTotals = $totalResponse->getRows()[0] ?? null;

            // Previous period — totals
            $prevTotalRequest = new SearchAnalyticsQueryRequest();
            $prevTotalRequest->setStartDate($prevStartDate);
            $prevTotalRequest->setEndDate($prevEndDate);
            $prevTotalRequest->setType('web');
            $prevTotalResponse = $service->searchanalytics->query($siteUrl, $prevTotalRequest);

            $prevTotals = $prevTotalResponse->getRows()[0] ?? null;

            $clicks = $currentTotals ? (int) $currentTotals->getClicks() : 0;
            $impressions = $currentTotals ? (int) $currentTotals->getImpressions() : 0;
            $ctr = $currentTotals ? round($currentTotals->getCtr() * 100, 1) : 0;
            $position = $currentTotals ? round($currentTotals->getPosition(), 1) : 0;

            $prevClicks = $prevTotals ? (int) $prevTotals->getClicks() : 0;
            $prevImpressions = $prevTotals ? (int) $prevTotals->getImpressions() : 0;
            $prevCtr = $prevTotals ? round($prevTotals->getCtr() * 100, 1) : 0;
            $prevPosition = $prevTotals ? round($prevTotals->getPosition(), 1) : 0;

            // Top queries — current
            $queryRequest = new SearchAnalyticsQueryRequest();
            $queryRequest->setStartDate($startDate);
            $queryRequest->setEndDate($endDate);
            $queryRequest->setDimensions(['query']);
            $queryRequest->setType('web');
            $queryRequest->setRowLimit(10);
            $queryResponse = $service->searchanalytics->query($siteUrl, $queryRequest);

            // Top queries — previous
            $prevQueryRequest = new SearchAnalyticsQueryRequest();
            $prevQueryRequest->setStartDate($prevStartDate);
            $prevQueryRequest->setEndDate($prevEndDate);
            $prevQueryRequest->setDimensions(['query']);
            $prevQueryRequest->setType('web');
            $prevQueryRequest->setRowLimit(50);
            $prevQueryResponse = $service->searchanalytics->query($siteUrl, $prevQueryRequest);

            $prevQueryLookup = [];
            foreach ($prevQueryResponse->getRows() as $row) {
                $prevQueryLookup[$row->getKeys()[0]] = [
                    'clicks' => (int) $row->getClicks(),
                    'impressions' => (int) $row->getImpressions(),
                ];
            }

            $queries = [];
            foreach ($queryResponse->getRows() as $row) {
                $query = $row->getKeys()[0];
                $curClicks = (int) $row->getClicks();
                $curImpressions = (int) $row->getImpressions();
                $prev = $prevQueryLookup[$query] ?? ['clicks' => 0, 'impressions' => 0];

                $queries[] = [
                    'query' => $query,
                    'clicks' => $curClicks,
                    'impressions' => $curImpressions,
                    'ctr' => round($row->getCtr() * 100, 1),
                    'position' => round($row->getPosition(), 1),
                    'clicksChange' => $this->_percentChange($curClicks, $prev['clicks']),
                    'impressionsChange' => $this->_percentChange($curImpressions, $prev['impressions']),
                ];
            }

            // Top pages — current
            $pageRequest = new SearchAnalyticsQueryRequest();
            $pageRequest->setStartDate($startDate);
            $pageRequest->setEndDate($endDate);
            $pageRequest->setDimensions(['page']);
            $pageRequest->setType('web');
            $pageRequest->setRowLimit(10);
            $pageResponse = $service->searchanalytics->query($siteUrl, $pageRequest);

            // Top pages — previous
            $prevPageRequest = new SearchAnalyticsQueryRequest();
            $prevPageRequest->setStartDate($prevStartDate);
            $prevPageRequest->setEndDate($prevEndDate);
            $prevPageRequest->setDimensions(['page']);
            $prevPageRequest->setType('web');
            $prevPageRequest->setRowLimit(50);
            $prevPageResponse = $service->searchanalytics->query($siteUrl, $prevPageRequest);

            $prevPageLookup = [];
            foreach ($prevPageResponse->getRows() as $row) {
                $prevPageLookup[$row->getKeys()[0]] = [
                    'clicks' => (int) $row->getClicks(),
                    'impressions' => (int) $row->getImpressions(),
                ];
            }

            $pages = [];
            foreach ($pageResponse->getRows() as $row) {
                $url = $row->getKeys()[0];
                $path = parse_url($url, PHP_URL_PATH) ?: $url;
                $curClicks = (int) $row->getClicks();
                $curImpressions = (int) $row->getImpressions();
                $prev = $prevPageLookup[$url] ?? ['clicks' => 0, 'impressions' => 0];

                $pages[] = [
                    'page' => $path,
                    'clicks' => $curClicks,
                    'impressions' => $curImpressions,
                    'ctr' => round($row->getCtr() * 100, 1),
                    'position' => round($row->getPosition(), 1),
                    'clicksChange' => $this->_percentChange($curClicks, $prev['clicks']),
                    'impressionsChange' => $this->_percentChange($curImpressions, $prev['impressions']),
                ];
            }

            return [
                'totals' => [
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $ctr,
                    'position' => $position,
                    'clicksChange' => $this->_percentChange($clicks, $prevClicks),
                    'impressionsChange' => $this->_percentChange($impressions, $prevImpressions),
                    'ctrChange' => $prevCtr > 0 ? round((($ctr - $prevCtr) / $prevCtr) * 100, 1) : 0.0,
                    'positionChange' => $prevPosition > 0 ? round((($position - $prevPosition) / $prevPosition) * 100, 1) : 0.0,
                ],
                'queries' => $queries,
                'pages' => $pages,
            ];
        } catch (\Throwable $e) {
            return $this->_error('Failed to fetch Search Console stats', $e);
        }
    }

    private function _getSearchConsoleService(): SearchConsole
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);
        $refreshToken = $settings->oauthRefreshToken;

        if (empty($refreshToken)) {
            throw new \RuntimeException('Not connected to Google. Please authorize in plugin settings.');
        }

        try {
            $client = new GoogleClient();
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setAccessType('offline');
            $client->fetchAccessTokenWithRefreshToken($refreshToken);

            return new SearchConsole($client);
        } catch (\Throwable $e) {
            Craft::error('Analytics: Search Console token refresh failed: ' . $e->getMessage(), __METHOD__);
            $this->disconnect();
            throw new \RuntimeException('Google authentication expired. Please reconnect in plugin settings.');
        }
    }

    private function _getSearchConsoleSiteUrl(): string
    {
        $settings = Plugin::$plugin->getSettings();
        $siteUrl = App::parseEnv($settings->searchConsoleSiteUrl);

        if (empty($siteUrl)) {
            // Fallback to primary site URL
            $siteUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        }

        return rtrim($siteUrl, '/') . '/';
    }

    private function _getClient(): BetaAnalyticsDataClient
    {
        if ($this->_client === null) {
            $settings = Plugin::$plugin->getSettings();
            $clientId = App::parseEnv($settings->oauthClientId);
            $clientSecret = App::parseEnv($settings->oauthClientSecret);
            $refreshToken = $settings->oauthRefreshToken;

            if (empty($refreshToken)) {
                throw new \RuntimeException('Not connected to Google Analytics. Please authorize in plugin settings.');
            }

            try {
                $credentials = new UserRefreshCredentials(
                    $this->_getScopes(),
                    [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $refreshToken,
                    ]
                );

                $this->_client = new BetaAnalyticsDataClient([
                    'credentials' => $credentials,
                ]);
            } catch (\Throwable $e) {
                Craft::error('Analytics: OAuth token refresh failed: ' . $e->getMessage(), __METHOD__);
                $this->disconnect();
                throw new \RuntimeException('Google authentication expired. Please reconnect in plugin settings.');
            }
        }

        return $this->_client;
    }

    /**
     * Logs the underlying exception and returns a generic, user-safe error payload.
     *
     * Google client exceptions can embed request URLs or partial credentials, so the
     * raw message is kept to the logs and never surfaced in the control panel.
     */
    private function _error(string $context, \Throwable $e): array
    {
        Craft::error("Analytics: {$context}: " . $e->getMessage(), __METHOD__);

        return ['error' => Craft::t('craft-analytics', 'Unable to load analytics data. Check the logs for details.')];
    }

    /**
     * Returns the current (last 30 days) and previous (the 30 days before that) date
     * ranges, in that order. GA4 labels them `date_range_0` and `date_range_1` on each row.
     */
    private function _comparisonDateRanges(): array
    {
        return [
            new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today']),
            new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo']),
        ];
    }

    private function _percentChange(float|int $current, float|int $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    private function _getPropertyId(): string
    {
        $settings = Plugin::$plugin->getSettings();
        $propertyId = App::parseEnv($settings->propertyId);

        if (empty($propertyId)) {
            throw new \RuntimeException('GA4 Property ID is not configured.');
        }

        if (!str_starts_with($propertyId, 'properties/')) {
            $propertyId = 'properties/' . $propertyId;
        }

        return $propertyId;
    }

    private function _getScopes(): array
    {
        $scopes = [self::SCOPE_ANALYTICS];

        if (Plugin::$plugin->getSettings()->enableSearchConsole) {
            $scopes[] = self::SCOPE_SEARCH_CONSOLE;
        }

        return $scopes;
    }

    public function isSearchConsoleEnabled(): bool
    {
        return Plugin::$plugin->getSettings()->enableSearchConsole;
    }
}