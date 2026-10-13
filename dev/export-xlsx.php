<?php
/**
 * Lokálny testovací web: vytvorí export cez meff_create_export(), bez
 * prehliadača, a vypíše čas, pamäť a počet správ.
 *
 * Spúšťa sa cez WP-CLI v priečinku lokálneho WordPressu:
 *   wp eval-file ".../dev/export-xlsx.php" out=/cesta/export.xlsx
 *   wp eval-file ".../dev/export-xlsx.php" out=/cesta/x.xlsx channels=3,4 spam=1
 *   wp eval-file ".../dev/export-xlsx.php" out=/cesta/x.xlsx period=custom from=2026-02-01 to=2026-02-28 email=gmail search=faktúra
 *
 * Filtre obchádzajú meff_get_submitted_filters(), ID kanálov sa teda
 * nekontrolujú. Obsah súboru vypíše dev/dump-xlsx.py.
 *
 * Súbor nepatrí do pluginu, priečinok dev/ musí byť v .distignore.
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Len pre lokálny web (WP_ENVIRONMENT_TYPE = local) cez WP-CLI.\n";
	return;
}

$mef_dev_opts = array(
	'out'      => '',
	'channels' => 'all',
	'period'   => 'all',
	'from'     => '',
	'to'       => '',
	'spam'     => '',
	'email'    => '',
	'search'   => '',
);
foreach ( (array) ( $args ?? array() ) as $mef_dev_arg ) {
	list( $mef_dev_key, $mef_dev_value ) = array_pad( explode( '=', $mef_dev_arg, 2 ), 2, '' );
	$mef_dev_opts[ $mef_dev_key ]        = $mef_dev_value;
}

if ( '' === $mef_dev_opts['out'] ) {
	WP_CLI::error( 'Chýba out=/cesta/k/suboru.xlsx' );
}

$mef_dev_filters = meff_default_filters();
if ( 'all' !== $mef_dev_opts['channels'] ) {
	$mef_dev_filters['channels'] = array_map( 'intval', array_filter( explode( ',', $mef_dev_opts['channels'] ) ) );
}
$mef_dev_filters['period'] = $mef_dev_opts['period'];
$mef_dev_filters['from']   = $mef_dev_opts['from'];
$mef_dev_filters['to']     = $mef_dev_opts['to'];
$mef_dev_filters['spam']   = (bool) $mef_dev_opts['spam'];
$mef_dev_filters['email']  = $mef_dev_opts['email'];
$mef_dev_filters['search'] = $mef_dev_opts['search'];

$mef_dev_start  = microtime( true );
$mef_dev_export = meff_create_export( $mef_dev_filters );
$mef_dev_time   = microtime( true ) - $mef_dev_start;

if ( is_wp_error( $mef_dev_export ) ) {
	WP_CLI::error( $mef_dev_export->get_error_code() . ': ' . $mef_dev_export->get_error_message() );
}

copy( $mef_dev_export['path'], $mef_dev_opts['out'] );
wp_delete_file( $mef_dev_export['path'] );

WP_CLI::log( sprintf( 'Súbor na stiahnutie: %s, %d B', $mef_dev_export['filename'], filesize( $mef_dev_opts['out'] ) ) );
WP_CLI::log( sprintf( 'Správ: %d, čas %.2f s, pamäť max %.1f MB', meff_count_messages( $mef_dev_filters ), $mef_dev_time, memory_get_peak_usage() / MB_IN_BYTES ) );

// Dočasné súbory exportu musia byť zmazané.
$mef_dev_left = glob( trailingslashit( get_temp_dir() ) . 'meff-*' );
if ( $mef_dev_left ) {
	WP_CLI::warning( sprintf( 'V dočasnom priečinku ostalo: %d', count( $mef_dev_left ) ) );
}
