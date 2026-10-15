<?php
/**
 * Standalone .po → .mo compiler.
 *
 * Usage (from plugin root):
 *   php tools/compile-mo.php languages/woo-customers-price-list-it_IT.po
 *
 * Compiles the given .po file into a .mo binary file (same basename, same dir).
 * The output file is suitable for use with load_plugin_textdomain().
 *
 * Format specification:
 *   https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
 *
 * @package WCPL
 * @since   1.0.0
 */

declare( strict_types = 1 );

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php compile-mo.php <path/to/file.po>\n" );
	exit( 1 );
}

$po_file = $argv[1];

if ( ! file_exists( $po_file ) ) {
	fwrite( STDERR, "Error: File not found: {$po_file}\n" );
	exit( 1 );
}

$mo_file = preg_replace( '/\.po$/i', '.mo', $po_file );

if ( $mo_file === $po_file ) {
	fwrite( STDERR, "Error: Input file must have a .po extension.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Parse the .po file
// ---------------------------------------------------------------------------

/**
 * Parse a .po file into an array of [ msgid => msgstr ] pairs.
 * - Header entry (msgid "") is excluded.
 * - Multi-line strings (using concatenation across lines) are supported.
 * - Fuzzy entries (#, fuzzy) are skipped.
 * - Only entries with a non-empty msgstr are included.
 *
 * @param string $file Path to .po file.
 * @return array<string, string>
 */
function parse_po( string $file ): array {
	$lines   = file( $file, FILE_IGNORE_NEW_LINES );
	$entries = [];
	$msgid   = null;
	$msgstr  = null;
	$is_fuzzy = false;
	$in_msgid  = false;
	$in_msgstr = false;

	$flush = static function () use ( &$entries, &$msgid, &$msgstr, &$is_fuzzy, &$in_msgid, &$in_msgstr ): void {
		if ( $msgid !== null && $msgstr !== null && $msgid !== '' && $msgstr !== '' && ! $is_fuzzy ) {
			$entries[ $msgid ] = $msgstr;
		}
		$msgid      = null;
		$msgstr     = null;
		$is_fuzzy   = false;
		$in_msgid   = false;
		$in_msgstr  = false;
	};

	foreach ( $lines as $line ) {
		$line = rtrim( $line );

		// Blank line → flush current entry.
		if ( $line === '' ) {
			$flush();
			continue;
		}

		// Flag comment.
		if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
			$is_fuzzy = true;
			continue;
		}

		// Any comment → ignore.
		if ( $line[0] === '#' ) {
			continue;
		}

		/**
		 * Decode a quoted gettext string (strips outer quotes, unescapes sequences).
		 */
		$decode = static function ( string $quoted ): string {
			// Remove surrounding double quotes.
			if ( str_starts_with( $quoted, '"' ) && str_ends_with( $quoted, '"' ) ) {
				$quoted = substr( $quoted, 1, -1 );
			}
			// Unescape standard gettext escape sequences.
			return strtr( $quoted, [
				'\\n'  => "\n",
				'\\t'  => "\t",
				'\\r'  => "\r",
				'\\"'  => '"',
				'\\\\' => '\\',
			] );
		};

		// msgid "..."
		if ( str_starts_with( $line, 'msgid ' ) ) {
			$flush();
			$msgid    = $decode( substr( $line, 6 ) );
			$in_msgid  = true;
			$in_msgstr = false;
			continue;
		}

		// msgstr "..."
		if ( str_starts_with( $line, 'msgstr ' ) ) {
			$msgstr    = $decode( substr( $line, 7 ) );
			$in_msgid  = false;
			$in_msgstr = true;
			continue;
		}

		// Continuation line "..."
		if ( str_starts_with( $line, '"' ) ) {
			$part = $decode( $line );
			if ( $in_msgid && $msgid !== null ) {
				$msgid .= $part;
			} elseif ( $in_msgstr && $msgstr !== null ) {
				$msgstr .= $part;
			}
			continue;
		}

		// msgstr[N] → not supported (plural forms), skip.
		if ( str_starts_with( $line, 'msgstr[' ) || str_starts_with( $line, 'msgid_plural' ) ) {
			$in_msgid  = false;
			$in_msgstr = false;
			continue;
		}
	}

	// Flush last entry.
	$flush();

	return $entries;
}

// ---------------------------------------------------------------------------
// Generate .mo binary
// ---------------------------------------------------------------------------

/**
 * Compile an array of [ msgid => msgstr ] into GNU .mo binary format.
 *
 * The .mo format (little-endian):
 *   Offset  Size  Content
 *   0       4     Magic number: 0x950412DE
 *   4       4     File format revision: 0
 *   8       4     N = number of strings
 *   12      4     Offset of original strings table (O)
 *   16      4     Offset of translated strings table (T)
 *   20      4     Size of hash table: 0 (omitted)
 *   24      4     Offset of hash table: 0 (omitted)
 *   O       N*8   Original strings: N pairs of (length, offset)
 *   T       N*8   Translated strings: N pairs of (length, offset)
 *   [data]        Concatenated null-terminated strings
 *
 * @param array<string, string> $entries
 * @return string Binary .mo content.
 */
function compile_mo( array $entries ): string {
	// Sort by msgid for deterministic output (not required but canonical).
	ksort( $entries );

	$n         = count( $entries );
	$header    = 7 * 4;        // 7 × 4-byte fields = 28 bytes
	$orig_off  = $header;      // Offset of original strings table
	$trans_off = $orig_off + $n * 8;    // Offset of translated strings table
	$data_off  = $trans_off + $n * 8;   // Offset of first string in data pool

	$orig_table  = '';  // N × (length, offset) for original strings
	$trans_table = '';  // N × (length, offset) for translated strings
	$data_pool   = '';  // Concatenated null-terminated strings

	$offset = $data_off;

	$originals   = array_keys( $entries );
	$translations = array_values( $entries );

	// Build original strings table + data.
	$orig_offsets = [];
	foreach ( $originals as $str ) {
		$len            = strlen( $str );
		$orig_table    .= pack( 'VV', $len, $offset );
		$orig_offsets[] = $offset;
		$data_pool     .= $str . "\x00";
		$offset        += $len + 1;
	}

	// Build translated strings table + data (appended to same pool).
	foreach ( $translations as $str ) {
		$len           = strlen( $str );
		$trans_table  .= pack( 'VV', $len, $offset );
		$data_pool    .= $str . "\x00";
		$offset       += $len + 1;
	}

	// Header (little-endian 32-bit unsigned integers).
	$header_bin = pack( 'V7',
		0x950412DE, // Magic number (little-endian)
		0,          // File format revision
		$n,         // Number of strings
		$orig_off,  // Offset of original strings table
		$trans_off, // Offset of translated strings table
		0,          // Size of hash table (omitted)
		0           // Offset of hash table (omitted)
	);

	return $header_bin . $orig_table . $trans_table . $data_pool;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

echo "Parsing {$po_file}…\n";
$entries = parse_po( $po_file );

if ( empty( $entries ) ) {
	fwrite( STDERR, "Warning: No translatable entries found in {$po_file}\n" );
	exit( 1 );
}

$count = count( $entries );
echo "Found {$count} translated string(s).\n";

$mo_binary = compile_mo( $entries );

if ( file_put_contents( $mo_file, $mo_binary ) === false ) {
	fwrite( STDERR, "Error: Could not write to {$mo_file}\n" );
	exit( 1 );
}

$size = number_format( strlen( $mo_binary ) );
echo "Written {$mo_file} ({$size} bytes)\n";
echo "Done.\n";
exit( 0 );
