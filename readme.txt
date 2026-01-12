=== Telegram Post Importer ===
Contributors: qiongwu
Tags: telegram, import, posts, rss
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import posts from a public Telegram channel and create matching WordPress posts.

== Description ==

Telegram Post Importer lets you pull messages from a public Telegram channel (via Telegram's public web view) and import them as WordPress posts. The plugin includes a preview step to select which messages to import and prevents duplicates using stored message IDs.

Features:

* Import messages from a public channel like https://t.me/samplechannelname
* Preview and select which messages to import
* Dedupe via Telegram message ID
* Optional category assignment, author selection, and status control

Limitations:

* Only public channels are supported.
* Media is embedded by URL (not downloaded into the media library).
* Telegram markup changes may require plugin updates.

== Installation ==

1. Upload the `telegram-post-import-plugin` folder to the `/wp-content/plugins/` directory, or upload the zip through the Plugins screen in WordPress.
2. Activate the plugin through the 'Plugins' screen.
3. Go to Settings -> Telegram Importer and configure the channel.

== Frequently Asked Questions ==

= Does this work with private channels? =
No. This plugin only supports public channels accessible via `https://t.me/s/<channel>`.

= Can I import media into the WordPress media library? =
Not currently. Media is embedded via remote URLs.

== Screenshots ==

1. Settings screen with channel and import options.
2. Preview list for selecting messages to import.

== Changelog ==

= 0.1.0 =
* Initial release with preview and selective import.
