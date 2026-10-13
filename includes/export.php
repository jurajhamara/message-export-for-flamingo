<?php
/**
 * Export: read the messages matching the filters through the Flamingo API
 * and write them to an .xlsx file, one row for each message and one column
 * for each field, named by the label the visitor saw in the form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Fields of the forms
 * ---------------------------------------------------------------------- */

/**
 * Contact Form 7 forms, keyed by the term ID of their Flamingo channel.
 *
 * Contact Form 7 keeps the channel's term ID with the form, in the post meta
 * _flamingo. A form without it is matched by its slug, which Contact Form 7
 * gives to the channel too. A channel whose form was deleted, or every
 * channel while Contact Form 7 is not active, has no form here.
 *
 * @return WPCF7_ContactForm[]
 */
function meff_get_cf7_forms() {
	static $forms = null;

	if ( null !== $forms ) {
		return $forms;
	}

	$forms = array();

	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return $forms;
	}

	$unmatched = array();

	foreach ( WPCF7_ContactForm::find() as $form ) {
		$meta = get_post_meta( $form->id(), '_flamingo', true );

		if ( is_array( $meta ) && ! empty( $meta['channel'] ) ) {
			$forms[ (int) $meta['channel'] ] = $form;
		} else {
			$unmatched[] = $form;
		}
	}

	foreach ( $unmatched as $form ) {
		$term = get_term_by( 'slug', $form->name(), Flamingo_Inbound_Message::channel_taxonomy );

		if ( $term && ! isset( $forms[ $term->term_id ] ) ) {
			$forms[ $term->term_id ] = $form;
		}
	}

	return $forms;
}

/**
 * Fields of the Contact Form 7 form behind a channel, in the order of the form.
 *
 * @param int $term_id Channel term ID.
 * @return array|null Label, type and whether to leave the field out, keyed
 *                    by field name. Null if the channel has no form.
 */
function meff_get_form_fields( $term_id ) {
	$forms = meff_get_cf7_forms();

	if ( ! isset( $forms[ $term_id ] ) ) {
		return null;
	}

	$form     = $forms[ $term_id ];
	$template = (string) $form->prop( 'form' );
	$fields   = array();
	$offset   = 0;

	foreach ( $form->scan_form_tags() as $tag ) {
		$position = meff_locate_form_tag( $template, $tag, $offset );

		if ( '' !== $tag->name && ! isset( $fields[ $tag->name ] ) ) {
			$fields[ $tag->name ] = array(
				'label' => meff_form_tag_label( $template, $tag, $position, $offset ),
				'type'  => $tag->basetype,
				// Contact Form 7 accepts no message without a required consent,
				// so its column would say the same thing in every row.
				'skip'  => 'acceptance' === $tag->basetype && ! $tag->has_option( 'optional' ),
			);
		}

		if ( $position ) {
			$offset = $position[1];
		}
	}

	return $fields;
}

/**
 * Where a form-tag is in the form template.
 *
 * Contact Form 7 does not tell, so the tag is looked up by its type and
 * name, from where the previous tag ended.
 *
 * @param string        $template Form template.
 * @param WPCF7_FormTag $tag      Form-tag from scan_form_tags().
 * @param int           $offset   Byte offset to search from.
 * @return int[]|null Byte offsets of the start and the end of the tag, or null if not found.
 */
function meff_locate_form_tag( $template, $tag, $offset ) {
	$pattern = '/\[' . preg_quote( $tag->type, '/' );

	if ( '' !== $tag->raw_name ) {
		$pattern .= '[\s\x{00a0}]+' . preg_quote( $tag->raw_name, '/' );
	}

	$pattern .= '(?=[\s\x{00a0}\]])[^\]]*\]/u';

	if ( ! preg_match( $pattern, $template, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
		return null;
	}

	$start = $match[0][1];
	$end   = $start + strlen( $match[0][0] );

	// A tag with content, like [acceptance name] text [/acceptance], ends after its closing tag.
	if ( preg_match( '/\G[^\[]*?\[\/' . preg_quote( $tag->type, '/' ) . '\]/u', $template, $closing, 0, $end ) ) {
		$end += strlen( $closing[0] );
	}

	return array( $start, $end );
}

/**
 * The text the visitor sees as the name of a field.
 *
 * Tried in this order:
 * 1. Consent: the text between [acceptance name] and [/acceptance].
 * 2. Text in a <label> around the tag: <label> Your name [text your-name] </label>.
 * 3. The last line of text before the tag, after the previous one, as with
 *    a group of checkboxes: "Days" on its own line before [checkbox days ...].
 *    A heading does not count, it names a group of fields.
 * 4. The placeholder: [text your-name placeholder "Your name"].
 * 5. The field name, readable: your-name becomes "Your name".
 *
 * @param string        $template     Form template.
 * @param WPCF7_FormTag $tag          Form-tag from scan_form_tags().
 * @param int[]|null    $position     Start and end of the tag from meff_locate_form_tag().
 * @param int           $previous_end Byte offset where the previous tag ended.
 * @return string
 */
function meff_form_tag_label( $template, $tag, $position, $previous_end ) {
	$values = (array) $tag->values;
	$first  = (string) reset( $values );
	$label  = '';

	if ( 'acceptance' === $tag->basetype ) {
		$label = meff_label_text( meff_template_text( '' !== $tag->content ? $tag->content : $first ) );
	}

	if ( '' === $label && $position ) {
		$label = meff_enclosing_label( $template, $position[0], $position[1] );
	}

	if ( '' === $label && $position ) {
		$before = substr( $template, $previous_end, $position[0] - $previous_end );
		$before = preg_replace( '/<(h[1-6])\b[^>]*>.*?<\/\1\s*>/is', "\n", $before );
		$lines  = array_reverse( explode( "\n", meff_template_text( $before ) ) );

		foreach ( $lines as $line ) {
			$label = meff_label_text( $line );

			if ( '' !== $label ) {
				break;
			}
		}
	}

	if ( '' === $label && ( $tag->has_option( 'placeholder' ) || $tag->has_option( 'watermark' ) ) ) {
		$label = meff_label_text( $first );
	}

	if ( '' === $label ) {
		$label = meff_field_name_label( $tag->name );
	}

	return $label;
}

/**
 * Text of the <label> element the form-tag is in.
 *
 * @param string $template Form template.
 * @param int    $start    Byte offset of the start of the tag.
 * @param int    $end      Byte offset of the end of the tag.
 * @return string The label, or an empty string if the tag is not in one.
 */
function meff_enclosing_label( $template, $start, $end ) {
	$before = substr( $template, 0, $start );

	if ( ! preg_match_all( '/<label\b[^>]*>/i', $before, $opening, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$opening = end( $opening[0] );
	$inside  = $opening[1] + strlen( $opening[0] );

	// The last label was closed before the tag, so it belongs to something else.
	if ( false !== stripos( substr( $before, $inside ), '</label' ) ) {
		return '';
	}

	$closing = stripos( $template, '</label', $end );

	if ( false === $closing ) {
		return '';
	}

	return meff_label_text( meff_template_text( substr( $template, $inside, $closing - $inside ) ) );
}

/**
 * Plain text of a part of a form template.
 *
 * Form-tags, line breaks and block elements become new lines, inline
 * elements like <strong> just disappear, entities are decoded.
 *
 * @param string $html Part of a form template.
 * @return string
 */
function meff_template_text( $html ) {
	$html = preg_replace( '/\[\/?[a-zA-Z][0-9a-zA-Z:._-]*\*?(?:[\s\x{00a0}][^\]]*)?\]/u', "\n", $html );
	$html = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', "\n", $html );
	$html = preg_replace( '/<(?!\/?(?:a|abbr|b|cite|code|em|i|mark|q|s|small|span|strong|sub|sup|u)\b)[^>]*>/i', "\n", $html );
	$html = preg_replace( '/<[^>]*>/', '', $html );

	return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * A label on one line, without a trailing colon or asterisk.
 *
 * "E-mail:" and "Name *" are then the same as "E-mail" and "Name".
 *
 * @param string $text Text from meff_template_text() or a tag value.
 * @return string
 */
function meff_label_text( $text ) {
	$text = trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $text ) );

	return trim( (string) preg_replace( '/[\s:*]+$/u', '', $text ) );
}

/**
 * A field name made readable, for fields without a label: your-name
 * becomes "Your name".
 *
 * @param string $name Field name.
 * @return string
 */
function meff_field_name_label( $name ) {
	return ucfirst( trim( preg_replace( '/[-_\s]+/', ' ', (string) $name ) ) );
}

/**
 * Fields other plugins add to forms for their bot checks.
 *
 * Contact Form 7 stores every submitted field whose name does not start
 * with an underscore, so these tokens end up in Flamingo too. Its own
 * Turnstile and reCAPTCHA fields start with an underscore.
 *
 * @return string[]
 */
function meff_ignored_fields() {
	return array( 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response' );
}

/* -------------------------------------------------------------------------
 * Export
 * ---------------------------------------------------------------------- */

/**
 * Explain that the server cannot create the file.
 *
 * @return string
 */
function meff_no_zip_message() {
	return __( 'The Excel file cannot be created, because the zip extension of PHP is missing on this server. Ask your hosting provider to enable it.', 'message-export-for-flamingo' );
}

/**
 * Write the messages matching the filters to an .xlsx file.
 *
 * Two steps, so that memory does not grow with the number of messages:
 *
 * 1. Messages are read in batches through Flamingo_Inbound_Message::find(),
 *    oldest first, and their values written to a temporary file, one line
 *    for each message. Meanwhile the fields of each form are noted.
 * 2. With all fields known, the columns are put together and the lines from
 *    the temporary file become rows of the sheet.
 *
 * @param array $filters Filters from meff_get_submitted_filters().
 * @return array|WP_Error Path of the file and the name to download it with.
 *                        The file is temporary, the caller deletes it.
 */
function meff_create_export( $filters ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'meff_no_zip', meff_no_zip_message() );
	}

	// Without a channel, the query would not be limited to any and match every message.
	if ( empty( $filters['channels'] ) ) {
		return new WP_Error( 'meff_no_messages', __( 'Select at least one form.', 'message-export-for-flamingo' ) );
	}

	$rows_path = wp_tempnam( 'meff-rows' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A temporary file read and written in pieces, WP_Filesystem has no streaming.
	$rows = $rows_path ? fopen( $rows_path, 'w+b' ) : false;

	if ( ! $rows ) {
		if ( $rows_path ) {
			wp_delete_file( $rows_path );
		}

		return new WP_Error( 'meff_temp_file', __( 'A temporary file for the export could not be created.', 'message-export-for-flamingo' ) );
	}

	$forms = array();

	foreach ( $filters['channels'] as $term_id ) {
		$forms[ $term_id ] = meff_get_form_fields( $term_id );
	}

	$found  = meff_collect_rows( $filters, $forms, $rows );
	$result = is_wp_error( $found ) ? $found : meff_write_export( $found, $forms, $rows );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
	fclose( $rows );
	wp_delete_file( $rows_path );

	return $result;
}

/**
 * Step 1: read the messages and write their values to the temporary file.
 *
 * Each line is JSON: the date and time of sending in the site's time zone,
 * the channel term ID, whether it is spam, and the values of the fields as
 * text, keyed by field name. Empty values are left out.
 *
 * @param array    $filters Filters from meff_get_submitted_filters().
 * @param array    $forms   Fields of the forms from meff_get_form_fields(), keyed by channel term ID.
 * @param resource $rows    Handle of the temporary file.
 * @return array|WP_Error What was found: number of messages and of spam,
 *                        and the fields in order with the width of their
 *                        longest value, keyed by channel term ID.
 */
function meff_collect_rows( $filters, $forms, $rows ) {
	$channels = meff_get_channels();
	$by_slug  = array();

	foreach ( $filters['channels'] as $term_id ) {
		if ( isset( $channels[ $term_id ] ) ) {
			$by_slug[ $channels[ $term_id ]['slug'] ] = $term_id;
		}
	}

	$found = array(
		'count'  => 0,
		'spam'   => 0,
		'fields' => array(),
		'widths' => array(),
	);

	$ignored = meff_ignored_fields();
	$yes     = _x( 'Yes', 'value of a consent column', 'message-export-for-flamingo' );
	$batch   = 500;
	$args    = meff_query_args( $filters );

	$args['posts_per_page'] = $batch;
	$args['orderby']        = array(
		'date' => 'ASC',
		'ID'   => 'ASC',
	);
	$args['no_found_rows']  = true;
	$args['offset']         = 0;

	do {
		// Each batch gets its own time, so a large export is not cut off halfway.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A long export, the way WordPress core extends the time for long tasks.
			set_time_limit( MINUTE_IN_SECONDS );
		}

		$messages = Flamingo_Inbound_Message::find( $args );

		foreach ( $messages as $message ) {
			$post = get_post( $message->id() );

			if ( ! $post || ! isset( $by_slug[ $message->channel ] ) ) {
				continue;
			}

			$term_id = $by_slug[ $message->channel ];
			$form    = $forms[ $term_id ];
			$consent = (array) $message->consent;
			$spam    = Flamingo_Inbound_Message::spam_status === $post->post_status;
			$keys    = array();
			$values  = array();

			foreach ( (array) $message->fields as $key => $value ) {
				$key = (string) $key;

				if ( in_array( $key, $ignored, true ) || ! empty( $form[ $key ]['skip'] ) ) {
					continue;
				}

				$keys[] = $key;
				$text   = meff_cell_text( $value );

				if ( '' === $text ) {
					continue;
				}

				// A consent is stored as 1. Without the form, a field with a
				// consent text in the message is one too.
				if ( isset( $form[ $key ] ) ? 'acceptance' === $form[ $key ]['type'] : isset( $consent[ $key ] ) ) {
					$text = $yes;
				}

				$values[ $key ] = $text;
				$width          = MEFF_Xlsx_Writer::text_width( $text );

				if ( ! isset( $found['widths'][ $term_id ][ $key ] ) || $found['widths'][ $term_id ][ $key ] < $width ) {
					$found['widths'][ $term_id ][ $key ] = $width;
				}
			}

			if ( ! isset( $found['fields'][ $term_id ] ) ) {
				$found['fields'][ $term_id ] = array();
			}

			meff_merge_field_order( $found['fields'][ $term_id ], $keys );

			$line = wp_json_encode( array( $post->post_date, $term_id, $spam, (object) $values ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- See meff_create_export().
			if ( false === $line || false === fwrite( $rows, $line . "\n" ) ) {
				return new WP_Error( 'meff_temp_file', __( 'The export could not be written to a temporary file. The server may be out of disk space.', 'message-export-for-flamingo' ) );
			}

			++$found['count'];

			if ( $spam ) {
				++$found['spam'];
			}
		}

		$args['offset'] += $batch;

		meff_free_memory();
	} while ( count( $messages ) === $batch );

	if ( ! $found['count'] ) {
		return new WP_Error( 'meff_no_messages', __( 'No messages match the selected filters.', 'message-export-for-flamingo' ) );
	}

	// Forms in the order of the Export page, not in the order of their first message.
	$fields = array();

	foreach ( $filters['channels'] as $term_id ) {
		if ( isset( $found['fields'][ $term_id ] ) ) {
			$fields[ $term_id ] = $found['fields'][ $term_id ];
		}
	}

	$found['fields'] = $fields;

	return $found;
}

/**
 * A field value as text for a cell. Several values, as from checkboxes,
 * are separated by commas.
 *
 * @param mixed $value Value from Flamingo.
 * @return string
 */
function meff_cell_text( $value ) {
	if ( ! is_array( $value ) ) {
		return MEFF_Xlsx_Writer::clean_text( $value );
	}

	$parts = array();

	array_walk_recursive(
		$value,
		static function ( $item ) use ( &$parts ) {
			$item = MEFF_Xlsx_Writer::clean_text( $item );

			if ( '' !== $item ) {
				$parts[] = $item;
			}
		}
	);

	return implode( ', ', $parts );
}

/**
 * Add the fields of a message to the fields seen so far, keeping their order.
 *
 * Without the form, the messages are the only source of the order, and it
 * is not stable: Contact Form 7 puts a field the browser did not send, such
 * as an unchecked checkbox, at the end. So a new field goes right after the
 * field before it in the message, not at the end of the list.
 *
 * @param string[] $order Fields seen so far, in order. Changed in place.
 * @param string[] $keys  Fields of the message, in its order.
 */
function meff_merge_field_order( &$order, $keys ) {
	$after = -1;

	foreach ( $keys as $key ) {
		$position = array_search( $key, $order, true );

		if ( false === $position ) {
			$position = $after + 1;
			array_splice( $order, $position, 0, array( $key ) );
		}

		$after = max( $after, $position );
	}
}

/**
 * Let go of the batch just read.
 *
 * WordPress keeps every post and its meta it loads in the object cache for
 * the rest of the request. Without this, memory would grow with each batch.
 */
function meff_free_memory() {
	if ( wp_cache_supports( 'flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

/**
 * Columns of the fields, in this order: fields of the first form, then
 * new fields of the next forms, then fields that are no longer in their
 * form but are in older messages.
 *
 * Fields of different forms with the same label share a column, compared
 * regardless of case. Two fields of one form never share a column, even
 * with the same label. A form without a definition, deleted or with
 * Contact Form 7 inactive, has its fields in the order of the messages.
 *
 * @param array $found Result of meff_collect_rows().
 * @param array $forms Fields of the forms from meff_get_form_fields(), keyed by channel term ID.
 * @return array[] Label, width and the field of each channel, keyed by term ID.
 */
function meff_field_columns( $found, $forms ) {
	$columns = array();
	$removed = array();

	foreach ( $found['fields'] as $term_id => $keys ) {
		$form = $forms[ $term_id ];

		if ( null === $form ) {
			foreach ( $keys as $key ) {
				meff_add_column( $columns, $term_id, $key, meff_field_name_label( $key ), $found );
			}

			continue;
		}

		foreach ( $form as $key => $field ) {
			if ( in_array( $key, $keys, true ) ) {
				meff_add_column( $columns, $term_id, $key, $field['label'], $found );
			}
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $form[ $key ] ) ) {
				$removed[] = array( $term_id, $key );
			}
		}
	}

	foreach ( $removed as $field ) {
		meff_add_column( $columns, $field[0], $field[1], meff_field_name_label( $field[1] ), $found );
	}

	return $columns;
}

/**
 * Put a field into the column with the same label, or into a new one.
 *
 * @param array[] $columns Columns so far. Changed in place.
 * @param int     $term_id Channel term ID.
 * @param string  $key     Field name.
 * @param string  $label   Label of the field.
 * @param array   $found   Result of meff_collect_rows(), for the width.
 */
function meff_add_column( &$columns, $term_id, $key, $label, $found ) {
	$match = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
	$width = isset( $found['widths'][ $term_id ][ $key ] ) ? $found['widths'][ $term_id ][ $key ] : 0;

	foreach ( $columns as $index => $column ) {
		if ( $column['match'] === $match && ! isset( $column['fields'][ $term_id ] ) ) {
			$columns[ $index ]['fields'][ $term_id ] = $key;
			$columns[ $index ]['width']              = max( $column['width'], $width );

			return;
		}
	}

	$columns[] = array(
		'label'  => $label,
		'match'  => $match,
		'width'  => $width,
		'fields' => array( $term_id => $key ),
	);
}

/**
 * Step 2: put the columns together and write the sheet from the temporary file.
 *
 * The first column is the date of sending. The form is a column only when
 * the messages come from more than one form, spam only when there is some.
 *
 * @param array    $found Result of meff_collect_rows().
 * @param array    $forms Fields of the forms from meff_get_form_fields(), keyed by channel term ID.
 * @param resource $rows  Handle of the temporary file.
 * @return array|WP_Error Path of the .xlsx file and the name to download it with.
 */
function meff_write_export( $found, $forms, $rows ) {
	$channels  = meff_get_channels();
	$names     = array();
	$show_form = count( $found['fields'] ) > 1;
	$show_spam = $found['spam'] > 0;
	$fields    = meff_field_columns( $found, $forms );
	$yes       = _x( 'Yes', 'value of the spam column', 'message-export-for-flamingo' );
	$date      = meff_date_format();

	// WordPress stores term names with HTML entities, like &amp; for &.
	foreach ( array_keys( $found['fields'] ) as $term_id ) {
		$names[ $term_id ] = wp_specialchars_decode( $channels[ $term_id ]['name'], ENT_QUOTES );
	}

	$columns = array(
		array(
			'label' => _x( 'Date', 'column header', 'message-export-for-flamingo' ),
			'type'  => 'date',
			'width' => $date['width'],
		),
	);

	if ( $show_form ) {
		$columns[] = meff_text_column( _x( 'Form', 'column header', 'message-export-for-flamingo' ), max( array_map( 'mb_strlen', $names ) ) );
	}

	if ( $show_spam ) {
		$columns[] = meff_text_column( _x( 'Spam', 'column header', 'message-export-for-flamingo' ), 0 );
	}

	foreach ( $fields as $column ) {
		$columns[] = meff_text_column( $column['label'], $column['width'] );
	}

	// Writing the sheet gets its own time, like each batch of messages.
	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- See meff_collect_rows().
		set_time_limit( MINUTE_IN_SECONDS );
	}

	$writer = new MEFF_Xlsx_Writer();
	$result = $writer->open( $columns, $date['code'] );

	rewind( $rows );

	while ( true === $result ) {
		$line = fgets( $rows );

		if ( false === $line ) {
			break;
		}

		list( $date, $term_id, $spam, $values ) = json_decode( $line, true );

		$cells = array( $date );

		if ( $show_form ) {
			$cells[] = $names[ $term_id ];
		}

		if ( $show_spam ) {
			$cells[] = $spam ? $yes : '';
		}

		foreach ( $fields as $column ) {
			$key     = isset( $column['fields'][ $term_id ] ) ? $column['fields'][ $term_id ] : null;
			$cells[] = null !== $key && isset( $values[ $key ] ) ? $values[ $key ] : '';
		}

		$result = $writer->add_row( $cells );
	}

	$path = true === $result ? wp_tempnam( 'meff-export' ) : '';

	if ( $path ) {
		$result = $writer->save( _x( 'Messages', 'sheet name', 'message-export-for-flamingo' ), $path );
	}

	$writer->cleanup();

	if ( true !== $result ) {
		if ( $path ) {
			wp_delete_file( $path );
		}

		return is_wp_error( $result ) ? $result : new WP_Error( 'meff_temp_file', __( 'A temporary file for the export could not be created.', 'message-export-for-flamingo' ) );
	}

	return array(
		'path'     => $path,
		'filename' => meff_export_filename( $names ),
	);
}

/**
 * Format of the date column: the date and time formats from Settings >
 * General, so the dates read as they do on the site.
 *
 * The width comes from the longest date of a year in that format, with
 * names of months in the language of the site. If the formats give nothing
 * Excel can show, the reader's computer decides the format.
 *
 * @return array Excel format code (empty for the built-in one) and width in characters.
 */
function meff_date_format() {
	$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	$code   = MEFF_Xlsx_Writer::date_format_from_php( $format );
	$width  = 16;

	if ( '' !== $code ) {
		$utc   = new DateTimeZone( 'UTC' );
		$width = 0;

		for ( $month = 1; $month <= 12; $month++ ) {
			$width = max( $width, mb_strlen( wp_date( $format, gmmktime( 23, 59, 59, $month, 28, 2026 ), $utc ) ) );
		}
	}

	// About three more characters for the filter button in the header.
	return array(
		'code'  => $code,
		'width' => max( $width + 2, MEFF_Xlsx_Writer::text_width( _x( 'Date', 'column header', 'message-export-for-flamingo' ) ) + 4 ),
	);
}

/**
 * A text column wide enough for its longest value.
 *
 * The header adds about three characters for the filter button. A long
 * header, typically a question, counts only up to 30 characters and wraps
 * to more lines, so short answers do not get a very wide column.
 *
 * @param string $label Column header.
 * @param int    $width Length of the longest value, in characters.
 * @return array Column for MEFF_Xlsx_Writer::open().
 */
function meff_text_column( $label, $width ) {
	return array(
		'label' => $label,
		'type'  => 'text',
		'width' => max( $width + 2, min( MEFF_Xlsx_Writer::text_width( $label ) + 4, 30 ) ),
	);
}

/**
 * Name of the downloaded file: after the form if there is one, otherwise
 * after the site, with today's date. For example prihlaska-na-kurz-2026-10-13.xlsx.
 *
 * @param string[] $names Names of the forms in the file.
 * @return string
 */
function meff_export_filename( $names ) {
	$name = sanitize_title( 1 === count( $names ) ? reset( $names ) : get_bloginfo( 'name' ) );

	// A name in a script without Latin letters would come out percent-encoded.
	if ( '' === $name || false !== strpos( $name, '%' ) ) {
		$name = 'messages';
	}

	return $name . '-' . wp_date( 'Y-m-d' ) . '.xlsx';
}
