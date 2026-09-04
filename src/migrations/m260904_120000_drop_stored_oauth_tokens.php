<?php

namespace sidecar\craftga\migrations;

use Craft;
use craft\db\Migration;

/**
 * Removes the access token and its expiry from plugin settings.
 *
 * Both were persisted to project config by earlier versions even though the
 * Google client derives access tokens from the refresh token on its own. The
 * refresh token itself is left in place: it should be moved to `.env` by hand
 * (the settings screen explains how), since a migration cannot write secrets
 * to the environment.
 */
class m260904_120000_drop_stored_oauth_tokens extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Skip if the change was already applied from another environment's YAML.
        $schemaVersion = $projectConfig->get('plugins.craft-ga.schemaVersion', true);
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.2.0', '>=')) {
            return true;
        }

        foreach (['oauthAccessToken', 'oauthExpiresAt'] as $key) {
            if ($projectConfig->get("plugins.craft-ga.settings.$key") !== null) {
                $projectConfig->remove("plugins.craft-ga.settings.$key");
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260904_120000_drop_stored_oauth_tokens cannot be reverted.\n";
        return false;
    }
}
