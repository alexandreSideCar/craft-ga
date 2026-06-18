<!-- craftcms-claude-skills -->
# Scaffolding

- Use `craft make <type> --with-docblocks` to scaffold new components (run from a host Craft site that has this plugin installed).
- Never manually create boilerplate the generator handles.
- After scaffolding, customize: add section headers, `@author Side-Car`, `@since` version, `@throws` chains, and register the component in `Plugin.php`.
- Likely generators for this plugin: `service`, `controller`, `widget-type`, `model`, `asset-bundle`, `command`, `queue-job`, `migration`.
- Register new services in `Plugin::config()`, new permissions in the `registerUserPermissions` handler, and new CP routes in the `RegisterUrlRules` handler.
