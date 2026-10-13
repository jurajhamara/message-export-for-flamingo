<?php
/**
 * Plugin Name:       Message Export for Flamingo
 * Description:       Export Contact Form 7 messages stored by Flamingo to an Excel file, filtered by form, date range, sender e-mail and text.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  flamingo
 * Author:            Juraj Hamara
 * Author URI:        https://jurajhamara.sk
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       message-export-for-flamingo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The plugin only reads Flamingo data. It never writes to or deletes
 * Flamingo messages, and it stores nothing of its own.
 */

require_once __DIR__ . '/includes/class-meff-xlsx-writer.php';
require_once __DIR__ . '/includes/export.php';

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

/**
 * Register the translations bundled in languages/.
 *
 * WordPress 6.8 and later register the Domain Path of active plugins on
 * their own, WordPress 6.7 does not. This only tells WordPress where to
 * look: the file is loaded with the first translated text, and language
 * packs from translate.wordpress.org still take precedence.
 */
function meff_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Needed for the bundled translations on WordPress 6.7.
	load_plugin_textdomain( 'message-export-for-flamingo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'meff_load_textdomain' );

/* -------------------------------------------------------------------------
 * Flamingo check
 * ---------------------------------------------------------------------- */

/**
 * Is Flamingo loaded, with the message class this plugin reads through?
 *
 * The Requires Plugins header stops activation without Flamingo, but
 * WordPress keeps loading an active plugin whose dependency was deleted
 * later, for example over FTP. So the plugin checks for the class itself.
 */
function meff_flamingo_is_ready() {
	return class_exists( 'Flamingo_Inbound_Message' );
}

/**
 * Hook into the admin once all plugins are loaded, so the check does not
 * depend on the order in which WordPress loads them. Without Flamingo,
 * only a notice is shown and nothing else is registered.
 */
function meff_init() {
	if ( ! meff_flamingo_is_ready() ) {
		add_action( 'admin_notices', 'meff_notice_missing_flamingo' );
		return;
	}

	add_action( 'admin_menu', 'meff_admin_menu' );
	add_action( 'wp_ajax_meff_count', 'meff_ajax_count' );
	add_action( 'admin_post_meff_download', 'meff_download' );
}
add_action( 'plugins_loaded', 'meff_init' );

/**
 * Tell users who can manage plugins that Flamingo is missing.
 */
function meff_notice_missing_flamingo() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	wp_admin_notice(
		esc_html__( 'Message Export for Flamingo needs the Flamingo plugin. Install and activate Flamingo to export messages.', 'message-export-for-flamingo' ),
		array( 'type' => 'error' )
	);
}

/* -------------------------------------------------------------------------
 * Filters
 * ---------------------------------------------------------------------- */

/**
 * Count messages through the Flamingo API.
 *
 * Flamingo_Inbound_Message::count() runs the query and returns the number
 * of all matching messages. Loading one of them is enough for that.
 *
 * @param array $args Arguments for Flamingo_Inbound_Message::find().
 * @return int
 */
function meff_count( $args ) {
	$args['posts_per_page'] = 1;

	return Flamingo_Inbound_Message::count( $args );
}

/**
 * Query clause for messages from the given channels.
 *
 * Contact Form 7 files its channels under a parent channel "Contact Form 7",
 * which normally has no messages of its own. Children are left out, so a
 * parent channel only counts the messages filed directly under it.
 *
 * @param int[] $term_ids Channel term IDs.
 * @return array Value for the tax_query argument.
 */
function meff_channel_tax_query( $term_ids ) {
	return array(
		array(
			'taxonomy'         => Flamingo_Inbound_Message::channel_taxonomy,
			'field'            => 'term_id',
			'terms'            => $term_ids,
			'include_children' => false,
		),
	);
}

/**
 * Forms that have messages in the Flamingo inbox, with their number.
 *
 * Each form is a Flamingo channel. A channel stays after its form is deleted
 * in Contact Form 7, so messages from deleted forms can still be exported.
 * Forms with only spam or trashed messages are not listed.
 *
 * @return array Name, slug and number of messages, keyed by term ID, ordered by name.
 */
function meff_get_channels() {
	static $channels = null;

	if ( null !== $channels ) {
		return $channels;
	}

	$channels = array();
	$terms    = get_terms(
		array(
			'taxonomy'   => Flamingo_Inbound_Message::channel_taxonomy,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return $channels;
	}

	foreach ( $terms as $term ) {
		$count = meff_count(
			array(
				'post_status' => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Messages of one form, Flamingo selects them the same way.
				'tax_query'   => meff_channel_tax_query( array( $term->term_id ) ),
			)
		);

		if ( $count ) {
			$channels[ $term->term_id ] = array(
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $count,
			);
		}
	}

	return $channels;
}

/**
 * Period presets, with the days they cover in the site's time zone.
 *
 * @return array Label, first and last day (Y-m-d, empty for no limit), keyed by preset.
 */
function meff_get_periods() {
	$today      = current_datetime();
	$last_month = $today->modify( 'first day of last month' );

	return array(
		'all'        => array(
			'label' => __( 'All time', 'message-export-for-flamingo' ),
			'from'  => '',
			'to'    => '',
		),
		'this-month' => array(
			/* translators: %s: Month and year, for example October 2026. */
			'label' => sprintf( __( 'This month (%s)', 'message-export-for-flamingo' ), wp_date( 'F Y', $today->getTimestamp() ) ),
			'from'  => $today->format( 'Y-m-01' ),
			'to'    => $today->format( 'Y-m-t' ),
		),
		'last-month' => array(
			/* translators: %s: Month and year, for example September 2026. */
			'label' => sprintf( __( 'Last month (%s)', 'message-export-for-flamingo' ), wp_date( 'F Y', $last_month->getTimestamp() ) ),
			'from'  => $last_month->format( 'Y-m-01' ),
			'to'    => $last_month->format( 'Y-m-t' ),
		),
		'this-year'  => array(
			/* translators: %s: Year, for example 2026. */
			'label' => sprintf( __( 'This year (%s)', 'message-export-for-flamingo' ), $today->format( 'Y' ) ),
			'from'  => $today->format( 'Y-01-01' ),
			'to'    => $today->format( 'Y-12-31' ),
		),
		'custom'     => array(
			'label' => __( 'Custom range', 'message-export-for-flamingo' ),
			'from'  => '',
			'to'    => '',
		),
	);
}

/**
 * Filters the Export page opens with: every form, all time.
 *
 * @return array
 */
function meff_default_filters() {
	return array(
		'channels' => array_keys( meff_get_channels() ),
		'period'   => 'all',
		'from'     => '',
		'to'       => '',
		'email'    => '',
		'search'   => '',
		'spam'     => false,
	);
}

/**
 * Read the filters from the submitted Export form. The callers verify the nonce.
 *
 * The form always sends its whole state, so a form that is not checked is
 * simply missing, and no checked form means no messages. For a preset
 * period, from and to are the days the preset covers.
 *
 * @return array Filters in the shape of meff_default_filters().
 */
function meff_get_submitted_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$channels = isset( $_POST['meff_channels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['meff_channels'] ) ) : array();
	$period   = isset( $_POST['meff_period'] ) ? sanitize_key( wp_unslash( $_POST['meff_period'] ) ) : '';
	$from     = isset( $_POST['meff_from'] ) ? meff_sanitize_date( sanitize_text_field( wp_unslash( $_POST['meff_from'] ) ) ) : '';
	$to       = isset( $_POST['meff_to'] ) ? meff_sanitize_date( sanitize_text_field( wp_unslash( $_POST['meff_to'] ) ) ) : '';
	$email    = isset( $_POST['meff_email'] ) ? sanitize_text_field( wp_unslash( $_POST['meff_email'] ) ) : '';
	$search   = isset( $_POST['meff_search'] ) ? sanitize_text_field( wp_unslash( $_POST['meff_search'] ) ) : '';
	$spam     = ! empty( $_POST['meff_spam'] );
	// phpcs:enable

	$periods = meff_get_periods();

	if ( ! isset( $periods[ $period ] ) ) {
		$period = 'all';
	}

	if ( 'custom' !== $period ) {
		$from = $periods[ $period ]['from'];
		$to   = $periods[ $period ]['to'];
	} elseif ( '' !== $from && '' !== $to && $from > $to ) {
		// Dates entered the other way round still mean the same days.
		list( $from, $to ) = array( $to, $from );
	}

	return array(
		'channels' => array_values( array_intersect( array_keys( meff_get_channels() ), $channels ) ),
		'period'   => $period,
		'from'     => $from,
		'to'       => $to,
		'email'    => $email,
		'search'   => $search,
		'spam'     => $spam,
	);
}

/**
 * Accept a day in the Y-m-d format of a date input, or nothing.
 *
 * @param string $value Submitted value.
 * @return string The day, or an empty string if it is not a valid day.
 */
function meff_sanitize_date( $value ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
		return '';
	}

	return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
}

/**
 * Arguments for Flamingo_Inbound_Message::find() that select the messages
 * matching the filters.
 *
 * At least one form must be selected. With an empty list of channels, the
 * query would not be limited to any channel and would match every message.
 *
 * @param array $filters Filters from meff_get_submitted_filters().
 * @return array
 */
function meff_query_args( $filters ) {
	$args = array(
		'post_status' => $filters['spam'] ? array( 'publish', Flamingo_Inbound_Message::spam_status ) : 'publish',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Messages of the selected forms, Flamingo selects them the same way.
		'tax_query'   => meff_channel_tax_query( $filters['channels'] ),
	);

	/*
	 * post_date is the time of sending in the site's time zone, so a message
	 * sent at 0:05 on 1 February belongs to February, although in UTC it was
	 * still January. Whole days, from 0:00:00 to 23:59:59.
	 */
	if ( '' !== $filters['from'] || '' !== $filters['to'] ) {
		$range = array(
			'column'    => 'post_date',
			'inclusive' => true,
		);

		if ( '' !== $filters['from'] ) {
			$range['after'] = $filters['from'];
		}

		if ( '' !== $filters['to'] ) {
			$range['before'] = $filters['to'];
		}

		$args['date_query'] = array( $range );
	}

	// A part of the address is enough. MySQL compares it regardless of case.
	if ( '' !== $filters['email'] ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only when the e-mail filter is used, Flamingo keeps the sender in post meta.
		$args['meta_query'] = array(
			array(
				'key'     => '_from_email',
				'value'   => $filters['email'],
				'compare' => 'LIKE',
			),
		);
	}

	// The same search as on the Inbound Messages page: every word must appear
	// in the subject, a field or the details Flamingo keeps with the message.
	if ( '' !== $filters['search'] ) {
		$args['s'] = $filters['search'];
	}

	return $args;
}

/**
 * Number of messages matching the filters.
 *
 * @param array $filters Filters from meff_get_submitted_filters().
 * @return int
 */
function meff_count_messages( $filters ) {
	if ( empty( $filters['channels'] ) ) {
		return 0;
	}

	return meff_count( meff_query_args( $filters ) );
}

/**
 * Label of the download button and the note next to it, as plain text.
 *
 * Some languages, Slovak among them, separate thousands with &nbsp;.
 * export.js sets the label as text, where the entity would show as it is,
 * so the number gets the character itself.
 *
 * @param array $filters Filters the messages were counted with.
 * @param int   $count   Number of matching messages.
 * @return array Number of messages, button label and note (empty if there is nothing to explain).
 */
function meff_button_state( $filters, $count ) {
	$note = '';

	if ( empty( $filters['channels'] ) ) {
		$note = __( 'Select at least one form.', 'message-export-for-flamingo' );
	} elseif ( ! $count ) {
		$note = __( 'No messages match the selected filters.', 'message-export-for-flamingo' );
	}

	return array(
		'count' => $count,
		'label' => sprintf(
			/* translators: %s: Number of messages. */
			_n( 'Download Excel (%s message)', 'Download Excel (%s messages)', $count, 'message-export-for-flamingo' ),
			html_entity_decode( number_format_i18n( $count ), ENT_QUOTES, 'UTF-8' )
		),
		'note'  => $note,
	);
}

/* -------------------------------------------------------------------------
 * Export page
 * ---------------------------------------------------------------------- */

/**
 * Add the Export page to the Flamingo menu, below Inbound Messages.
 *
 * It uses the same capability as Inbound Messages, so whoever can read the
 * messages can export them, including on sites that remap Flamingo
 * capabilities with the flamingo_map_meta_cap filter.
 */
function meff_admin_menu() {
	$hook = add_submenu_page(
		'flamingo',
		__( 'Export Messages', 'message-export-for-flamingo' ),
		__( 'Export', 'message-export-for-flamingo' ),
		'flamingo_edit_inbound_messages',
		'message-export-for-flamingo',
		'meff_export_page'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'meff_load_export_page' );
	}
}

/**
 * Load the page's CSS and JavaScript on the Export page only.
 */
function meff_load_export_page() {
	add_action( 'admin_enqueue_scripts', 'meff_enqueue_assets' );
}

/**
 * Enqueue the page's CSS and JavaScript. The file time is the version, so
 * browsers load a changed file without a separate version number to keep.
 */
function meff_enqueue_assets() {
	$dir = plugin_dir_path( __FILE__ ) . 'assets/';
	$url = plugin_dir_url( __FILE__ ) . 'assets/';

	wp_enqueue_style( 'meff-export', $url . 'export.css', array(), (string) filemtime( $dir . 'export.css' ) );
	wp_enqueue_script( 'meff-export', $url . 'export.js', array(), (string) filemtime( $dir . 'export.js' ), array( 'in_footer' => true ) );
}

/**
 * Render the Export page.
 *
 * The page opens with every form and all time selected, so one click
 * exports everything. While the filters change, export.js asks
 * meff_ajax_count() for the number of matching messages and shows it on the
 * button. The form is sent by POST, so the sender's e-mail filter does not
 * end up in the URL or in server logs.
 */
function meff_export_page() {
	$channels = meff_get_channels();
	?>
	<div class="wrap meff-export">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php

	// The filters are no use if the file cannot be created.
	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_admin_notice(
			esc_html( meff_no_zip_message() ),
			array(
				'type'               => 'error',
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	</div>
		<?php
		return;
	}

	if ( ! $channels ) {
		?>
		<p><?php esc_html_e( 'There are no messages in Flamingo yet. They will appear here after someone sends one of your forms.', 'message-export-for-flamingo' ); ?></p>
	</div>
		<?php
		return;
	}

	$filters = meff_default_filters();
	$state   = meff_button_state( $filters, meff_count_messages( $filters ) );
	?>
		<p><?php esc_html_e( 'Choose which messages to download. The Excel file has one row for each message.', 'message-export-for-flamingo' ); ?></p>

		<form id="meff-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-count-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-error="<?php esc_attr_e( 'The number of messages could not be updated. Reload the page and try again.', 'message-export-for-flamingo' ); ?>">
			<input type="hidden" name="action" value="meff_download">
			<?php wp_nonce_field( 'meff_export' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Forms', 'message-export-for-flamingo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Forms', 'message-export-for-flamingo' ); ?></legend>
							<?php foreach ( $channels as $term_id => $channel ) : ?>
								<label>
									<input type="checkbox" name="meff_channels[]" value="<?php echo esc_attr( $term_id ); ?>" <?php checked( in_array( $term_id, $filters['channels'], true ) ); ?>>
									<?php echo esc_html( $channel['name'] ); ?>
									<span class="count">(<?php echo esc_html( number_format_i18n( $channel['count'] ) ); ?>)</span>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Period', 'message-export-for-flamingo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Period', 'message-export-for-flamingo' ); ?></legend>
							<?php foreach ( meff_get_periods() as $key => $period ) : ?>
								<label>
									<input type="radio" name="meff_period" value="<?php echo esc_attr( $key ); ?>" <?php checked( $filters['period'], $key ); ?>>
									<?php echo esc_html( $period['label'] ); ?>
								</label>
								<?php if ( 'custom' === $key ) : ?>
									<span class="meff-dates">
										<span class="meff-date">
											<label for="meff-from"><?php echo esc_html_x( 'from', 'start of a date range', 'message-export-for-flamingo' ); ?></label>
											<input type="date" id="meff-from" name="meff_from">
										</span>
										<span class="meff-date">
											<label for="meff-to"><?php echo esc_html_x( 'to', 'end of a date range', 'message-export-for-flamingo' ); ?></label>
											<input type="date" id="meff-to" name="meff_to">
										</span>
									</span>
								<?php endif; ?>
								<br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>

			<details class="meff-more">
				<summary><?php esc_html_e( 'More options', 'message-export-for-flamingo' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="meff-email"><?php esc_html_e( 'Sender e-mail', 'message-export-for-flamingo' ); ?></label></th>
						<td>
							<input type="text" id="meff-email" name="meff_email" class="regular-text" inputmode="email" autocomplete="off" spellcheck="false" aria-describedby="meff-email-description">
							<p class="description" id="meff-email-description"><?php esc_html_e( 'The whole address or a part of it, for example @gmail.com.', 'message-export-for-flamingo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="meff-search"><?php esc_html_e( 'Text', 'message-export-for-flamingo' ); ?></label></th>
						<td>
							<input type="text" id="meff-search" name="meff_search" class="regular-text" autocomplete="off" aria-describedby="meff-search-description">
							<p class="description" id="meff-search-description"><?php esc_html_e( 'Only messages that contain all of these words, anywhere in the message.', 'message-export-for-flamingo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Spam', 'message-export-for-flamingo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="meff_spam" value="1">
								<?php esc_html_e( 'Also include messages marked as spam', 'message-export-for-flamingo' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</details>

			<p class="submit">
				<button type="submit" id="meff-download" class="button button-primary" <?php disabled( 0, $state['count'] ); ?>><?php echo esc_html( $state['label'] ); ?></button>
				<span class="spinner meff-spinner"></span>
				<span id="meff-note" class="meff-note" aria-live="polite"><?php echo esc_html( $state['note'] ); ?></span>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Tell export.js how many messages match the submitted filters, with the
 * button label and note to show.
 */
function meff_ajax_count() {
	check_ajax_referer( 'meff_export' );

	if ( ! current_user_can( 'flamingo_edit_inbound_messages' ) ) {
		wp_send_json_error( null, 403 );
	}

	$filters = meff_get_submitted_filters();

	wp_send_json_success( meff_button_state( $filters, meff_count_messages( $filters ) ) );
}

/**
 * Download the messages matching the submitted filters as an .xlsx file.
 *
 * The file is created in the temporary folder, sent and deleted at once.
 * The browser stays on the Export page. If there is nothing to download,
 * for example because the messages were deleted since the page was loaded,
 * a page with the reason and a link back is shown instead.
 */
function meff_download() {
	check_admin_referer( 'meff_export' );

	if ( ! current_user_can( 'flamingo_edit_inbound_messages' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to export messages.', 'message-export-for-flamingo' ), 403 );
	}

	$export = meff_create_export( meff_get_submitted_filters() );

	if ( is_wp_error( $export ) ) {
		wp_die(
			esc_html( $export->get_error_message() ),
			esc_html__( 'Export Messages', 'message-export-for-flamingo' ),
			array(
				'response'  => 'meff_no_messages' === $export->get_error_code() ? 200 : 500,
				'back_link' => true,
			)
		);
	}

	clearstatcache( true, $export['path'] );

	// The file holds personal data, no copy may stay in a cache.
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' );
	header( 'Content-Length: ' . filesize( $export['path'] ) );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Sends a temporary file as it is, WP_Filesystem has no streaming.
	readfile( $export['path'] );
	wp_delete_file( $export['path'] );
	exit;
}
