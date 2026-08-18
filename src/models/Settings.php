<?php

namespace sidecar\craftga\models;

use craft\base\Model;

class Settings extends Model
{
    public string $propertyId = '';
    public bool $enableSearchConsole = false;
    public string $searchConsoleSiteUrl = '';
    public string $oauthClientId = '';
    public string $oauthClientSecret = '';
    public string $oauthAccessToken = '';
    public string $oauthRefreshToken = '';
    public int $oauthExpiresAt = 0;
    public int $cacheDuration = 300;

    public function defineRules(): array
    {
        return [
            [['propertyId', 'searchConsoleSiteUrl', 'oauthClientId', 'oauthClientSecret', 'oauthAccessToken', 'oauthRefreshToken'], 'string'],
            ['enableSearchConsole', 'boolean'],
            ['enableSearchConsole', 'default', 'value' => false],
            ['oauthExpiresAt', 'integer'],
            // Yii treats a duration of 0 as "never expire", so a 0 here would cache
            // API responses forever rather than disabling the cache.
            ['cacheDuration', 'integer', 'min' => 1],
            ['cacheDuration', 'default', 'value' => 300],
        ];
    }

    public function isConnected(): bool
    {
        return !empty($this->oauthRefreshToken);
    }
}
