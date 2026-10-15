<?php

namespace sidecar\craftga\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use sidecar\craftga\auth\AccessTokenFetcher;
use sidecar\craftga\jobs\RefreshReports;
use sidecar\craftga\models\Settings;
use sidecar\craftga\Plugin;
use Google\Analytics\Data\V1beta\BatchRunReportsRequest;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Google\Client as GoogleClient;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use GuzzleHttp\Exception\RequestException;
use yii\caching\TagDependency;

/**
 * Owns all communication with the GA4 Data API and Search Console.
 *
 * Reports are addressed by a key (see the `REPORT_*` constants) plus optional
 * parameters, and flow through one pipeline: GA4 requests for every requested
 * report are packed into `batchRunReports` calls (5 per call), responses are
 * parsed per report, and each payload is cached individually.
 *
 * The cache is stale-while-revalidate. A report stays "fresh" for the configured
 * cache duration, after which it is still served instantly from cache while a
 * {@see RefreshReports} queue job replaces it in the background. Only a report
 * that has never been cached (or whose cached copy aged past
 * {@see STALE_CACHE_DURATION}) blocks a control panel request on Google.
 */
class AnalyticsService extends Component
{
    // Const Properties
    // =========================================================================

    public const REPORT_SUMMARY = 'summary';
    public const REPORT_TRAFFIC_SOURCES = 'traffic-sources';
    public const REPORT_TOP_PAGES = 'top-pages';
    public const REPORT_TOP_REFERRALS = 'top-referrals';
    public const REPORT_DAILY = 'daily';
    public const REPORT_KEY_EVENTS = 'key-events';
    public const REPORT_ENGAGEMENT = 'engagement';
    public const REPORT_SEARCH_CONSOLE = 'search-console';

    private const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';
    private const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const CACHE_PREFIX = 'craft-ga:';
    private const CACHE_TAG = 'craft-ga';
    private const REFRESH_LOCK_KEY = self::CACHE_PREFIX . 'refresh-lock';

    /**
     * @var int Seconds to cache an error payload. Long enough to absorb a burst of
     * dashboard loads during an API outage, short enough to recover quickly.
     */
    private const ERROR_CACHE_DURATION = 30;

    /**
     * @var int Hard TTL, in seconds, of a successful report payload. Past the
     * configured cache duration the payload is stale and refreshed in the
     * background; past this it is gone and the next request fetches synchronously.
     */
    private const STALE_CACHE_DURATION = 86400;

    /**
     * @var int Seconds during which stale reads won't queue another refresh job,
     * so a busy dashboard doesn't pile up duplicate jobs while one is running.
     */
    private const REFRESH_LOCK_DURATION = 120;

    /**
     * @var int Maximum number of report requests GA4 accepts in one `batchRunReports` call.
     */
    private const BATCH_SIZE = 5;

    /**
     * @var int Deadline in milliseconds for a single GA4 `runReport` call, so a slow or
     * unreachable Google API can't hang a CP request on the client's ~60s default.
     */
    private const GA_CALL_TIMEOUT_MS = 15000;

    /**
     * @var int Deadline in milliseconds for a GA4 `batchRunReports` call, which does up
     * to {@see BATCH_SIZE} reports' worth of work.
     */
    private const GA_BATCH_TIMEOUT_MS = 30000;

    /**
     * @var int Timeout in seconds for Search Console HTTP calls.
     */
    private const SEARCH_CONSOLE_TIMEOUT = 15;

    /**
     * @var int Timeout in seconds for calls to Google's OAuth token endpoint.
     */
    private const TOKEN_TIMEOUT = 10;

    /**
     * @var int Seconds before its expiry at which a cached access token is considered expired.
     */
    private const TOKEN_EXPIRY_MARGIN = 60;

    // Private Properties
    // =========================================================================

    /**
     * @var BetaAnalyticsDataClient|null Lazily built GA4 Data API client.
     */
    private ?BetaAnalyticsDataClient $_client = null;

    // Public Methods
    // =========================================================================

    /**
     * Whether a property, OAuth client, and refresh token are all configured.
     */
    public function isConfigured(): bool
    {
        $settings = Plugin::$plugin->getSettings();
        $propertyId = App::parseEnv($settings->propertyId);
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);

        return !empty($propertyId) && !empty($clientId) && !empty($clientSecret) && $settings->isConnected();
    }

    /**
     * Whether the Search Console panel is enabled in settings.
     */
    public function isSearchConsoleEnabled(): bool
    {
        return Plugin::$plugin->getSettings()->enableSearchConsole;
    }

    /**
     * Builds the Google consent screen URL for the OAuth flow.
     */
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

    /**
     * The OAuth redirect URI registered with Google, without any dynamic query
     * string (e.g. `?site=en`) Craft may append, so it always matches exactly.
     */
    public function getRedirectUri(): string
    {
        $url = UrlHelper::cpUrl('craft-ga/oauth/callback', null, 'https');

        if (($pos = strpos($url, '?')) !== false) {
            $url = substr($url, 0, $pos);
        }

        return $url;
    }

    /**
     * Exchanges the OAuth authorization code for tokens and returns the refresh token.
     *
     * Nothing is persisted here on purpose: a refresh token written to plugin
     * settings ends up in project config (and therefore in version control). The
     * caller shows it once so it can be added to `.env`, and the setting only ever
     * holds a `$ENV_VAR` reference to it. If the setting is still empty, the default
     * reference is saved so the settings screen is ready for the variable.
     *
     * @throws \RuntimeException When Google does not return a refresh token.
     * @throws \GuzzleHttp\Exception\GuzzleException On a failed token endpoint call.
     */
    public function handleOAuthCallback(string $code): string
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);
        $redirectUri = $this->getRedirectUri();

        $response = Craft::createGuzzleClient(['timeout' => self::TOKEN_TIMEOUT])->post(self::TOKEN_ENDPOINT, [
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

        if ($settings->oauthRefreshToken === '') {
            Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, [
                'oauthRefreshToken' => '$' . Settings::DEFAULT_REFRESH_TOKEN_ENV,
            ]);
        }

        $this->clearCache();

        return $data['refresh_token'];
    }

    /**
     * Forgets the connection. A literal token is cleared from the settings; an
     * environment reference is left as-is since the token itself lives in `.env`.
     */
    public function disconnect(): void
    {
        $settings = Plugin::$plugin->getSettings();

        if ($settings->oauthRefreshToken !== '' && !$settings->isRefreshTokenEnvReference()) {
            Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, ['oauthRefreshToken' => '']);
        }

        $this->clearCache();
    }

    /**
     * Returns the reports the CP dashboard displays, keyed by report with their
     * default parameters. Search Console is included only when enabled.
     *
     * @return array<string, array>
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function getDashboardSpecs(): array
    {
        $specs = [
            self::REPORT_SUMMARY => [],
            self::REPORT_TOP_PAGES => ['limit' => 10],
            self::REPORT_TRAFFIC_SOURCES => [],
            self::REPORT_TOP_REFERRALS => ['limit' => 10],
            self::REPORT_DAILY => ['days' => 30],
            self::REPORT_KEY_EVENTS => [],
            self::REPORT_ENGAGEMENT => [],
        ];

        if ($this->isSearchConsoleEnabled()) {
            $specs[self::REPORT_SEARCH_CONSOLE] = [];
        }

        return $specs;
    }

    /**
     * Returns every dashboard report in one pass, so all uncached GA4 requests
     * travel in as few batched API calls as possible.
     *
     * @return array<string, array> Report key => payload (data, or `['error' => string]`).
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function getDashboardData(): array
    {
        return $this->_getReports($this->getDashboardSpecs());
    }

    /**
     * Users, sessions and pageviews for today, the last 7 and the last 30 days,
     * each with a percentage change against the preceding period.
     */
    public function getSummaryStats(): array
    {
        return $this->_getReports([self::REPORT_SUMMARY => []])[self::REPORT_SUMMARY];
    }

    /**
     * Sessions per default channel group over the last 30 days.
     */
    public function getTrafficSources(): array
    {
        return $this->_getReports([self::REPORT_TRAFFIC_SOURCES => []])[self::REPORT_TRAFFIC_SOURCES];
    }

    /**
     * Most viewed pages over the last 30 days.
     */
    public function getTopPages(int $limit = 10): array
    {
        return $this->_getReports([self::REPORT_TOP_PAGES => ['limit' => $limit]])[self::REPORT_TOP_PAGES];
    }

    /**
     * Top referring sources over the last 30 days.
     */
    public function getTopReferrals(int $limit = 10): array
    {
        return $this->_getReports([self::REPORT_TOP_REFERRALS => ['limit' => $limit]])[self::REPORT_TOP_REFERRALS];
    }

    /**
     * Daily users, sessions and pageviews for the last `$days` days.
     */
    public function getDailyStats(int $days = 30): array
    {
        return $this->_getReports([self::REPORT_DAILY => ['days' => $days]])[self::REPORT_DAILY];
    }

    /**
     * Key events over the last 30 days, with per-page detail.
     */
    public function getKeyEvents(): array
    {
        return $this->_getReports([self::REPORT_KEY_EVENTS => []])[self::REPORT_KEY_EVENTS];
    }

    /**
     * Engagement metrics over the last 30 days.
     */
    public function getEngagementStats(): array
    {
        return $this->_getReports([self::REPORT_ENGAGEMENT => []])[self::REPORT_ENGAGEMENT];
    }

    /**
     * Search Console totals, top queries and top pages for the last 30 days.
     */
    public function getSearchConsoleStats(): array
    {
        return $this->_getReports([self::REPORT_SEARCH_CONSOLE => []])[self::REPORT_SEARCH_CONSOLE];
    }

    /**
     * Fetches the given reports from Google regardless of cache state and replaces
     * their cached copies. Used by the {@see RefreshReports} queue job and the
     * `craft-ga/cache/warm` console command.
     *
     * A report that fails to fetch keeps its existing good cached copy (served
     * stale) rather than being replaced by an error.
     *
     * @param array<string, array> $specs Report key => parameters.
     * @return array<string, array> Report key => payload that was fetched.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function refreshReports(array $specs): array
    {
        $cache = Craft::$app->getCache();
        $payloads = $this->_fetchReports($specs);
        $hadError = false;

        foreach ($payloads as $key => $payload) {
            if (isset($payload['error'])) {
                $hadError = true;
                $existing = $cache->get($this->_cacheKey($key, $specs[$key]));

                if (is_array($existing) && !isset($existing['data']['error'])) {
                    continue;
                }
            }

            $this->_store($key, $specs[$key], $payload);
        }

        // On failure the lock expires on its own, which spaces out retries during an outage.
        if (!$hadError) {
            $cache->delete(self::REFRESH_LOCK_KEY);
        }

        return $payloads;
    }

    /**
     * Drops every cached report and the refresh lock. The next load fetches synchronously.
     */
    public function clearCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    // Private Methods
    // =========================================================================

    /**
     * Serves the requested reports from cache, fetching what is missing and
     * scheduling a background refresh for what is stale.
     *
     * When anything is missing, stale reports ride along in the same synchronous
     * fetch: with batching the extra requests cost almost nothing, and it avoids
     * queuing a job for data that is about to be fresh anyway.
     *
     * @param array<string, array> $specs Report key => parameters.
     * @return array<string, array> Report key => payload, in the order of `$specs`.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _getReports(array $specs): array
    {
        $cache = Craft::$app->getCache();
        // Yii treats 0 as "never expire"; clamp so a stored 0 from an older install can't disable refreshes.
        $freshFor = max(1, (int) Plugin::$plugin->getSettings()->cacheDuration);

        $results = [];
        $missing = [];
        $stale = [];

        foreach ($specs as $key => $params) {
            $entry = $cache->get($this->_cacheKey($key, $params));

            // Entries written before 2.3.0 are bare payloads; treat them as a miss.
            if (!is_array($entry) || !array_key_exists('data', $entry)) {
                $missing[$key] = $params;
                continue;
            }

            $results[$key] = $entry['data'];

            if (!isset($entry['data']['error']) && time() - (int) ($entry['time'] ?? 0) >= $freshFor) {
                $stale[$key] = $params;
            }
        }

        if ($missing !== []) {
            $toFetch = $missing + $stale;

            foreach ($this->_fetchReports($toFetch) as $key => $payload) {
                if (isset($payload['error']) && isset($stale[$key])) {
                    // Keep serving the stale copy rather than swapping in an error.
                    continue;
                }

                $this->_store($key, $toFetch[$key], $payload);
                $results[$key] = $payload;
            }
        } elseif ($stale !== []) {
            $this->_queueRefresh($stale);
        }

        $ordered = [];
        foreach (array_keys($specs) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * Pushes a {@see RefreshReports} job for the given reports unless one was
     * queued within the last {@see REFRESH_LOCK_DURATION} seconds.
     *
     * @param array<string, array> $specs
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _queueRefresh(array $specs): void
    {
        $cache = Craft::$app->getCache();

        // add() is a no-op when the key exists, which is what dedupes concurrent requests.
        if (!$cache->add(self::REFRESH_LOCK_KEY, time(), self::REFRESH_LOCK_DURATION, $this->_cacheDependency())) {
            return;
        }

        Craft::$app->getQueue()->push(new RefreshReports(['specs' => $specs]));
    }

    /**
     * Writes a report payload to the cache, wrapped with its fetch time so
     * {@see _getReports()} can tell fresh from stale.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _store(string $key, array $params, array $payload): void
    {
        $duration = isset($payload['error']) ? self::ERROR_CACHE_DURATION : self::STALE_CACHE_DURATION;

        Craft::$app->getCache()->set(
            $this->_cacheKey($key, $params),
            ['data' => $payload, 'time' => time()],
            $duration,
            $this->_cacheDependency(),
        );
    }

    /**
     * Cache key for a report and its parameters, e.g. `craft-ga:top-pages:limit=10`.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _cacheKey(string $key, array $params): string
    {
        ksort($params);

        return self::CACHE_PREFIX . $key . ($params !== [] ? ':' . http_build_query($params) : '');
    }

    /**
     * Returns the tag dependency applied to every cached entry, so {@see clearCache()}
     * can invalidate all report variants and the refresh lock in one call.
     */
    private function _cacheDependency(): TagDependency
    {
        return new TagDependency(['tags' => self::CACHE_TAG]);
    }

    /**
     * Fetches the given reports from Google, bypassing the cache.
     *
     * GA4 reports are packed into `batchRunReports` calls of up to {@see BATCH_SIZE}
     * requests. A report's requests never straddle two batches, so a failed batch
     * yields an error payload for exactly the reports it carried. Search Console
     * has its own API and is fetched separately.
     *
     * @param array<string, array> $specs Report key => parameters.
     * @return array<string, array> Report key => payload, in the order of `$specs`.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _fetchReports(array $specs): array
    {
        $results = [];
        $pending = [];

        foreach ($specs as $key => $params) {
            if ($key === self::REPORT_SEARCH_CONSOLE) {
                $results[$key] = $this->_fetchSearchConsoleStats();
                continue;
            }

            try {
                $pending[$key] = $this->_buildRequests($key, $params);
            } catch (\Throwable $e) {
                $results[$key] = $this->_error("Failed to build the {$key} report", $e);
            }
        }

        foreach ($this->_packBatches($pending) as $batch) {
            try {
                $responses = $this->_runReports($batch['requests']);
            } catch (\Throwable $e) {
                foreach (array_keys($batch['keys']) as $key) {
                    $results[$key] = $this->_error("Failed to fetch the {$key} report", $e);
                }
                continue;
            }

            $offset = 0;
            foreach ($batch['keys'] as $key => $count) {
                $slice = array_slice($responses, $offset, $count);
                $offset += $count;

                try {
                    $results[$key] = $this->_parseReport($key, $specs[$key], $slice);
                } catch (\Throwable $e) {
                    $results[$key] = $this->_error("Failed to parse the {$key} report", $e);
                }
            }
        }

        $ordered = [];
        foreach (array_keys($specs) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * Groups per-report request lists into batches of at most {@see BATCH_SIZE}
     * requests without splitting a report across batches.
     *
     * @param array<string, RunReportRequest[]> $pending Report key => its requests.
     * @return array<int, array{keys: array<string, int>, requests: RunReportRequest[]}>
     *   Each batch lists the report keys it carries (with their request count, in order) and the flat requests.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _packBatches(array $pending): array
    {
        $batches = [];
        $current = ['keys' => [], 'requests' => []];

        foreach ($pending as $key => $requests) {
            if ($current['requests'] !== [] && count($current['requests']) + count($requests) > self::BATCH_SIZE) {
                $batches[] = $current;
                $current = ['keys' => [], 'requests' => []];
            }

            $current['keys'][$key] = count($requests);
            array_push($current['requests'], ...$requests);
        }

        if ($current['requests'] !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Runs the given requests in one API call and returns the responses in the same order.
     *
     * @param RunReportRequest[] $requests At most {@see BATCH_SIZE} requests.
     * @return RunReportResponse[]
     * @throws \RuntimeException When the client cannot be built or the property is not configured.
     * @throws \Google\ApiCore\ApiException On a failed API call.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _runReports(array $requests): array
    {
        $requests = array_values($requests);

        if (count($requests) === 1) {
            return [$this->_getClient()->runReport($requests[0], ['timeoutMillis' => self::GA_CALL_TIMEOUT_MS])];
        }

        $response = $this->_getClient()->batchRunReports(
            new BatchRunReportsRequest([
                'property' => $this->_getPropertyId(),
                'requests' => $requests,
            ]),
            ['timeoutMillis' => self::GA_BATCH_TIMEOUT_MS],
        );

        return iterator_to_array($response->getReports(), false);
    }

    /**
     * Builds the GA4 request(s) a report needs.
     *
     * @return RunReportRequest[]
     * @throws \InvalidArgumentException For an unknown report key.
     * @throws \RuntimeException When the property ID is not configured.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _buildRequests(string $key, array $params): array
    {
        return match ($key) {
            self::REPORT_SUMMARY => $this->_buildSummaryRequests(),
            self::REPORT_TRAFFIC_SOURCES => [$this->_buildTrafficSourcesRequest()],
            self::REPORT_TOP_PAGES => [$this->_buildTopPagesRequest((int) ($params['limit'] ?? 10))],
            self::REPORT_TOP_REFERRALS => [$this->_buildTopReferralsRequest((int) ($params['limit'] ?? 10))],
            self::REPORT_DAILY => [$this->_buildDailyRequest((int) ($params['days'] ?? 30))],
            self::REPORT_KEY_EVENTS => $this->_buildKeyEventsRequests(),
            self::REPORT_ENGAGEMENT => [$this->_buildEngagementRequest()],
            default => throw new \InvalidArgumentException("Unknown report: {$key}"),
        };
    }

    /**
     * Turns a report's responses (in the order {@see _buildRequests()} produced
     * the requests) into the payload the templates consume.
     *
     * @param RunReportResponse[] $responses
     * @throws \InvalidArgumentException For an unknown report key.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _parseReport(string $key, array $params, array $responses): array
    {
        return match ($key) {
            self::REPORT_SUMMARY => $this->_parseSummary($responses),
            self::REPORT_TRAFFIC_SOURCES => $this->_parseTrafficSources($responses[0]),
            self::REPORT_TOP_PAGES => $this->_parseTopPages($responses[0], (int) ($params['limit'] ?? 10)),
            self::REPORT_TOP_REFERRALS => $this->_parseTopReferrals($responses[0], (int) ($params['limit'] ?? 10)),
            self::REPORT_DAILY => $this->_parseDaily($responses[0]),
            self::REPORT_KEY_EVENTS => $this->_parseKeyEvents($responses[0], $responses[1]),
            self::REPORT_ENGAGEMENT => $this->_parseEngagement($responses[0]),
            default => throw new \InvalidArgumentException("Unknown report: {$key}"),
        };
    }

    // Summary
    // -------------------------------------------------------------------------

    /**
     * One request per period (today, 7 days, 30 days), each carrying the current
     * and the preceding range. GA4 allows up to 4 date ranges per request, so the
     * 6 ranges can't fit in one.
     *
     * @return RunReportRequest[]
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildSummaryRequests(): array
    {
        $propertyId = $this->_getPropertyId();
        $metrics = [
            new Metric(['name' => 'activeUsers']),
            new Metric(['name' => 'sessions']),
            new Metric(['name' => 'screenPageViews']),
        ];

        $requests = [];
        foreach ($this->_summaryPeriods() as [$start, $end, $prevStart, $prevEnd]) {
            $requests[] = new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => [
                    new DateRange(['start_date' => $start, 'end_date' => $end]),
                    new DateRange(['start_date' => $prevStart, 'end_date' => $prevEnd]),
                ],
                'metrics' => $metrics,
            ]);
        }

        return $requests;
    }

    /**
     * @param RunReportResponse[] $responses One per period, in {@see _summaryPeriods()} order.
     */
    private function _parseSummary(array $responses): array
    {
        $result = [];
        $metricNames = ['users', 'sessions', 'pageviews'];

        foreach (array_keys($this->_summaryPeriods()) as $index => $period) {
            $rows = iterator_to_array($responses[$index]->getRows(), false);
            $current = $rows[0] ?? null;
            $previous = $rows[1] ?? null;

            $currentValues = [];
            $previousValues = [];
            foreach ($metricNames as $position => $metric) {
                $currentValues[$metric] = $current ? (int) $current->getMetricValues()[$position]->getValue() : 0;
                $previousValues[$metric] = $previous ? (int) $previous->getMetricValues()[$position]->getValue() : 0;
            }

            $result[$period] = $currentValues;
            $result[$period]['change'] = [];
            foreach ($metricNames as $metric) {
                $result[$period]['change'][$metric] = $this->_percentChange($currentValues[$metric], $previousValues[$metric]);
            }
        }

        return $result;
    }

    /**
     * Period key => [start, end, previousStart, previousEnd], as GA4 relative dates.
     *
     * @return array<string, string[]>
     */
    private function _summaryPeriods(): array
    {
        return [
            'today' => ['today', 'today', 'yesterday', 'yesterday'],
            'week' => ['7daysAgo', 'today', '14daysAgo', '8daysAgo'],
            'month' => ['30daysAgo', 'today', '60daysAgo', '31daysAgo'],
        ];
    }

    // Top pages
    // -------------------------------------------------------------------------

    /**
     * Both periods in one request. With multiple date ranges GA4 appends a
     * synthetic `dateRange` dimension (date_range_0 = current, date_range_1 = previous)
     * as the last dimension on each row. Over-fetches so previous-period rows for
     * the top current pages are present even when the two periods rank differently.
     *
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildTopPagesRequest(int $limit): RunReportRequest
    {
        return new RunReportRequest([
            'property' => $this->_getPropertyId(),
            'date_ranges' => $this->_comparisonDateRanges(),
            'dimensions' => [
                new Dimension(['name' => 'pagePath']),
                new Dimension(['name' => 'pageTitle']),
            ],
            'metrics' => [new Metric(['name' => 'screenPageViews'])],
            'order_bys' => [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'screenPageViews']),
                    'desc' => true,
                ]),
            ],
            'limit' => $limit * 5,
        ]);
    }

    private function _parseTopPages(RunReportResponse $response, int $limit): array
    {
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
    }

    // Traffic sources
    // -------------------------------------------------------------------------

    /**
     * Both periods in one request; the trailing `dateRange` dimension separates them.
     *
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildTrafficSourcesRequest(): RunReportRequest
    {
        return new RunReportRequest([
            'property' => $this->_getPropertyId(),
            'date_ranges' => $this->_comparisonDateRanges(),
            'dimensions' => [new Dimension(['name' => 'sessionDefaultChannelGroup'])],
            'metrics' => [new Metric(['name' => 'sessions'])],
            'order_bys' => [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'sessions']),
                    'desc' => true,
                ]),
            ],
        ]);
    }

    private function _parseTrafficSources(RunReportResponse $response): array
    {
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
    }

    // Top referrals
    // -------------------------------------------------------------------------

    /**
     * Top referral sources (e.g. `lapresse.ca`) for the last 30 days, with a
     * vs-previous-30-days change per source.
     *
     * Sessions are filtered to GA4's "Referral" default channel group, so search
     * engines, direct traffic, and social networks don't crowd out true referrers.
     * Over-fetches so previous-period rows for the top current sources are present
     * even when the two periods rank differently.
     *
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildTopReferralsRequest(int $limit): RunReportRequest
    {
        return new RunReportRequest([
            'property' => $this->_getPropertyId(),
            'date_ranges' => $this->_comparisonDateRanges(),
            'dimensions' => [new Dimension(['name' => 'sessionSource'])],
            'metrics' => [new Metric(['name' => 'sessions'])],
            'dimension_filter' => new FilterExpression([
                'filter' => new Filter([
                    'field_name' => 'sessionDefaultChannelGroup',
                    'string_filter' => new StringFilter([
                        'match_type' => MatchType::EXACT,
                        'value' => 'Referral',
                    ]),
                ]),
            ]),
            'order_bys' => [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'sessions']),
                    'desc' => true,
                ]),
            ],
            'limit' => $limit * 5,
        ]);
    }

    private function _parseTopReferrals(RunReportResponse $response, int $limit): array
    {
        $current = [];
        $prevLookup = [];
        foreach ($response->getRows() as $row) {
            $dimValues = $row->getDimensionValues();
            $source = $dimValues[0]->getValue();
            $sessions = (int) $row->getMetricValues()[0]->getValue();

            if ($dimValues[1]->getValue() === 'date_range_1') {
                $prevLookup[$source] = ($prevLookup[$source] ?? 0) + $sessions;
                continue;
            }

            $current[$source] = ($current[$source] ?? 0) + $sessions;
        }

        arsort($current);
        $current = array_slice($current, 0, $limit, true);

        $referrals = [];
        foreach ($current as $source => $sessions) {
            $referrals[] = [
                'source' => $source,
                'sessions' => $sessions,
                'change' => $this->_percentChange($sessions, $prevLookup[$source] ?? 0),
            ];
        }

        return $referrals;
    }

    // Daily
    // -------------------------------------------------------------------------

    /**
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildDailyRequest(int $days): RunReportRequest
    {
        return new RunReportRequest([
            'property' => $this->_getPropertyId(),
            'date_ranges' => [new DateRange(['start_date' => "{$days}daysAgo", 'end_date' => 'today'])],
            'dimensions' => [new Dimension(['name' => 'date'])],
            'metrics' => [
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'screenPageViews']),
            ],
            'order_bys' => [
                new OrderBy([
                    'dimension' => new DimensionOrderBy(['dimension_name' => 'date']),
                ]),
            ],
        ]);
    }

    private function _parseDaily(RunReportResponse $response): array
    {
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
    }

    // Key events
    // -------------------------------------------------------------------------

    /**
     * Two requests: current + previous event counts (the trailing `dateRange`
     * dimension separates the periods), then per-page detail for the current period.
     *
     * @return RunReportRequest[]
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildKeyEventsRequests(): array
    {
        $propertyId = $this->_getPropertyId();
        $metrics = [new Metric(['name' => 'keyEvents'])];
        $orderBys = [
            new OrderBy([
                'metric' => new MetricOrderBy(['metric_name' => 'keyEvents']),
                'desc' => true,
            ]),
        ];

        return [
            new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => $this->_comparisonDateRanges(),
                'dimensions' => [new Dimension(['name' => 'eventName'])],
                'metrics' => $metrics,
                'order_bys' => $orderBys,
            ]),
            new RunReportRequest([
                'property' => $propertyId,
                'date_ranges' => [new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])],
                'dimensions' => [
                    new Dimension(['name' => 'eventName']),
                    new Dimension(['name' => 'pageTitle']),
                    new Dimension(['name' => 'pagePath']),
                ],
                'metrics' => $metrics,
                'order_bys' => $orderBys,
                'limit' => 100,
            ]),
        ];
    }

    private function _parseKeyEvents(RunReportResponse $response, RunReportResponse $detailResponse): array
    {
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

        $details = [];
        foreach ($detailResponse->getRows() as $row) {
            $eventName = $row->getDimensionValues()[0]->getValue();
            $details[$eventName][] = [
                'pageTitle' => $row->getDimensionValues()[1]->getValue(),
                'pagePath' => $row->getDimensionValues()[2]->getValue(),
                'count' => (int) $row->getMetricValues()[0]->getValue(),
            ];
        }

        foreach ($events as &$event) {
            $event['details'] = $details[$event['name']] ?? [];
        }
        unset($event);

        return [
            'events' => $events,
            'total' => $total,
            'totalChange' => $this->_percentChange($total, $prevTotal),
        ];
    }

    // Engagement
    // -------------------------------------------------------------------------

    /**
     * Both periods in one request (row 0 = current, row 1 = previous).
     *
     * @throws \RuntimeException When the property ID is not configured.
     */
    private function _buildEngagementRequest(): RunReportRequest
    {
        return new RunReportRequest([
            'property' => $this->_getPropertyId(),
            'date_ranges' => $this->_comparisonDateRanges(),
            'metrics' => [
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'screenPageViewsPerSession']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'activeUsers']),
            ],
        ]);
    }

    private function _parseEngagement(RunReportResponse $response): array
    {
        $rows = iterator_to_array($response->getRows(), false);
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
            'returningUsers' => $totalUsers - $newUsers,
        ];
    }

    // Search Console
    // -------------------------------------------------------------------------

    /**
     * Totals, top queries and top pages for the last 30 days (ending 2 days ago,
     * Search Console's data lag), each compared with the preceding 30 days.
     */
    private function _fetchSearchConsoleStats(): array
    {
        try {
            $service = $this->_getSearchConsoleService();
            $siteUrl = $this->_getSearchConsoleSiteUrl();

            $endDate = date('Y-m-d', strtotime('-2 days'));
            $startDate = date('Y-m-d', strtotime('-31 days'));
            $prevEndDate = date('Y-m-d', strtotime('-32 days'));
            $prevStartDate = date('Y-m-d', strtotime('-61 days'));

            $currentTotals = $this->_searchConsoleQuery($service, $siteUrl, $startDate, $endDate)->getRows()[0] ?? null;
            $prevTotals = $this->_searchConsoleQuery($service, $siteUrl, $prevStartDate, $prevEndDate)->getRows()[0] ?? null;

            $clicks = $currentTotals ? (int) $currentTotals->getClicks() : 0;
            $impressions = $currentTotals ? (int) $currentTotals->getImpressions() : 0;
            $ctr = $currentTotals ? round($currentTotals->getCtr() * 100, 1) : 0;
            $position = $currentTotals ? round($currentTotals->getPosition(), 1) : 0;

            $prevClicks = $prevTotals ? (int) $prevTotals->getClicks() : 0;
            $prevImpressions = $prevTotals ? (int) $prevTotals->getImpressions() : 0;
            $prevCtr = $prevTotals ? round($prevTotals->getCtr() * 100, 1) : 0;
            $prevPosition = $prevTotals ? round($prevTotals->getPosition(), 1) : 0;

            $queryResponse = $this->_searchConsoleQuery($service, $siteUrl, $startDate, $endDate, 'query', 10);
            $prevQueryResponse = $this->_searchConsoleQuery($service, $siteUrl, $prevStartDate, $prevEndDate, 'query', 50);

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

            $pageResponse = $this->_searchConsoleQuery($service, $siteUrl, $startDate, $endDate, 'page', 10);
            $prevPageResponse = $this->_searchConsoleQuery($service, $siteUrl, $prevStartDate, $prevEndDate, 'page', 50);

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

    /**
     * Runs one Search Analytics query for web results, optionally broken down by a dimension.
     *
     * @throws \Google\Service\Exception On a failed API call.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _searchConsoleQuery(
        SearchConsole $service,
        string $siteUrl,
        string $startDate,
        string $endDate,
        ?string $dimension = null,
        ?int $rowLimit = null,
    ): SearchConsole\SearchAnalyticsQueryResponse {
        $request = new SearchAnalyticsQueryRequest();
        $request->setStartDate($startDate);
        $request->setEndDate($endDate);
        $request->setType('web');

        if ($dimension !== null) {
            $request->setDimensions([$dimension]);
        }

        if ($rowLimit !== null) {
            $request->setRowLimit($rowLimit);
        }

        return $service->searchanalytics->query($siteUrl, $request);
    }

    /**
     * Builds a Search Console client authenticated with the shared cached access token.
     *
     * @throws \RuntimeException When not connected or the token cannot be obtained (see {@see _getAccessToken()}).
     */
    private function _getSearchConsoleService(): SearchConsole
    {
        $token = $this->_getAccessToken();

        $client = new GoogleClient();
        // Explicit timeout for every Search Console HTTP call so an unreachable
        // Google API can't hang a CP request indefinitely.
        $client->setHttpClient(Craft::createGuzzleClient(['timeout' => self::SEARCH_CONSOLE_TIMEOUT]));
        $client->setAccessToken([
            'access_token' => $token['access_token'],
            'expires_in' => $token['expires_at'] - time(),
            'created' => time(),
        ]);

        return new SearchConsole($client);
    }

    /**
     * The Search Console property URL, falling back to the primary site's base URL.
     */
    private function _getSearchConsoleSiteUrl(): string
    {
        $settings = Plugin::$plugin->getSettings();
        $siteUrl = App::parseEnv($settings->searchConsoleSiteUrl);

        if (empty($siteUrl)) {
            $siteUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        }

        return rtrim($siteUrl, '/') . '/';
    }

    // Google clients and auth
    // -------------------------------------------------------------------------

    /**
     * Lazily builds the GA4 Data API client. Authentication goes through
     * {@see AccessTokenFetcher} so the client reuses the cached access token
     * instead of refreshing its own on every process.
     *
     * @throws \RuntimeException When not connected, or the client cannot be constructed.
     */
    private function _getClient(): BetaAnalyticsDataClient
    {
        if ($this->_client !== null) {
            return $this->_client;
        }

        if (Plugin::$plugin->getSettings()->getRefreshToken() === '') {
            throw new \RuntimeException('Not connected to Google Analytics. Please authorize in plugin settings.');
        }

        try {
            $this->_client = new BetaAnalyticsDataClient([
                'credentials' => new AccessTokenFetcher(fn(): array => $this->_getAccessToken()),
            ]);
        } catch (\Throwable $e) {
            // No network I/O happens here: a failure is a construction/config problem.
            // Token refresh errors surface on the first report call.
            Craft::error('Analytics: could not build the GA4 client: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Could not initialize the Google Analytics client. Check the logs for details.');
        }

        return $this->_client;
    }

    /**
     * Returns a valid access token for the configured refresh token, refreshing it
     * through Google's token endpoint only when the cached one is missing or about
     * to expire. Google access tokens last an hour, so this turns two refreshes per
     * cold dashboard load into roughly one per hour.
     *
     * The cache key hashes the OAuth credentials, so changing the client or the
     * refresh token naturally invalidates the cached token. The token is kept out
     * of logs and error messages.
     *
     * @return array{access_token: string, expires_at: int}
     * @throws \RuntimeException When not connected, when Google rejects the refresh
     * token (the plugin then disconnects), or when the token endpoint is unreachable.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    private function _getAccessToken(): array
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = (string) App::parseEnv($settings->oauthClientId);
        $clientSecret = (string) App::parseEnv($settings->oauthClientSecret);
        $refreshToken = $settings->getRefreshToken();

        if ($refreshToken === '') {
            throw new \RuntimeException('Not connected to Google. Please authorize in plugin settings.');
        }

        $cache = Craft::$app->getCache();
        $cacheKey = self::CACHE_PREFIX . 'access-token:' . hash('sha256', $clientId . '|' . $clientSecret . '|' . $refreshToken);

        $token = $cache->get($cacheKey);
        if (is_array($token) && !empty($token['access_token']) && ($token['expires_at'] ?? 0) > time() + self::TOKEN_EXPIRY_MARGIN) {
            return $token;
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => self::TOKEN_TIMEOUT])->post(self::TOKEN_ENDPOINT, [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);
        } catch (\Throwable $e) {
            if ($this->_isRevokedGrant($e)) {
                // Google definitively rejected the refresh token (revoked/expired):
                // clear it so the settings screen prompts for a reconnect.
                Craft::error('Analytics: Google refresh token was revoked: ' . $e->getMessage(), __METHOD__);
                $this->disconnect();
                throw new \RuntimeException('Google authentication expired. Please reconnect in plugin settings.');
            }

            // Transient failure (network, timeout, Google 5xx): keep the stored
            // token so a blip at Google doesn't force a full reconnect.
            Craft::error('Analytics: token refresh failed: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Could not reach Google to refresh authentication. Try again later.');
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || empty($data['access_token'])) {
            $error = is_array($data) ? ($data['error'] ?? 'no access token in response') : 'unreadable response';
            Craft::error("Analytics: Google rejected the token refresh: {$error}", __METHOD__);
            throw new \RuntimeException('Google rejected the authentication refresh. Check the logs for details.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $token = [
            'access_token' => (string) $data['access_token'],
            'expires_at' => time() + $expiresIn,
        ];

        // Not tagged on purpose: clearing the report cache shouldn't force a token refresh.
        $cache->set($cacheKey, $token, max(1, $expiresIn - self::TOKEN_EXPIRY_MARGIN));

        return $token;
    }

    /**
     * Returns whether the given exception is Google's token endpoint definitively
     * rejecting the refresh token (`invalid_grant` on a 400/401), as opposed to a
     * transient network or server failure.
     */
    private function _isRevokedGrant(\Throwable $e): bool
    {
        if (!$e instanceof RequestException) {
            return false;
        }

        $response = $e->getResponse();
        if ($response === null) {
            return false;
        }

        if (!in_array($response->getStatusCode(), [400, 401], true)) {
            return false;
        }

        return str_contains((string) $response->getBody(), 'invalid_grant');
    }

    /**
     * The OAuth scopes to request at connect time.
     *
     * @return string[]
     */
    private function _getScopes(): array
    {
        $scopes = [self::SCOPE_ANALYTICS];

        if (Plugin::$plugin->getSettings()->enableSearchConsole) {
            $scopes[] = self::SCOPE_SEARCH_CONSOLE;
        }

        return $scopes;
    }

    /**
     * The GA4 property resource name, e.g. `properties/123456789`.
     *
     * @throws \RuntimeException When the property ID is not configured.
     */
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

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Logs the underlying exception and returns a generic, user-safe error payload.
     *
     * Google client exceptions can embed request URLs or partial credentials, so the
     * raw message is kept to the logs and never surfaced in the control panel.
     */
    private function _error(string $context, \Throwable $e): array
    {
        Craft::error("Analytics: {$context}: " . $e->getMessage(), __METHOD__);

        return ['error' => Craft::t('craft-ga', 'Unable to load analytics data. Check the logs for details.')];
    }

    /**
     * Returns the current (last 30 days) and previous (the 30 days before that) date
     * ranges, in that order. GA4 labels them `date_range_0` and `date_range_1` on each row.
     *
     * @return DateRange[]
     */
    private function _comparisonDateRanges(): array
    {
        return [
            new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today']),
            new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo']),
        ];
    }

    /**
     * Percentage change from `$previous` to `$current`, rounded to one decimal.
     * A rise from zero counts as +100%.
     */
    private function _percentChange(float|int $current, float|int $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }
}
