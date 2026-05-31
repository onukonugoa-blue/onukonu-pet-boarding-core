<?php
/**
 * OPB_Xlsx_Reader
 *
 * Minimal pure-PHP XLSX reader. XLSX is a ZIP archive; no external library required.
 * Reads the first worksheet only and returns an associative-row array identical
 * in shape to the CSV reader output.
 */
class OPB_Xlsx_Reader {

    /**
     * @return array{headers: string[], rows: array[], error?: string}
     */
    public static function to_rows( string $path ): array {
        if ( ! class_exists('ZipArchive') ) {
            return [ 'headers' => [], 'rows' => [], 'error' => 'PHP ZipArchive extension not available.' ];
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return [ 'headers' => [], 'rows' => [], 'error' => "Cannot open XLSX file." ];
        }

        $shared_strings = self::read_shared_strings( $zip );
        $rows_raw       = self::read_sheet( $zip, $shared_strings );
        $zip->close();

        if ( empty( $rows_raw ) ) {
            return [ 'headers' => [], 'rows' => [] ];
        }

        $header_row = array_shift( $rows_raw );
        $headers    = array_map( 'trim', $header_row );
        if ( isset($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF") ) {
            $headers[0] = substr($headers[0], 3);
        }
        $col_count = count( $headers );

        $rows = [];
        foreach ( $rows_raw as $raw ) {
            $padded = array_pad( $raw, $col_count, '' );
            $row    = array_combine( $headers, array_slice( $padded, 0, $col_count ) );
            if ( ! array_filter($row, fn($v) => trim((string)$v) !== '') ) continue;
            $rows[] = $row;
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function read_shared_strings( ZipArchive $zip ): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ( ! $xml ) return [];

        $dom = new DOMDocument();
        $dom->loadXML( $xml, LIBXML_NOERROR | LIBXML_NOWARNING );
        $ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $out = [];

        foreach ( $dom->getElementsByTagNameNS($ns, 'si') as $si ) {
            $text = '';
            foreach ( $si->getElementsByTagNameNS($ns, 't') as $t ) {
                $text .= $t->nodeValue;
            }
            $out[] = $text;
        }
        return $out;
    }

    private static function read_sheet( ZipArchive $zip, array $ss ): array {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ( ! $xml ) {
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ( $rels ) {
                $dom = new DOMDocument();
                $dom->loadXML( $rels, LIBXML_NOERROR );
                foreach ( $dom->getElementsByTagName('Relationship') as $rel ) {
                    if ( str_contains($rel->getAttribute('Type'), 'worksheet') ) {
                        $target = ltrim( $rel->getAttribute('Target'), '/' );
                        $xml    = $zip->getFromName('xl/' . $target);
                        if ( $xml ) break;
                    }
                }
            }
        }
        if ( ! $xml ) return [];

        $dom = new DOMDocument();
        $dom->loadXML( $xml, LIBXML_NOERROR | LIBXML_NOWARNING );
        $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ( $dom->getElementsByTagNameNS($ns, 'row') as $row_el ) {
            $sparse = [];
            foreach ( $row_el->getElementsByTagNameNS($ns, 'c') as $c ) {
                $ref       = $c->getAttribute('r');
                $col_index = self::col_letter_to_index( preg_replace('/\d+/', '', $ref) );
                $type      = $c->getAttribute('t');
                $v_el      = $c->getElementsByTagNameNS($ns, 'v')->item(0);
                $is_el     = $c->getElementsByTagNameNS($ns, 'is')->item(0);

                if ( $type === 's' ) {
                    $idx = $v_el ? (int)$v_el->nodeValue : 0;
                    $val = $ss[$idx] ?? '';
                } elseif ( $type === 'inlineStr' && $is_el ) {
                    $val = '';
                    foreach ( $is_el->getElementsByTagNameNS($ns, 't') as $t ) {
                        $val .= $t->nodeValue;
                    }
                } elseif ( $type === 'b' ) {
                    $val = $v_el ? ($v_el->nodeValue ? 'TRUE' : 'FALSE') : '';
                } elseif ( $type === 'str' ) {
                    $val = $v_el ? $v_el->nodeValue : '';
                } else {
                    $val = $v_el ? $v_el->nodeValue : '';
                }
                $sparse[$col_index] = (string)$val;
            }

            if ( empty($sparse) ) continue;
            $max_col = max(array_keys($sparse));
            $dense   = [];
            for ( $i = 0; $i <= $max_col; $i++ ) {
                $dense[] = $sparse[$i] ?? '';
            }
            $rows[] = $dense;
        }
        return $rows;
    }

    /** 'A' → 0, 'B' → 1, 'Z' → 25, 'AA' → 26 */
    private static function col_letter_to_index( string $col ): int {
        $col = strtoupper( trim($col) );
        $n   = 0;
        for ( $i = 0; $i < strlen($col); $i++ ) {
            $n = $n * 26 + ( ord($col[$i]) - 64 );
        }
        return $n - 1;
    }
}
