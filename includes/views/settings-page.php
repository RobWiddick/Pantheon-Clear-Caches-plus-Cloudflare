<?php
/**
 * Settings page template.
 *
 * Variables available: $settings (Settings), $log (Log), $admin (Admin), $active_tab (string).
 *
 * @package CPCF
 */

use CPCF\Cloudflare_API;
use CPCF\Environment;
use CPCF\Settings;

defined( 'ABSPATH' ) || exit;

$cpcf_connected   = $settings->is_connected();
$cpcf_production  = Environment::is_production();
$cpcf_auto_on     = (bool) $settings->get( 'enabled' );
$cpcf_auto_paused = $cpcf_auto_on && $settings->get( 'production_only' ) && ! $cpcf_production;
$cpcf_tabs        = array(
	'connection' => __( 'Connection', 'purge-pantheon-cloudflare-caches' ),
	'behavior'   => __( 'Purge Behavior', 'purge-pantheon-cloudflare-caches' ),
	'scope'      => __( 'Targeted Purge Scope', 'purge-pantheon-cloudflare-caches' ),
	'rules'      => __( 'Dependency Rules', 'purge-pantheon-cloudflare-caches' ),
	'tools'      => __( 'Tools & Log', 'purge-pantheon-cloudflare-caches' ),
);
$cpcf_mode_labels = array(
	'default'    => __( 'Use default', 'purge-pantheon-cloudflare-caches' ),
	'everything' => __( 'Purge everything', 'purge-pantheon-cloudflare-caches' ),
	'targeted'   => __( 'Purge affected URLs only', 'purge-pantheon-cloudflare-caches' ),
	'none'       => __( 'Do not purge', 'purge-pantheon-cloudflare-caches' ),
);
$cpcf_type_modes  = (array) $settings->get( 'post_type_modes' );
$cpcf_rules       = $settings->rules();
$cpcf_post_types  = $admin->purgeable_post_types();
?>
<div class="wrap cpcf-wrap">
	<h1><?php esc_html_e( 'Purge Pantheon + Cloudflare Caches', 'purge-pantheon-cloudflare-caches' ); ?></h1>

	<?php settings_errors( Settings::OPTION ); ?>

	<div class="cpcf-status">
		<div class="cpcf-status-item">
			<span class="cpcf-status-label"><?php esc_html_e( 'Cloudflare', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php if ( $cpcf_connected ) : ?>
				<span class="cpcf-badge cpcf-badge-ok"><?php esc_html_e( 'Connected', 'purge-pantheon-cloudflare-caches' ); ?></span>
				<span class="cpcf-status-detail"><?php echo esc_html( $settings->get_zone_name() ? $settings->get_zone_name() : $settings->get_zone_id() ); ?></span>
			<?php else : ?>
				<span class="cpcf-badge cpcf-badge-error"><?php esc_html_e( 'Not connected', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="cpcf-status-item">
			<span class="cpcf-status-label"><?php esc_html_e( 'Automatic purging', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php if ( ! $cpcf_auto_on ) : ?>
				<span class="cpcf-badge cpcf-badge-skipped"><?php esc_html_e( 'Off', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php elseif ( $cpcf_auto_paused ) : ?>
				<span class="cpcf-badge cpcf-badge-warning"><?php esc_html_e( 'Paused', 'purge-pantheon-cloudflare-caches' ); ?></span>
				<span class="cpcf-status-detail"><?php esc_html_e( 'This is not a production environment.', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php else : ?>
				<span class="cpcf-badge cpcf-badge-ok"><?php esc_html_e( 'On', 'purge-pantheon-cloudflare-caches' ); ?></span>
				<span class="cpcf-status-detail"><?php echo esc_html( 'everything' === $settings->get( 'purge_mode' ) ? __( 'Default: purge everything', 'purge-pantheon-cloudflare-caches' ) : __( 'Default: purge affected URLs only', 'purge-pantheon-cloudflare-caches' ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="cpcf-status-item">
			<span class="cpcf-status-label"><?php esc_html_e( 'Environment', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<span class="cpcf-status-detail"><?php echo esc_html( Environment::describe() ); ?></span>
			<?php if ( Environment::has_pantheon_purge() ) : ?>
				<span class="cpcf-badge cpcf-badge-ok"><?php esc_html_e( 'Pantheon edge purge available', 'purge-pantheon-cloudflare-caches' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<h2 class="nav-tab-wrapper cpcf-tabs">
		<?php foreach ( $cpcf_tabs as $cpcf_tab_id => $cpcf_tab_label ) : ?>
			<a href="<?php echo esc_url( $admin->page_url( array( 'tab' => $cpcf_tab_id ) ) ); ?>" class="nav-tab <?php echo $active_tab === $cpcf_tab_id ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $cpcf_tab_id ); ?>"><?php echo esc_html( $cpcf_tab_label ); ?></a>
		<?php endforeach; ?>
	</h2>

	<form method="post" action="options.php" id="cpcf-settings-form">
		<?php settings_fields( Settings::GROUP ); ?>

		<div class="cpcf-panel" data-panel="connection" <?php echo 'connection' === $active_tab ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Connect to Cloudflare', 'purge-pantheon-cloudflare-caches' ); ?></h2>
			<p>
				<?php esc_html_e( 'The plugin talks to Cloudflare with an API token that is limited to reading your zones and purging their cache. Nothing else on your Cloudflare account is accessible with it.', 'purge-pantheon-cloudflare-caches' ); ?>
			</p>
			<ol class="cpcf-steps">
				<li>
					<?php
					printf(
						/* translators: %s: link to the Cloudflare dashboard */
						esc_html__( 'Open %s. The form is pre-filled with the two permissions the plugin needs (Zone: Read and Cache Purge). Optionally restrict it to a single zone, then create the token.', 'purge-pantheon-cloudflare-caches' ),
						'<a href="' . esc_url( Cloudflare_API::token_template_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Cloudflare\'s "Create API token" page', 'purge-pantheon-cloudflare-caches' ) . '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Paste the token below and click "Verify token & load zones".', 'purge-pantheon-cloudflare-caches' ); ?></li>
				<li><?php esc_html_e( 'Choose the zone (domain) this site is served from and save.', 'purge-pantheon-cloudflare-caches' ); ?></li>
			</ol>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cpcf-api-token"><?php esc_html_e( 'API token', 'purge-pantheon-cloudflare-caches' ); ?></label></th>
					<td>
						<?php if ( $settings->token_from_constant() ) : ?>
							<p><code>CPCF_CLOUDFLARE_API_TOKEN</code> <?php esc_html_e( 'is defined in wp-config.php, so the token cannot be changed here.', 'purge-pantheon-cloudflare-caches' ); ?></p>
						<?php else : ?>
							<input type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[api_token]" id="cpcf-api-token" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $settings->get_api_token() ? __( '•••••••• (token saved; paste a new one to replace it)', 'purge-pantheon-cloudflare-caches' ) : __( 'Paste your Cloudflare API token', 'purge-pantheon-cloudflare-caches' ) ); ?>" />
						<?php endif; ?>
						<button type="button" class="button" id="cpcf-verify"><?php esc_html_e( 'Verify token & load zones', 'purge-pantheon-cloudflare-caches' ); ?></button>
						<p id="cpcf-verify-status" class="description" aria-live="polite"></p>
						<?php if ( ! $settings->token_from_constant() ) : ?>
							<p class="description">
								<?php esc_html_e( 'The token is stored encrypted in the database. To keep it out of the database entirely, define CPCF_CLOUDFLARE_API_TOKEN (and optionally CPCF_CLOUDFLARE_ZONE_ID) in wp-config.php instead.', 'purge-pantheon-cloudflare-caches' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cpcf-zone"><?php esc_html_e( 'Zone', 'purge-pantheon-cloudflare-caches' ); ?></label></th>
					<td>
						<?php if ( $settings->zone_from_constant() ) : ?>
							<p><code><?php echo esc_html( $settings->get_zone_id() ); ?></code> <?php esc_html_e( '(defined in wp-config.php)', 'purge-pantheon-cloudflare-caches' ); ?></p>
						<?php else : ?>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[zone]" id="cpcf-zone">
								<option value=""><?php esc_html_e( '— Select a zone —', 'purge-pantheon-cloudflare-caches' ); ?></option>
								<?php if ( '' !== $settings->get_zone_id() ) : ?>
									<option value="<?php echo esc_attr( $settings->get_zone_id() . '|' . $settings->get_zone_name() ); ?>" selected><?php echo esc_html( $settings->get_zone_name() ? $settings->get_zone_name() : $settings->get_zone_id() ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Verify the token to load the zones it can access. The zone matching this site\'s domain is selected automatically.', 'purge-pantheon-cloudflare-caches' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $cpcf_connected ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<td>
							<a class="button" href="<?php echo esc_url( $admin->action_url( 'test_connection' ) ); ?>"><?php esc_html_e( 'Test connection', 'purge-pantheon-cloudflare-caches' ); ?></a>
							<?php if ( ! $settings->token_from_constant() ) : ?>
								<a class="button cpcf-button-danger" href="<?php echo esc_url( $admin->action_url( 'disconnect' ) ); ?>" onclick="return window.confirm( '<?php echo esc_js( __( 'Remove the stored token and zone?', 'purge-pantheon-cloudflare-caches' ) ); ?>' );"><?php esc_html_e( 'Disconnect', 'purge-pantheon-cloudflare-caches' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<div class="cpcf-panel" data-panel="behavior" <?php echo 'behavior' === $active_tab ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'When content changes', 'purge-pantheon-cloudflare-caches' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $admin->checkbox( 'enabled', __( 'Automatic purging', 'purge-pantheon-cloudflare-caches' ), __( 'Purge caches automatically when content changes.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'production_only', __( 'Production only', 'purge-pantheon-cloudflare-caches' ), __( 'Only purge automatically in production (Pantheon "live", or the WordPress environment type "production"). Manual purges always work.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default purge mode', 'purge-pantheon-cloudflare-caches' ); ?></th>
					<td>
						<fieldset>
							<label class="cpcf-radio">
								<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[purge_mode]" value="everything" <?php checked( $settings->get( 'purge_mode' ), 'everything' ); ?> />
								<strong><?php esc_html_e( 'Purge everything', 'purge-pantheon-cloudflare-caches' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Clears the whole zone on every change. Simple and always correct, but every visitor hits your origin until the cache refills. Best for smaller sites or sites with many cross-references between pages.', 'purge-pantheon-cloudflare-caches' ); ?></span>
							</label>
							<label class="cpcf-radio">
								<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[purge_mode]" value="targeted" <?php checked( $settings->get( 'purge_mode' ), 'targeted' ); ?> />
								<strong><?php esc_html_e( 'Purge affected URLs only', 'purge-pantheon-cloudflare-caches' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Purges the edited page plus the pages that show it: the home page, archives, feeds, parent pages, and any content that embeds or links to it. Configure what counts as "affected" on the Targeted Purge Scope tab, and declare relationships the plugin cannot detect on the Dependency Rules tab.', 'purge-pantheon-cloudflare-caches' ); ?></span>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Per post type', 'purge-pantheon-cloudflare-caches' ); ?></th>
					<td>
						<table class="widefat striped cpcf-table">
							<thead><tr><th><?php esc_html_e( 'Post type', 'purge-pantheon-cloudflare-caches' ); ?></th><th><?php esc_html_e( 'Purge mode', 'purge-pantheon-cloudflare-caches' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $cpcf_post_types as $cpcf_type ) : ?>
								<?php $cpcf_current_mode = isset( $cpcf_type_modes[ $cpcf_type->name ] ) ? $cpcf_type_modes[ $cpcf_type->name ] : 'default'; ?>
								<tr>
									<td><?php echo esc_html( $cpcf_type->labels->name ); ?> <code><?php echo esc_html( $cpcf_type->name ); ?></code></td>
									<td>
										<select name="<?php echo esc_attr( Settings::OPTION ); ?>[post_type_modes][<?php echo esc_attr( $cpcf_type->name ); ?>]">
											<?php foreach ( $cpcf_mode_labels as $cpcf_value => $cpcf_label ) : ?>
												<option value="<?php echo esc_attr( $cpcf_value ); ?>" <?php selected( $cpcf_current_mode, $cpcf_value ); ?>>
													<?php
													if ( 'default' === $cpcf_value && 'attachment' === $cpcf_type->name ) {
														esc_html_e( 'Use default (affected URLs only)', 'purge-pantheon-cloudflare-caches' );
													} else {
														echo esc_html( $cpcf_label );
													}
													?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'Media defaults to targeted purging because uploads happen often. Individual posts can also override their post type from the "Cache Purge Control" box in the editor.', 'purge-pantheon-cloudflare-caches' ); ?></p>
					</td>
				</tr>
				<?php $admin->checkbox( 'slug_change_everything', __( 'URL changes', 'purge-pantheon-cloudflare-caches' ), __( 'Purge everything when the URL (slug) of published content changes, because links to it appear across the site.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'sitewide_purge', __( 'Site-wide changes', 'purge-pantheon-cloudflare-caches' ), __( 'Purge everything when menus, widgets, Customizer settings, the theme, templates, site settings, ACF options pages, or plugins change.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php
				$admin->select(
					'term_purge',
					__( 'Category / tag / term edits', 'purge-pantheon-cloudflare-caches' ),
					array(
						'follow'     => __( 'Follow the default purge mode', 'purge-pantheon-cloudflare-caches' ),
						'everything' => __( 'Always purge everything', 'purge-pantheon-cloudflare-caches' ),
						'targeted'   => __( 'Purge the term archive and home page only', 'purge-pantheon-cloudflare-caches' ),
						'none'       => __( 'Do nothing', 'purge-pantheon-cloudflare-caches' ),
					),
					__( 'Renaming a term changes every page that displays it; a full purge is the safe choice.', 'purge-pantheon-cloudflare-caches' )
				);
				$admin->select(
					'comment_purge',
					__( 'Comments', 'purge-pantheon-cloudflare-caches' ),
					array(
						'post'   => __( 'Purge the commented post and its feeds', 'purge-pantheon-cloudflare-caches' ),
						'follow' => __( 'Treat like a post update', 'purge-pantheon-cloudflare-caches' ),
						'none'   => __( 'Do nothing', 'purge-pantheon-cloudflare-caches' ),
					)
				);
				?>
			</table>

			<h2><?php esc_html_e( 'Other caches', 'purge-pantheon-cloudflare-caches' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$admin->checkbox(
					'pantheon_purge',
					__( 'Pantheon edge cache', 'purge-pantheon-cloudflare-caches' ),
					Environment::has_pantheon_purge()
						? __( 'Also clear the Pantheon Global CDN (Pantheon Advanced Page Cache) for the same URLs, or entirely when purging everything.', 'purge-pantheon-cloudflare-caches' )
						: __( 'Also clear the Pantheon Global CDN when the Pantheon Advanced Page Cache plugin is active. (Not detected on this site.)', 'purge-pantheon-cloudflare-caches' )
				);
				$admin->checkbox( 'flush_object_cache', __( 'Object cache', 'purge-pantheon-cloudflare-caches' ), __( 'Also flush the WordPress object cache (Redis, Memcached…) on every purge. Rarely needed and can slow down busy sites.', 'purge-pantheon-cloudflare-caches' ) );
				$admin->checkbox( 'admin_bar', __( 'Admin bar', 'purge-pantheon-cloudflare-caches' ), __( 'Show a "Purge Caches" menu in the admin bar with purge shortcuts.', 'purge-pantheon-cloudflare-caches' ) );
				$admin->checkbox( 'log_enabled', __( 'Purge log', 'purge-pantheon-cloudflare-caches' ), __( 'Keep a log of the last 50 purges on the Tools & Log tab.', 'purge-pantheon-cloudflare-caches' ) );
				?>
			</table>
		</div>

		<div class="cpcf-panel" data-panel="scope" <?php echo 'scope' === $active_tab ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'What a targeted purge includes', 'purge-pantheon-cloudflare-caches' ); ?></h2>
			<p><?php esc_html_e( 'These options apply whenever content is purged in "affected URLs only" mode. The edited page itself (and its previous URL, if it changed) is always included.', 'purge-pantheon-cloudflare-caches' ); ?></p>
			<table class="form-table" role="presentation">
				<?php $admin->checkbox( 'include_home', __( 'Home page', 'purge-pantheon-cloudflare-caches' ), __( 'The front page and the blog index, including its paginated pages.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_post_type_archive', __( 'Post type archive', 'purge-pantheon-cloudflare-caches' ), __( 'The archive of the content\'s post type (for custom post types that have one).', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_terms', __( 'Term archives', 'purge-pantheon-cloudflare-caches' ), __( 'Category, tag and custom taxonomy archives the content belongs to (and belonged to before the change).', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_author', __( 'Author archive', 'purge-pantheon-cloudflare-caches' ), __( 'The author\'s archive page.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_dates', __( 'Date archives', 'purge-pantheon-cloudflare-caches' ), __( 'Year, month and day archives (blog posts only).', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_feeds', __( 'Feeds', 'purge-pantheon-cloudflare-caches' ), __( 'The site feeds and the feeds of the archives above.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_ancestors', __( 'Parent pages', 'purge-pantheon-cloudflare-caches' ), __( 'Parent pages of hierarchical content (they often list their children).', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php $admin->checkbox( 'include_adjacent', __( 'Previous / next posts', 'purge-pantheon-cloudflare-caches' ), __( 'The neighbouring posts, whose "previous" and "next" links change.', 'purge-pantheon-cloudflare-caches' ) ); ?>
				<?php
				$admin->select(
					'reference_lookup',
					__( 'Content that references it', 'purge-pantheon-cloudflare-caches' ),
					array(
						'all'     => __( 'Scan content and custom fields (recommended)', 'purge-pantheon-cloudflare-caches' ),
						'content' => __( 'Scan content only', 'purge-pantheon-cloudflare-caches' ),
						'none'    => __( 'Do not scan', 'purge-pantheon-cloudflare-caches' ),
					),
					__( 'Finds published pages that embed, link to, or query the changed content: block references, synced patterns, images and galleries, shortcodes, links, featured images, and ACF relationship / post object / image fields stored in custom fields. If more pages match than the limit below, everything is purged instead.', 'purge-pantheon-cloudflare-caches' )
				);
				$admin->number( 'reference_limit', __( 'Reference limit', 'purge-pantheon-cloudflare-caches' ), 1, 500, __( 'Maximum number of referencing pages to look up per change.', 'purge-pantheon-cloudflare-caches' ) );
				$admin->select(
					'archive_method',
					__( 'Archive pagination', 'purge-pantheon-cloudflare-caches' ),
					array(
						'prefix' => __( 'Purge by prefix (all pages of an archive, recommended)', 'purge-pantheon-cloudflare-caches' ),
						'urls'   => __( 'Purge paginated URLs one by one', 'purge-pantheon-cloudflare-caches' ),
					),
					__( 'Purge by prefix clears every cached page under an archive path (e.g. /category/news/ and all of its /page/N/ pages) in one request. It is available on all Cloudflare plans; if Cloudflare rejects it the plugin falls back to purging the paginated URLs.', 'purge-pantheon-cloudflare-caches' )
				);
				$admin->number( 'pagination_pages', __( 'Paginated pages', 'purge-pantheon-cloudflare-caches' ), 0, 25, __( 'How many pages of each archive to purge when purging URLs one by one (also used for the Pantheon edge cache). 0 purges only the first page.', 'purge-pantheon-cloudflare-caches' ) );
				$admin->checkbox( 'scheme_variants', __( 'http and https', 'purge-pantheon-cloudflare-caches' ), __( 'Purge both the http:// and https:// version of each URL. Cloudflare caches them separately.', 'purge-pantheon-cloudflare-caches' ) );
				$admin->number( 'targeted_fallback_limit', __( 'Full purge threshold', 'purge-pantheon-cloudflare-caches' ), 0, 2000, __( 'If a targeted purge would touch more URLs than this, purge everything instead. 0 disables the threshold.', 'purge-pantheon-cloudflare-caches' ) );
				?>
				<tr>
					<th scope="row"><label for="cpcf-always-purge"><?php esc_html_e( 'Always purge', 'purge-pantheon-cloudflare-caches' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[always_purge_urls]" id="cpcf-always-purge" class="large-text code" rows="4" placeholder="/&#10;/sitemap.xml&#10;/news/*"><?php echo esc_textarea( (string) $settings->get( 'always_purge_urls' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'URLs or paths purged with every targeted purge, one per line. End a path with * to purge everything under it.', 'purge-pantheon-cloudflare-caches' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="cpcf-panel" data-panel="rules" <?php echo 'rules' === $active_tab ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Dependency rules', 'purge-pantheon-cloudflare-caches' ); ?></h2>
			<p><?php esc_html_e( 'Some relationships cannot be detected from the database: a template that queries "Team member" entries for the /about/ page, a plugin that renders a list from custom tables, or a page built by a page builder. Declare them here: when content of the chosen type changes, the listed URLs are purged as well.', 'purge-pantheon-cloudflare-caches' ); ?></p>
			<p><?php esc_html_e( 'Tip: editors can also declare this from the other direction. The "Cache Purge Control" box on any page has a "Purge this when any of these change" option.', 'purge-pantheon-cloudflare-caches' ); ?></p>

			<table class="widefat cpcf-table cpcf-rules" id="cpcf-rules">
				<thead>
					<tr>
						<th class="cpcf-col-type"><?php esc_html_e( 'When this changes', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th><?php esc_html_e( 'Also purge these URLs (one per line, * for prefixes)', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th class="cpcf-col-everything"><?php esc_html_e( 'Purge everything', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th class="cpcf-col-remove"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cpcf_rules as $cpcf_index => $cpcf_rule ) : ?>
						<tr class="cpcf-rule">
							<td>
								<select name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][<?php echo (int) $cpcf_index; ?>][post_type]">
									<?php foreach ( $cpcf_post_types as $cpcf_type ) : ?>
										<option value="<?php echo esc_attr( $cpcf_type->name ); ?>" <?php selected( $cpcf_rule['post_type'], $cpcf_type->name ); ?>><?php echo esc_html( $cpcf_type->labels->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][<?php echo (int) $cpcf_index; ?>][urls]" class="large-text code" rows="2"><?php echo esc_textarea( implode( "\n", (array) $cpcf_rule['urls'] ) ); ?></textarea></td>
							<td><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][<?php echo (int) $cpcf_index; ?>][everything]" value="1" <?php checked( ! empty( $cpcf_rule['everything'] ) ); ?> /></td>
							<td><button type="button" class="button-link cpcf-remove-rule" aria-label="<?php esc_attr_e( 'Remove rule', 'purge-pantheon-cloudflare-caches' ); ?>">&times;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="cpcf-add-rule"><?php esc_html_e( 'Add rule', 'purge-pantheon-cloudflare-caches' ); ?></button></p>

			<template id="cpcf-rule-template">
				<tr class="cpcf-rule">
					<td>
						<select name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][__INDEX__][post_type]">
							<?php foreach ( $cpcf_post_types as $cpcf_type ) : ?>
								<option value="<?php echo esc_attr( $cpcf_type->name ); ?>"><?php echo esc_html( $cpcf_type->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][__INDEX__][urls]" class="large-text code" rows="2" placeholder="/about/&#10;/team/*"></textarea></td>
					<td><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[rules][__INDEX__][everything]" value="1" /></td>
					<td><button type="button" class="button-link cpcf-remove-rule" aria-label="<?php esc_attr_e( 'Remove rule', 'purge-pantheon-cloudflare-caches' ); ?>">&times;</button></td>
				</tr>
			</template>
		</div>

		<div id="cpcf-submit" <?php echo 'tools' === $active_tab ? 'hidden' : ''; ?>>
			<?php submit_button(); ?>
		</div>
	</form>

	<div class="cpcf-panel" data-panel="tools" <?php echo 'tools' === $active_tab ? '' : 'hidden'; ?>>
		<h2><?php esc_html_e( 'Purge now', 'purge-pantheon-cloudflare-caches' ); ?></h2>
		<?php if ( ! $cpcf_connected ) : ?>
			<p><?php esc_html_e( 'Connect to Cloudflare first to use the purge tools.', 'purge-pantheon-cloudflare-caches' ); ?></p>
		<?php else : ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $admin->action_url( 'purge', array( 'type' => 'everything' ) ) ); ?>" onclick="return window.confirm( '<?php echo esc_js( __( 'Purge the entire cache for this zone?', 'purge-pantheon-cloudflare-caches' ) ); ?>' );"><?php esc_html_e( 'Purge everything', 'purge-pantheon-cloudflare-caches' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cpcf_purge" />
				<input type="hidden" name="type" value="urls" />
				<?php wp_nonce_field( 'cpcf_purge' ); ?>
				<label for="cpcf-purge-urls"><strong><?php esc_html_e( 'Purge specific URLs', 'purge-pantheon-cloudflare-caches' ); ?></strong></label>
				<textarea name="urls" id="cpcf-purge-urls" class="large-text code" rows="4" placeholder="/about/&#10;https://example.com/news/"></textarea>
				<p class="description"><?php esc_html_e( 'One URL or path per line.', 'purge-pantheon-cloudflare-caches' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Purge URLs', 'purge-pantheon-cloudflare-caches' ); ?></button></p>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Recent purges', 'purge-pantheon-cloudflare-caches' ); ?></h2>
		<?php $cpcf_entries = $log->entries(); ?>
		<?php if ( empty( $cpcf_entries ) ) : ?>
			<p><?php echo esc_html( $log->enabled() ? __( 'No purges have been logged yet.', 'purge-pantheon-cloudflare-caches' ) : __( 'Logging is disabled.', 'purge-pantheon-cloudflare-caches' ) ); ?></p>
		<?php else : ?>
			<table class="widefat striped cpcf-table cpcf-log">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th><?php esc_html_e( 'Trigger', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th><?php esc_html_e( 'What was purged', 'purge-pantheon-cloudflare-caches' ); ?></th>
						<th><?php esc_html_e( 'Result', 'purge-pantheon-cloudflare-caches' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cpcf_entries as $cpcf_entry ) : ?>
						<tr>
							<td>
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cpcf_entry['time'] ) ); ?>
								<br /><small><?php echo esc_html( isset( $cpcf_entry['source'] ) ? $cpcf_entry['source'] : '' ); ?></small>
							</td>
							<td><?php echo esc_html( implode( '; ', (array) $cpcf_entry['reasons'] ) ); ?></td>
							<td>
								<?php if ( ! empty( $cpcf_entry['everything'] ) ) : ?>
									<strong><?php esc_html_e( 'Everything', 'purge-pantheon-cloudflare-caches' ); ?></strong>
								<?php else : ?>
									<?php
									$cpcf_count = isset( $cpcf_entry['url_count'] ) ? (int) $cpcf_entry['url_count'] : count( (array) $cpcf_entry['urls'] );
									$cpcf_pref  = count( (array) $cpcf_entry['prefixes'] );
									/* translators: %d: number of URLs */
									echo esc_html( sprintf( _n( '%d URL', '%d URLs', $cpcf_count, 'purge-pantheon-cloudflare-caches' ), $cpcf_count ) );

									if ( $cpcf_pref > 0 ) {
										/* translators: %d: number of prefixes */
										echo esc_html( ', ' . sprintf( _n( '%d prefix', '%d prefixes', $cpcf_pref, 'purge-pantheon-cloudflare-caches' ), $cpcf_pref ) );
									}
									?>
									<?php if ( ! empty( $cpcf_entry['urls'] ) || ! empty( $cpcf_entry['prefixes'] ) ) : ?>
										<details>
											<summary><?php esc_html_e( 'Show', 'purge-pantheon-cloudflare-caches' ); ?></summary>
											<ul class="cpcf-url-list">
												<?php foreach ( (array) $cpcf_entry['prefixes'] as $cpcf_prefix ) : ?>
													<li><code><?php echo esc_html( $cpcf_prefix ); ?>*</code></li>
												<?php endforeach; ?>
												<?php foreach ( (array) $cpcf_entry['urls'] as $cpcf_url ) : ?>
													<li><code><?php echo esc_html( $cpcf_url ); ?></code></li>
												<?php endforeach; ?>
												<?php if ( $cpcf_count > count( (array) $cpcf_entry['urls'] ) ) : ?>
													<li>&hellip;</li>
												<?php endif; ?>
											</ul>
										</details>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php echo wp_kses_post( $admin->describe_targets( $cpcf_entry ) ); ?>
								<?php if ( ! empty( $cpcf_entry['message'] ) ) : ?>
									<br /><small><?php echo esc_html( $cpcf_entry['message'] ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( $admin->action_url( 'clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'purge-pantheon-cloudflare-caches' ); ?></a></p>
		<?php endif; ?>
	</div>
</div>
