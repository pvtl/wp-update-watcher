# WP Update Watcher

WordPress plugin that monitors installation for core, plugin and theme updates and emails the client when they are available.

## Installation into a Bedrock site

#### Install

```
# 1. Get it ready (to use a repo outside of packagist)
composer config repositories.wp-update-watcher git https://github.com/pvtl/wp-update-watcher

# 2. Install the Plugin
composer require pvtl/wp-update-watcher
```

#### Activate / Configure

- Activate the plugin
- Settings > Update Watcher:
    - For now, please set the cron to `Other Cron` and manually setup a cron for once a month (to start 1 month after the site is launched)
    - Set the correct emails
    - Set the recipient's name
    - Only notify for active themes/plugins

#### [Maintainers Note] Publishing Updates

To update the plugin and make it available for updating via composer, follow these steps:
1. Push your changes to `master` branch
2. Check existing tags using `git tag`. This command will print the list of tags for this repository. In our case, git tags are plugin versions.
3. Create a new tag via `git tag <tagname>`. Example: If the current plugin version is 1.0.6 the next will be 1.0.7, so we need to run `git tag 1.0.7`
4. Push the tag to the repo `git push origin <tagname>`. In our case, `git push origin 1.0.7`
