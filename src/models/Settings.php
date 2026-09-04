<?php

namespace sidecar\craftga\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    /**
     * Name of the environment variable the plugin suggests for the refresh token.
     */
    public const DEFAULT_REFRESH_TOKEN_ENV = 'GA4_OAUTH_REFRESH_TOKEN';

    public string $propertyId = '';
    public bool $enableSearchConsole = false;
    public string $searchConsoleSiteUrl = '';
    public string $oauthClientId = '';
    public string $oauthClientSecret = '';

    /**
     * The Google refresh token, or (recommended) a `$ENV_VAR` reference to it,
     * resolved with App::parseEnv() at use.
     *
     * The plugin never writes a literal token here: the OAuth callback shows the
     * token once so it can be added to `.env`, which keeps it out of project
     * config and version control. Access tokens are short-lived and are fetched
     * by the Google client from the refresh token, so they are never stored.
     */
    public string $oauthRefreshToken = '';

    public int $cacheDuration = 300;

    public function defineRules(): array
    {
        return [
            [['propertyId', 'searchConsoleSiteUrl', 'oauthClientId', 'oauthClientSecret', 'oauthRefreshToken'], 'string'],
            ['enableSearchConsole', 'boolean'],
            ['enableSearchConsole', 'default', 'value' => false],
            // Yii treats a duration of 0 as "never expire", so a 0 here would cache
            // API responses forever rather than disabling the cache.
            ['cacheDuration', 'integer', 'min' => 1],
            ['cacheDuration', 'default', 'value' => 300],
        ];
    }

    /**
     * Returns the resolved refresh token, or an empty string when it is unset or
     * references an environment variable that is not defined in this environment.
     */
    public function getRefreshToken(): string
    {
        if ($this->oauthRefreshToken === '') {
            return '';
        }

        $token = App::parseEnv($this->oauthRefreshToken);

        // parseEnv() hands back the reference untouched when the variable is missing.
        if (!is_string($token) || $token === '' || ($this->isRefreshTokenEnvReference() && $token === $this->oauthRefreshToken)) {
            return '';
        }

        return $token;
    }

    /**
     * Whether the refresh token setting is a `$ENV_VAR` reference rather than a literal token.
     */
    public function isRefreshTokenEnvReference(): bool
    {
        return str_starts_with($this->oauthRefreshToken, '$');
    }

    /**
     * The environment variable name the refresh token is (or should be) stored in.
     */
    public function getRefreshTokenEnvName(): string
    {
        return $this->isRefreshTokenEnvReference()
            ? substr($this->oauthRefreshToken, 1)
            : self::DEFAULT_REFRESH_TOKEN_ENV;
    }

    public function isConnected(): bool
    {
        return $this->getRefreshToken() !== '';
    }
}
