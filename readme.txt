=== Cache Purge Control for Cloudflare ===
Contributors: robwiddick
Tags: cloudflare, cache, purge, pantheon, cdn
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 7.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Purges your Cloudflare cache (and Pantheon edge cache) when content changes. Purge everything, or only the URLs an edit actually affects.

== Description ==

When Cloudflare caches your whole site ("Cache Everything", APO-style page rules, or a long browser/edge TTL), editors expect their changes to show up immediately. This plugin makes that happen by purging Cloudflare whenever content changes, and it lets you decide *how much* to purge.

**Two purge modes**

* **Purge everything** (default): clears the entire zone cache on every publish, update, trash or delete. Simple and always correct; every visitor hits your origin until the cache refills.
* **Purge affected URLs only**: purges the edited page plus every page that shows it: the front page and blog index, post type / category / tag / author / date archives and their pagination, feeds, parent pages, previous/next posts, and content that embeds, links to or lists it.

Modes can be set globally, per post type, or per individual post from a "Cache Purge Control" box in the editor.

**Relationship detection for targeted purges**

WordPress does not keep a map of which pages display which content, so a "targeted" purge has to find those relationships itself. The plugin scans published content and custom fields for:

* Block references (`"id":123`, `"ref":123` for synced patterns, `"mediaId"`, gallery and image IDs, `wp-image-123` classes)
* Query Loop and Latest Posts blocks that list the post type
* Shortcodes with `id="123"` attributes and links to the post's URL
* Featured images and Advanced Custom Fields relationship, post object, page link, image, file and gallery fields (plain IDs and serialized arrays in post meta)
* Posts linked from navigation menus (title or URL changes escalate to a full purge)

Relationships the database cannot reveal, such as a template that queries "Team member" entries for your About page, can be declared as **dependency rules** (when post type X changes, also purge these URLs) or from the other direction on any page ("purge this page when any of these post types change"). If more pages reference a post than a configurable limit, the plugin purges everything to stay safe.

**Site-wide changes**

Menus, widgets, Customizer settings, the theme, block templates and template parts, site settings (title, front page, permalinks…), ACF options pages and plugin updates all affect every page, so they trigger a full purge by default.

**Cloudflare connection**

Connect with a Cloudflare API token limited to *Zone: Read* and *Cache Purge*. The settings page links to a pre-filled "Create API token" form, verifies the token, lists your zones and pre-selects the one matching your domain. The token is stored encrypted; you can also define `CPCF_CLOUDFLARE_API_TOKEN` and `CPCF_CLOUDFLARE_ZONE_ID` in `wp-config.php` to keep credentials out of the database entirely.

Archive pagination is purged with Cloudflare's purge-by-prefix (available on all plans since 2025); if a zone rejects it the plugin falls back to purging paginated URLs. Rate limited requests are retried automatically via WP-Cron.

**Pantheon**

On Pantheon the plugin only purges automatically on the *live* environment (elsewhere it uses the WordPress environment type). When the Pantheon Advanced Page Cache plugin is active, the same URLs (or everything) are also cleared from the Pantheon Global CDN.

**Other features**

* Manual "Purge everything", "Purge this page and related URLs" and "Purge this URL" shortcuts in the admin bar
* Purge arbitrary URLs from the settings page
* A log of the last 50 purges with the trigger, the URLs and the result
* Optional WordPress object cache flush
* Developer API: `cpcf_purge_everything()`, `cpcf_purge_urls()`, `cpcf_purge_post()` and filters such as `cpcf_post_purge_plan`, `cpcf_purge_urls`, `cpcf_sitewide_options`, `cpcf_reference_content_patterns`

= External services =

This plugin connects to the **Cloudflare API** (https://api.cloudflare.com/) to verify your API token, list the zones it can access, and purge cached content. It sends the API token you entered, the selected zone ID, and, for targeted purges, the URLs or URL prefixes of the pages on your site that should be purged. Requests are made when you verify or test the connection, when you purge manually, and automatically when content on your site changes (only after you have entered credentials). No data is sent to the plugin author. Cloudflare's terms of service: https://www.cloudflare.com/terms/ and privacy policy: https://www.cloudflare.com/privacypolicy/

When the Pantheon Advanced Page Cache plugin is installed, the plugin calls its PHP functions (`pantheon_wp_clear_edge_paths()` / `pantheon_wp_clear_edge_all()`), which contact the Pantheon platform from within your site. See https://pantheon.io/terms and https://pantheon.io/privacy for Pantheon's terms and privacy policy.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Cloudflare Purge**.
3. Click the link to Cloudflare's "Create API token" page. The form is pre-filled with the *Zone: Read* and *Cache Purge* permissions; optionally restrict it to your zone, then create the token.
4. Paste the token, click **Verify token & load zones**, choose your zone and save.
5. Review the **Purge Behavior** tab. The default is to purge everything on any content change.

Alternatively define the credentials in `wp-config.php`:

`define( 'CPCF_CLOUDFLARE_API_TOKEN', '...' );`
`define( 'CPCF_CLOUDFLARE_ZONE_ID', '...' );`

**Upgrading from the 1.x MU plugin:** remove `mu-clear-cloudflare-cache.php` from `wp-content/mu-plugins`. If the old `files/private/cloudflare_cache_config.json` file is still present on Pantheon, the settings page offers a one-click import of its zone and token.

== Frequently Asked Questions ==

= Can I connect with OAuth instead of an API token? =

Not yet. Cloudflare introduced self-managed OAuth clients in 2026, but every site using OAuth needs its own registered client (with a matching redirect URL) or a vendor-hosted broker, and OAuth consent is granted per account rather than per zone. A zone-scoped API token is simpler to create and safer, so the plugin uses tokens. The connection layer is isolated so OAuth can be added later.

= Which purge mode should I use? =

Start with **Purge everything** unless full purges are visibly hurting performance (cache hit ratio drops after every edit, origin load spikes). Switch to **Purge affected URLs only** when your site is large, edits are frequent, and pages mostly show their own content. Use per post type overrides for a mix: e.g. targeted purges for blog posts, full purges for pages that build the navigation.

= Why was everything purged although targeted mode is on? =

Targeted mode escalates to a full purge when: the URL of a published item changed, the item is linked from a navigation menu and its title or URL changed, a dependency rule asks for it, more pages reference the item than the reference limit, or the number of affected URLs exceeds the full-purge threshold. The purge log on the Tools & Log tab shows the reason.

= Does it work with Cloudflare APO or the official Cloudflare plugin? =

APO manages its own cache and purges; this plugin is meant for zones cached with Cache Rules / "Cache Everything". It can run alongside the official Cloudflare plugin, but you should disable automatic purging in one of them.

= Are http:// and https:// handled? =

Cloudflare caches them separately, so by default both variants of every URL are purged. Disable this on the Targeted Purge Scope tab if your zone redirects http to https at the edge.

= Nothing is purged on my staging site =

By default automatic purges only run in production: on Pantheon that means the *live* environment, elsewhere the WordPress environment type must be `production` (the default when `WP_ENVIRONMENT_TYPE` is not set). Disable "Production only" on the Purge Behavior tab if you really want purges elsewhere.

== Screenshots ==

1. Connection tab: verify the token and choose a zone.
2. Purge Behavior tab: default mode and per post type overrides.
3. Targeted Purge Scope tab.
4. Dependency rules.
5. Tools and purge log.
6. Per-post Cache Purge Control box in the editor.

== Changelog ==

= 2.0.0 =
* Rewritten as a regular, installable plugin with a settings page (was a Pantheon-only MU plugin with a JSON config file).
* Connect to Cloudflare with an API token: guided token creation, verification, zone selection.
* New "purge affected URLs only" mode with relationship detection, dependency rules and per-post controls.
* Site-wide change detection (menus, widgets, Customizer, templates, settings, ACF options pages).
* Purge by prefix for archive pagination, http/https variants, rate limit retries.
* Pantheon edge cache and object cache purging are now optional and detected automatically.
* Admin bar shortcuts, manual URL purging and a purge log.

= 1.0 =
* Initial MU plugin: purge everything on post save.

== Upgrade Notice ==

= 2.0.0 =
Replaces the 1.x MU plugin. Remove mu-clear-cloudflare-cache.php from mu-plugins and connect to Cloudflare from Settings → Cloudflare Purge.
