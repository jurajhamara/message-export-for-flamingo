<?php
/**
 * Writer of a simple .xlsx file: one sheet with a header row, text and
 * date cells.
 *
 * Rows go to a temporary file as they come, so memory does not grow with
 * the number of rows. save() then packs the file with ZipArchive.
 *
 * Every value is written as text (an inline string), never as a formula or
 * a number guessed from the text, so "=1+1" and "+421 999 123 456" stay
 * exactly as they were typed. Dates are real dates with a date format.
 *
 * The class knows nothing about Flamingo. It needs the zip extension of PHP,
 * check class_exists( 'ZipArchive' ) before using it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write one sheet to an .xlsx file.
 */
class MEFF_Xlsx_Writer {

	/**
	 * Most characters Excel keeps in a cell. It counts UTF-16 units, so an
	 * emoji takes two.
	 */
	const MAX_CELL_LENGTH = 32767;

	/**
	 * Columns are made at least this wide and at most this wide, in characters.
	 */
	const MIN_WIDTH = 8;
	const MAX_WIDTH = 50;

	/*
	 * Cell styles, positions in cellXfs in styles.xml.
	 */
	const STYLE_HEADER = 1;
	const STYLE_DATE   = 2;
	const STYLE_TEXT   = 3;
	const STYLE_WRAP   = 4;

	/**
	 * Columns: label, type (text or date) and width in characters.
	 *
	 * @var array[]
	 */
	private $columns = array();

	/**
	 * Path of the temporary file with the sheet.
	 *
	 * @var string
	 */
	private $sheet_path = '';

	/**
	 * Handle of the temporary file with the sheet.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Rows written so far, the header included.
	 *
	 * @var int
	 */
	private $rows = 0;

	/**
	 * XML not yet written to the file. Writing in larger pieces is faster.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Excel format code of date columns, empty for the built-in format.
	 *
	 * @var string
	 */
	private $date_format = '';

	/**
	 * Start the sheet and write the header row.
	 *
	 * Without a date format, dates use built-in format 22, a short date and
	 * time in the format of the reader's computer.
	 *
	 * @param array[] $columns     Each with label, type ('text' or 'date') and width in characters.
	 * @param string  $date_format Optional. Excel format code of date columns, see date_format_from_php().
	 * @return true|WP_Error
	 */
	public function open( $columns, $date_format = '' ) {
		$this->columns     = array_values( $columns );
		$this->date_format = (string) $date_format;
		$this->rows        = 0;
		$this->buffer      = '';

		$this->sheet_path = wp_tempnam( 'meff-sheet' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A temporary file written in pieces, WP_Filesystem has no streaming.
		$this->handle = $this->sheet_path ? fopen( $this->sheet_path, 'wb' ) : false;

		if ( ! $this->handle ) {
			$this->handle = null;
			$this->cleanup();

			return new WP_Error( 'meff_temp_file', __( 'A temporary file for the export could not be created.', 'message-export-for-flamingo' ) );
		}

		$cols = '';

		foreach ( $this->columns as $index => $column ) {
			$width = max( self::MIN_WIDTH, min( self::MAX_WIDTH, (int) $column['width'] ) );
			$cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $index + 1, $width );

			$this->columns[ $index ]['width'] = $width;
		}

		// The header row stays visible while scrolling.
		$this->buffer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
			. '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
			. '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
			. '</sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. '<cols>' . $cols . '</cols>'
			. '<sheetData>';

		$this->write_row( wp_list_pluck( $this->columns, 'label' ), true );

		return true;
	}

	/**
	 * Add a row of values, one for each column in the order of the columns.
	 *
	 * A text column takes a string, a date column a date and time in the
	 * Y-m-d H:i:s format. An empty value leaves the cell empty.
	 *
	 * @param array $values Values of the row.
	 * @return true|WP_Error
	 */
	public function add_row( $values ) {
		$this->write_row( array_values( $values ), false );

		if ( strlen( $this->buffer ) > 65536 ) {
			return $this->flush();
		}

		return true;
	}

	/**
	 * Finish the sheet and pack the whole file.
	 *
	 * @param string $sheet_name Name of the sheet tab.
	 * @param string $path       Where to save the .xlsx file. An existing file is replaced.
	 * @return true|WP_Error
	 */
	public function save( $sheet_name, $path ) {
		$range = 'A1:' . self::column_name( count( $this->columns ) - 1 ) . max( 1, $this->rows );

		$this->buffer .= '</sheetData>'
			. '<autoFilter ref="' . $range . '"/>'
			. '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
			. '</worksheet>';

		$result = $this->flush();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See open().
		fclose( $this->handle );
		$this->handle = null;

		$sheet_name = self::sheet_name( $sheet_name );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'meff_zip', __( 'The Excel file could not be created.', 'message-export-for-flamingo' ) );
		}

		$zip->addFromString( '[Content_Types].xml', self::content_types_xml() );
		$zip->addFromString( '_rels/.rels', self::package_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook_xml( $sheet_name, $range ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml( $this->date_format ) );
		$zip->addFile( $this->sheet_path, 'xl/worksheets/sheet1.xml' );

		// The sheet file is read only now, so it may be deleted only after this.
		if ( ! $zip->close() ) {
			return new WP_Error( 'meff_zip', __( 'The Excel file could not be created.', 'message-export-for-flamingo' ) );
		}

		$this->cleanup();

		return true;
	}

	/**
	 * Delete the temporary file. Safe to call more than once.
	 */
	public function cleanup() {
		if ( $this->handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See open().
			fclose( $this->handle );
			$this->handle = null;
		}

		if ( $this->sheet_path ) {
			wp_delete_file( $this->sheet_path );
			$this->sheet_path = '';
		}
	}

	/**
	 * Make a value safe for a text cell.
	 *
	 * Line breaks become \n, which Excel shows as a new line in the cell.
	 * Characters not allowed in XML 1.0 are removed, otherwise the file
	 * would not open. The vertical tab and form feed, which come with text
	 * copied from Word, are its line and page breaks, so they become \n too.
	 * Text over Excel's limit is cut and ends with an ellipsis.
	 *
	 * @param mixed $value A string or number. Anything else gives an empty string.
	 * @return string
	 */
	public static function clean_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = str_replace( array( "\r\n", "\r", "\v", "\f" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x08\x0E-\x1F\x7F]|\x{FFFE}|\x{FFFF}/u', '', $value );
		$value = trim( (string) $value );

		// Fewer bytes than the limit means fewer UTF-16 units too.
		if ( strlen( $value ) > self::MAX_CELL_LENGTH ) {
			$astral = preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $value );
			$value  = mb_substr( $value, 0, max( 0, self::MAX_CELL_LENGTH - 1 - $astral ) ) . "\u{2026}";
		}

		return $value;
	}

	/**
	 * Excel format code for a date format of PHP, like the date and time
	 * formats in the WordPress settings.
	 *
	 * For example j. n. Y H:i, which shows 5. 6. 2026 08:30, becomes
	 * d\. m\. yyyy hh:mm. Excel shows the names of months and days (F, M,
	 * l, D) in its own language, not in the language of the site. What
	 * Excel cannot show, like the time zone or the English suffix of 1st,
	 * is left out.
	 *
	 * @param string $format Date format of PHP.
	 * @return string Excel format code, or an empty string if nothing of the
	 *                date or time is left.
	 */
	public static function date_format_from_php( $format ) {
		$codes = array(
			'd' => 'dd',
			'j' => 'd',
			'D' => 'ddd',
			'l' => 'dddd',
			'm' => 'mm',
			'n' => 'm',
			'M' => 'mmm',
			'F' => 'mmmm',
			'Y' => 'yyyy',
			'o' => 'yyyy',
			'y' => 'yy',
			'H' => 'hh',
			'G' => 'h',
			'h' => 'hh',
			'g' => 'h',
			'i' => 'mm',
			's' => 'ss',
			'A' => 'AM/PM',
			'a' => 'am/pm',
		);

		// Full date and time formats, written out.
		$expand = array(
			'c' => 'Y-m-d\\TH:i:s',
			'r' => 'D, d M Y H:i:s',
		);

		// Day of the week as a number, day of the year, week, days in the
		// month, leap year, Swatch time, microseconds, time zones, Unix time.
		$unsupported = 'NSwzWtLBuveIOPpTZU';

		$chars = preg_split( '//u', (string) $format, -1, PREG_SPLIT_NO_EMPTY );
		$code  = '';
		$parts = false;

		// Not valid UTF-8.
		if ( ! $chars ) {
			return '';
		}

		for ( $i = 0, $count = count( $chars ); $i < $count; $i++ ) {
			$char = $chars[ $i ];

			if ( '\\' === $char ) {
				++$i;
				$code .= isset( $chars[ $i ] ) ? self::format_literal( $chars[ $i ] ) : '';
			} elseif ( isset( $codes[ $char ] ) ) {
				$code .= $codes[ $char ];
				$parts = true;
			} elseif ( isset( $expand[ $char ] ) ) {
				$code .= self::date_format_from_php( $expand[ $char ] );
				$parts = true;
			} elseif ( false === strpos( $unsupported, $char ) ) {
				$code .= self::format_literal( $char );
			}
		}

		return $parts ? trim( (string) preg_replace( '/ {2,}/', ' ', $code ) ) : '';
	}

	/**
	 * Length of the longest line of a text, in characters.
	 *
	 * @param string $text Text from clean_text().
	 * @return int
	 */
	public static function text_width( $text ) {
		$width = 0;

		foreach ( explode( "\n", $text ) as $line ) {
			$width = max( $width, mb_strlen( $line ) );
		}

		return $width;
	}

	/**
	 * Column name in Excel: 0 is A, 25 is Z, 26 is AA.
	 *
	 * @param int $index Position of the column, from 0.
	 * @return string
	 */
	public static function column_name( $index ) {
		$name = '';

		for ( $index++; $index > 0; $index = intdiv( $index - 1, 26 ) ) {
			$name = chr( 65 + ( $index - 1 ) % 26 ) . $name;
		}

		return $name;
	}

	/**
	 * Add a row to the buffer.
	 *
	 * @param array $values Values of the row, in the order of the columns.
	 * @param bool  $header Whether this is the header row.
	 */
	private function write_row( $values, $header ) {
		++$this->rows;

		$xml = '<row r="' . $this->rows . '">';

		foreach ( $this->columns as $index => $column ) {
			$value = isset( $values[ $index ] ) ? $values[ $index ] : '';
			$ref   = self::column_name( $index ) . $this->rows;

			if ( ! $header && 'date' === $column['type'] ) {
				$serial = self::date_serial( $value );

				if ( null !== $serial ) {
					$xml .= '<c r="' . $ref . '" s="' . self::STYLE_DATE . '"><v>' . $serial . '</v></c>';
				}

				continue;
			}

			$value = self::clean_text( $value );

			if ( '' === $value ) {
				continue;
			}

			if ( $header ) {
				$style = self::STYLE_HEADER;
			} elseif ( false !== strpos( $value, "\n" ) || mb_strlen( $value ) > $column['width'] ) {
				$style = self::STYLE_WRAP;
			} else {
				$style = self::STYLE_TEXT;
			}

			$xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
				. htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' )
				. '</t></is></c>';
		}

		$this->buffer .= $xml . '</row>';
	}

	/**
	 * A character shown as it is in an Excel format code.
	 *
	 * The space and the colon between hours and minutes stay as they are,
	 * Excel needs the colon to tell minutes from months. Anything else is
	 * escaped with a backslash, so a letter or a dot is not taken for a code.
	 *
	 * @param string $char One character.
	 * @return string
	 */
	private static function format_literal( $char ) {
		return ' ' === $char || ':' === $char ? $char : '\\' . $char;
	}

	/**
	 * Write the buffer to the file.
	 *
	 * @return true|WP_Error
	 */
	private function flush() {
		// A full disk can stop the write partway, without returning false.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- See open().
		if ( strlen( $this->buffer ) !== fwrite( $this->handle, $this->buffer ) ) {
			return new WP_Error( 'meff_temp_file', __( 'The export could not be written to a temporary file. The server may be out of disk space.', 'message-export-for-flamingo' ) );
		}

		$this->buffer = '';

		return true;
	}

	/**
	 * Date and time as Excel stores it: days since 30 December 1899, the
	 * time of day as a fraction. Excel has no time zones, so the time is
	 * taken as it is.
	 *
	 * @param string $datetime Date and time in the Y-m-d H:i:s format.
	 * @return string|null The number as text, or null for an invalid date.
	 */
	private static function date_serial( $datetime ) {
		$date = DateTime::createFromFormat( '!Y-m-d H:i:s', (string) $datetime, new DateTimeZone( 'UTC' ) );

		if ( ! $date ) {
			return null;
		}

		/*
		 * Unix time 0 is day 25569. With 12 decimals the number has 17 digits,
		 * enough to read back as exactly the number Excel stores for the same
		 * time typed in, so 11:45 does not show as 11:44:59. number_format()
		 * ignores the locale, which a cast to string did not before PHP 8.
		 */
		return number_format( $date->getTimestamp() / DAY_IN_SECONDS + 25569, 12, '.', '' );
	}

	/**
	 * A sheet name Excel accepts: at most 31 characters, none of : \ / ? * [ ].
	 *
	 * @param string $name Wanted name.
	 * @return string
	 */
	private static function sheet_name( $name ) {
		$name = trim( str_replace( array( ':', '\\', '/', '?', '*', '[', ']' ), ' ', self::clean_text( $name ) ), " '" );
		$name = mb_substr( preg_replace( '/\s+/u', ' ', $name ), 0, 31 );

		return '' === $name ? 'Sheet1' : $name;
	}

	/**
	 * [Content_Types].xml: what each part of the package is.
	 *
	 * @return string
	 */
	private static function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	/**
	 * _rels/.rels: where the workbook is.
	 *
	 * @return string
	 */
	private static function package_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * xl/workbook.xml: the only sheet and the range of its autofilter, which
	 * Excel also keeps as a hidden name.
	 *
	 * @param string $sheet_name Name from sheet_name().
	 * @param string $range      Range of the autofilter, for example A1:F120.
	 * @return string
	 */
	private static function workbook_xml( $sheet_name, $range ) {
		$absolute = preg_replace_callback(
			'/([A-Z]+)(\d+)/',
			static function ( $cell ) {
				return '$' . $cell[1] . '$' . $cell[2];
			},
			$range
		);
		$quoted   = "'" . str_replace( "'", "''", $sheet_name ) . "'";

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<bookViews><workbookView activeTab="0"/></bookViews>'
			. '<sheets><sheet name="' . htmlspecialchars( $sheet_name, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
			. htmlspecialchars( $quoted . '!' . $absolute, ENT_QUOTES | ENT_XML1, 'UTF-8' )
			. '</definedName></definedNames>'
			. '</workbook>';
	}

	/**
	 * xl/_rels/workbook.xml.rels: where the sheet and the styles are.
	 *
	 * @return string
	 */
	private static function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/**
	 * xl/styles.xml: the cell styles in the order of the STYLE_ constants.
	 *
	 * The header is bold on a light grey background. The date has its own
	 * format, or built-in format 22, which Excel shows as a short date and
	 * time in the format of the user's computer. Text cells have the Text
	 * format (@), so a value edited later stays text. Everything is aligned
	 * to the top, so a cell with several lines does not push its neighbours
	 * to the middle.
	 *
	 * @param string $date_format Excel format code of dates, empty for format 22.
	 * @return string
	 */
	private static function styles_xml( $date_format ) {
		// Own formats are numbered from 164, lower numbers are built in.
		$date_id = '' === $date_format ? 22 : 164;
		$formats = '' === $date_format ? '' : '<numFmts count="1"><numFmt numFmtId="164" formatCode="' . htmlspecialchars( $date_format, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '"/></numFmts>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $formats
			. '<fonts count="2">'
			. '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
			. '</fonts>'
			. '<fills count="3">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="5">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '<xf numFmtId="' . $date_id . '" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>'
			. '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>'
			. '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}
}
