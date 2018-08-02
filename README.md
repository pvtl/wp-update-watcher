# WP Update Watcher

WordPress plugin that monitors installation for core, plugin and theme updates and emails the client when they are available.

## Bedrock Installation / Upgrade

Add the git repository to `composer.json`

```
"repositories": [
  ...
  {
    "type": "git-bitbucket",
    "url": "https://bitbucket.org/pvtl/wp-update-watcher"
  }
]
```

Then just add the package as a requirement, WP Update Watcher will automatically install into the plugins directory set in `composer.json`.

```
"require": {
  ...
  "pvtl/wp-update-watcher": "dev-master"
}
```
