# WP Update Watcher

WordPress plugin that monitors installation for core, plugin and theme updates and emails the client when they are available.

## Bedrock Installation / Upgrade

#### Step 1.

Add the git _repository_ AND _minimum-stability_ to `composer.json`:

```
  "repositories": [
    ...
    {
      "type": "git",
      "url": "https://bitbucket.org/pvtl/wp-update-watcher"
    }
  ],
  "minimum-stability": "dev",
```


#### Step 2.

Then simply composer require the plugin:

```
  composer require pvtl/wp-update-watcher
```
