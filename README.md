# WP Update Watcher

WordPress plugin that monitors installation for core, plugin and theme updates and emails the client when they are available.

## Bedrock Installation / Upgrade

#### Step 1. Get it ready (to use a repo outside of packagist)

Add the git _repository_ to `composer.json`:

```
  "repositories": [
    ...
    {
      "type": "git",
      "url": "https://bitbucket.org/pvtl/wp-update-watcher"
    }
  ],
```


#### Step 2. Install the Plugin

Then simply composer require the plugin:

```
composer require pvtl/wp-update-watcher
```

#### Step 3. Activate

- Activate the plugin
- Settings > Update Watcher:
    - For now, please set the cron to `Other Cron` and manually setup a cron for once a month (to start 1 month after the site is launched)
    - Set the correct emails
    - Only notify for active themes/plugins
