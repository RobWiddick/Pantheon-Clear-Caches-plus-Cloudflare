=== Cache Purge Control for Cloudflare ===
Contributors: robwiddick
Tags: cloudflare, cache, purge, cdn, pantheon
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 7.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Purge Cloudflare (and Pantheon) caches when content changes. Purge the whole zone, or only the URLs an edit affects.

== Description ==

If Cloudflare caches your pages (Cache Rules, "Cache Everything", or a long edge TTL), editors expect their changes to appear right away. Cache Purge Control for Cloudflare purges Cloudflare whenever content changes, and it lets you decide how much to purge.

= Two purge modes =

* **Purge everything** (default). Clears the entire zone cache on every publish, update, trash or delete. Simple and always correct; every visitor hits your origin until the cache refills.
* **Purge affected URLs only.** Purges the edited page plus every page that shows it: the front page and blog index, post type, category, tag, author and date archives (including their pagination), feeds, parent pages, previous and next posts, and content that embeds, links to or lists it.

Set the mode globally, per post type, or per individual post from a "Cache Purge Control" box in the editor.

= Finding the pages an edit affects =

WordPress does not keep a map of which pages display which content, so a targeted purge has to work it out. The plugin scans published content and custom fields for:

* Block references such as `"id":123`, `"ref":123` for synced patterns, `"mediaId"`, gallery and image IDs and `wp-image-123` classes
* Query Loop and Latest Posts blocks that list the post type
* Shortcodes with `id="123"` attributes and links to the post's URL
* Featured images and Advanced Custom Fields relationship, post object, page link, image, file and gallery fields stored in post meta
* Posts linked from navigation menus (title or URL changes trigger a full purge)

Relationships the database cannot reveal, for example a template that queries "Team member" entries for your About page, can be declared as dependency rules ("when Team members change, also purge these URLs") or from the other direction on any page ("purge this page when any of these post types change"). If more pages reference a post than a configurable limit, or the URL of published content changes, the plugin purges everything to stay safe. The purge log always shows why.

= Site-wide changes =

Menus, widgets, Customizer settings, the theme, block templates and template parts, site settings (title, front page, permalinks), ACF options pages and plugin updates affect every page, so they trigger a full purge by default.

= Cloudflare connection =

Connect with a Cloudflare API token limited to **Zone: Read** and **Cache Purge**. The settings page links to a pre-filled "Create API token" form, verifies the token, lists your zones and pre-selects the one that matches your domain. The token is stored encrypted. You can also define `CPCF_CLOUDFLARE_API_TOKEN` and `CPCF_CLOUDFLARE_ZONE_ID` in `wp-config.php` to keep credentials out of the database.

Archive pagination is purged with Cloudflare's purge-by-prefix (available on all plans); if a zone rejects it the plugin purges the paginated URLs instead. Rate limited requests are retried automatically.

= Pantheon =

On Pantheon, automatic purges only run on the Live environment. Elsewhere they run when the WordPress environment type is "production". When the Pantheon Advanced Page Cache plugin is active, the same URLs (or everything) are also cleared from the Pantheon Global CDN.

= More =

* "Purge everything", "Purge this page and related URLs" and "Purge this URL" shortcuts in the admin bar
* Purge any list of URLs from the settings page
* A log of the last 50 purges with the trigger, the URLs and the result
* Optional WordPress object cache flush
* Developer functions `cpcf_purge_everything()`, `cpcf_purge_urls()` and `cpcf_purge_post()`, plus filters such as `cpcf_post_purge_plan`, `cpcf_purge_urls`, `cpcf_sitewide_options` and `cpcf_reference_content_patterns`

= External services =

This plugin connects to the Cloudflare API (https://api.cloudflare.com/) to verify your API token, list the zones it can access, and purge cached content. It sends the API token you entered, the selected zone ID and, for targeted purges, the URLs or URL prefixes of pages on your site that should be purged. Requests are made when you verify or test the connection, when you purge manually, and automatically when content on your site changes. Nothing is sent before you enter credentials, and nothing is sent to the plugin author. Cloudflare terms of service: https://www.cloudflare.com/terms/ Privacy policy: https://www.cloudflare.com/privacypolicy/

If the Pantheon Advanced Page Cache plugin is installed, this plugin calls its PHP functions, which contact the Pantheon platform from your site. Pantheon terms: https://pantheon.io/terms Privacy policy: https://pantheon.io/privacy

== Installation ==

1. Install and activate the plugin from **Plugins → Add New**, or upload the ZIP.
2. Go to **Settings → Cloudflare Purge**.
3. Follow the link to Cloudflare's "Create API token" page. The form is pre-filled with the Zone: Read and Cache Purge permissions; optionally limit it to your zone, then create the token.
4. Paste the token, click **Verify token & load zones**, choose your zone and save.
5. Review the **Purge Behavior** tab. The default purges everything on any content change.

To keep credentials out of the database, add these lines to `wp-config.php` instead of entering them on the settings page:

`define( 'CPCF_CLOUDFLARE_API_TOKEN', 'your-token' );`
`define( 'CPCF_CLOUDFLARE_ZONE_ID', 'your-zone-id' );`

= Upgrading from the 1.x MU plugin =

Remove `mu-clear-cloudflare-cache.php` from `wp-content/mu-plugins`. On Pantheon, if the old `files/private/cloudflare_cache_config.json` file is still present, the settings page offers a one-click import of its zone and token. Delete the file afterwards.

== Frequently Asked Questions ==

= Which purge mode should I use? =

Start with **Purge everything** unless full purges visibly hurt performance (cache hit ratio drops after every edit, origin load spikes). Switch to **Purge affected URLs only** on large sites with frequent edits. Mix them with per post type overrides, for example targeted purges for blog posts and full purges for pages.

= Why was everything purged although targeted mode is on? =

Targeted mode escalates to a full purge when the URL of published content changed, the content is linked from a navigation menu and its title or URL changed, a block template or template part references it, a dependency rule asks for it, more pages reference it than the reference limit, or the number of affected URLs exceeds the full purge threshold. The purge log on the Tools & Log tab shows the reason.

= Can I connect with OAuth instead of an API token? =

Not yet. Cloudflare's OAuth clients (introduced in 2026) require each site to register its own client with a matching redirect URL, or a vendor-hosted broker, and consent is granted per account rather than per zone. A zone-scoped API token is simpler and safer. The connection layer is isolated so OAuth can be added later.

= Does it work with Cloudflare APO or the official Cloudflare plugin? =

APO manages its own cache; this plugin is meant for zones cached with Cache Rules or "Cache Everything". It can run alongside the official Cloudflare plugin, but disable automatic purging in one of them.

= Are http:// and https:// handled? =

Cloudflare caches them separately, so both variants of every URL are purged by default. Disable this on the Targeted Purge Scope tab if your zone redirects http to https at the edge.

= Nothing is purged on my staging site =

By default automatic purges only run in production: the Live environment on Pantheon, or a WordPress environment type of "production" elsewhere (the default when `WP_ENVIRONMENT_TYPE` is not set). Disable "Production only" on the Purge Behavior tab if you want purges elsewhere. Manual purges always work.

= What data does the plugin store? =

Your API token (encrypted), the selected zone, the settings, a log of recent purges, and per-post purge preferences. Everything is removed when the plugin is uninstalled.

== Screenshots ==

1. Connection tab: verify the token and choose a zone.
2. Purge Behavior tab: default mode and per post type overrides.
3. Targeted Purge Scope tab.
4. Dependency rules.
5. Tools and purge log.
6. The Cache Purge Control box in the editor.

== Changelog ==

= 2.0.0 =
* Rewritten as a regular, installable plugin with a settings page (previously a Pantheon-only MU plugin configured through a JSON file).
* Connect to Cloudflare with an API token: guided token creation, verification, zone selection, encrypted storage or wp-config constants.
* New "purge affected URLs only" mode with relationship detection, dependency rules and per-post controls.
* Site-wide change detection: menus, widgets, Customizer, templates, settings, ACF options pages, plugin and theme updates.
* Purge by prefix for archive pagination, http/https variants, automatic retries when rate limited.
* Pantheon edge cache and object cache purging are optional and detected automatically.
* Admin bar shortcuts, manual URL purging and a purge log.

= 1.0 =
* Initial MU plugin: purge everything on post save.

== Upgrade Notice ==

= 2.0.0 =
Replaces the 1.x MU plugin. Remove mu-clear-cloudflare-cache.php from mu-plugins, then connect to Cloudflare under Settings → Cloudflare Purge.
