# Purge Pantheon + Cloudflare Caches

Purges your Cloudflare zone cache (and Pantheon edge cache) when content changes, with granular control over *how much* is purged: everything, or only the URLs an edit actually affects.

This is version 2 of what used to be a Pantheon-only MU plugin ("36 Total Cache + Cloudflare Purger"). It is now a regular, installable WordPress plugin with a settings page, built to meet the WordPress.org plugin directory guidelines.

## Why

Pantheon's Global CDN and Cloudflare both cache pages aggressively. Their built-in invalidation only knows about the page that was edited, so:

* A leadership page that pulls in `people` posts is not refreshed when a person is edited.
* A site-wide banner stored on an options page never invalidates the pages that render it.

The original plugin fixed this by purging the entire Cloudflare zone on every save. That is still the default, but it is a blunt instrument for larger sites. This version adds a targeted mode that works out which pages are affected by a change, plus ways to declare relationships the plugin cannot detect.

## Features

* **Connect to Cloudflare** with a zone-scoped API token (Zone: Read + Cache Purge). The settings page links to a pre-filled token form, verifies the token, lists your zones and pre-selects the one matching your domain. Tokens are stored encrypted, or can live in `wp-config.php`.
* **Purge modes**: purge everything (default) or purge affected URLs only. Set globally, per post type, or per post.
* **Affected URL detection** for targeted purges: the post's current and previous URL, home/blog index, post type / term / author / date archives and their pagination (via Cloudflare purge-by-prefix), feeds, parent pages, previous/next posts, and content that references the post (block IDs and synced pattern refs, image classes, shortcodes, links, Query Loop / Latest Posts blocks, featured images, ACF relationship / post object / image fields in post meta).
* **Dependency rules** ("when post type X changes, also purge these URLs") and a per-page "purge this page when any of these post types change" option for relationships that are not visible in the database.
* **Safety escalations** to a full purge: slug changes, posts linked from navigation menus, too many referencing pages, too many affected URLs.
* **Site-wide changes** (menus, widgets, Customizer, templates, settings, ACF options pages, theme/plugin updates) purge everything.
* **Comments, terms, authors and attachments** each have sensible, configurable handling.
* **Pantheon**: automatic purges run only on the `live` environment; Pantheon Advanced Page Cache is used for the same URLs when present.
* Manual purges from the admin bar and the settings page, a purge log, rate limit retries via WP-Cron, optional object cache flush.

## Installation

1. Install and activate the plugin: upload the ZIP through **Plugins → Add New**, or clone this repository into `wp-content/plugins/`.
2. Go to **Settings → Purge Caches** and follow the three steps on the Connection tab.
3. Review the **Purge Behavior** tab.

## Why an API token and not OAuth?

Cloudflare launched self-managed OAuth clients in June 2026. They only support the authorization code flow, every client must register exact redirect URLs, clients are private to the Cloudflare account that created them unless the publisher verifies a domain and makes them public, and consent is granted for whole accounts rather than single zones. For a plugin installed on many unrelated sites that means either every site owner registers their own OAuth client (more work than creating a token) or the plugin author runs a hosted broker that holds a public client secret. A zone-scoped API token is simpler and gives the site the least privilege it needs. The API client only needs a bearer token, so an OAuth connection method can be added later without touching the purge logic.

## How targeted purges find related pages

WordPress has no registry of "which pages show this post", so the plugin combines several strategies:

| Relationship | How it is detected |
| --- | --- |
| Archives the post appears in | Taxonomy terms, author, dates, post type archive; removed terms are captured from `set_object_terms` |
| Pagination of those archives | Cloudflare purge-by-prefix on the archive path, with URL enumeration as a fallback and for Pantheon |
| Blocks embedding the post or attachment | `"id":N`, `"ref":N` (synced patterns), `"mediaId":N`, `wp-image-N`, `data-id="N"`, gallery `ids` |
| Listing blocks | Query Loop (`"postType":"type"`) and Latest Posts blocks |
| Classic content | Shortcode `id="N"` attributes, links to the permalink path |
| Custom fields (ACF and others) | Post meta equal to `N`, serialized `s:len:"N";` / `i:N;`, JSON `"id":N` |
| Featured images | `_thumbnail_id` meta |
| Navigation menus | `_menu_item_object_id` meta; title/URL changes escalate to a full purge |
| Parent pages | `post_parent` ancestors |
| Anything else | Dependency rules, per-page dependencies, or the `cpcf_post_purge_plan` filter |

All lookups are bounded (`LIMIT`) and run once per request at shutdown, after all post data, terms and meta have been saved (the plugin hooks `wp_after_insert_post`, which fires after term and meta updates in both the classic and the REST/Gutenberg save paths).

## Developer API

```php
cpcf_purge_everything( 'Deploy finished' );
cpcf_purge_urls( array( '/pricing/', 'https://example.com/docs/' ) );
cpcf_purge_post( 123 );
```

Useful filters: `cpcf_post_purge_plan`, `cpcf_purge_urls`, `cpcf_purge_prefixes`, `cpcf_post_purge_mode`, `cpcf_post_type_purge_mode`, `cpcf_sitewide_options`, `cpcf_sitewide_post_types`, `cpcf_reference_content_patterns`, `cpcf_reference_post_types`, `cpcf_home_post_types`, `cpcf_date_archive_post_types`, `cpcf_is_production`, `cpcf_automatic_purges_allowed`, `cpcf_api_request_args`, `cpcf_purge_batch_size`. Actions: `cpcf_before_purge`, `cpcf_after_purge`.

## Development

```bash
composer global require wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp dealerdirect/phpcodesniffer-composer-installer
phpcs                       # uses phpcs.xml.dist
wp plugin check purge-pantheon-cloudflare-caches   # with the Plugin Check plugin installed
wp dist-archive .           # builds the ZIP, honouring .distignore
```

## Contributing

Pull requests are welcome. Please describe the use case, run `phpcs`, and keep changes focused.

## License

MIT. See [LICENSE](LICENSE).
