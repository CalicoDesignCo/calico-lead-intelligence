<?php
/**
 * Plugin Name: Calico Lead Intelligence
 * Description: Lead attribution and diagnostics for Gravity Forms, including first-touch and last-touch source tracking, campaign reporting, and WordPress dashboards.
 * Version: 4.5.30
 * Update URI: https://github.com/CalicoDesignCo/calico-lead-intelligence
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Calico Design
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: calico-lead-intelligence
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CLI_VERSION', '4.5.30' );
define( 'CLI_CATALOG_VERSION', '2.3.4' );
define( 'CLI_REPORT_STORAGE_VERSION', '2' );
define( 'CLI_REPORT_CACHE_SCHEMA', '11' );
define( 'CLI_FILE', __FILE__ );
define( 'CLI_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/class-cli-updater.php';
CLI_GitHub_Updater::register( __FILE__ );

/**
 * Keep Calico product menus together without merging their pages or icons.
 *
 * This helper is duplicated across Calico products and guarded so whichever
 * product loads first coordinates the shared sidebar group.
 */
if ( ! function_exists( 'calico_register_admin_suite_menu' ) ) {
    function calico_register_admin_suite_menu() {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;
        add_action( 'admin_menu', 'calico_add_admin_suite_separators', 999 );
        add_filter( 'custom_menu_order', '__return_true', 999 );
        add_filter( 'menu_order', 'calico_admin_suite_menu_order', 999 );
        add_action( 'admin_head', 'calico_admin_suite_menu_styles' );
    }

    function calico_add_admin_suite_separators() {
        global $menu;
        if ( ! is_array( $menu ) ) return;

        $suite_slugs = [ 'calico-lead-intelligence', 'calico-conversion-clicks' ];
        $active_suite_slugs = array_intersect( $suite_slugs, wp_list_pluck( $menu, 2 ) );
        if ( ! $active_suite_slugs ) return;

        $menu_slugs = wp_list_pluck( $menu, 2 );
        if ( ! in_array( 'separator-calico-suite-start', $menu_slugs, true ) ) {
            $menu[] = [ '', 'manage_options', 'separator-calico-suite-start', '', 'calico-suite-divider calico-suite-separator-start' ];
        }
        if ( ! in_array( 'separator-calico-suite-end', $menu_slugs, true ) ) {
            $menu[] = [ '', 'manage_options', 'separator-calico-suite-end', '', 'calico-suite-divider calico-suite-separator-end' ];
        }
    }

    function calico_admin_suite_menu_order( $menu_order ) {
        if ( ! is_array( $menu_order ) ) return $menu_order;

        $suite_slugs = [ 'calico-lead-intelligence', 'calico-conversion-clicks' ];
        $active_suite_slugs = array_values( array_intersect( $suite_slugs, $menu_order ) );
        if ( ! $active_suite_slugs ) return $menu_order;

        $original_positions = [];
        foreach ( $active_suite_slugs as $slug ) {
            $position = array_search( $slug, $menu_order, true );
            if ( false !== $position ) $original_positions[] = $position;
        }
        if ( ! $original_positions ) return $menu_order;

        $group_slugs = array_merge( [ 'separator-calico-suite-start' ], $active_suite_slugs, [ 'separator-calico-suite-end' ] );
        $ordered_menu = array_values( array_diff( $menu_order, $group_slugs ) );
        $insert_at = min( min( $original_positions ), count( $ordered_menu ) );
        array_splice( $ordered_menu, $insert_at, 0, $group_slugs );
        return $ordered_menu;
    }

    function calico_admin_suite_menu_styles() {
        echo '<style id="calico-admin-suite-menu">#adminmenu li.calico-suite-divider{height:17px!important;margin:0!important;padding:0!important;position:relative;pointer-events:none}#adminmenu li.calico-suite-divider>a{display:none!important}#adminmenu li.calico-suite-divider:before{content:"";position:absolute;left:0;right:0;top:8px;border-top:1px solid #3c434a}#adminmenu li.calico-suite-separator-start:after{content:"CALICO";position:absolute;left:7px;top:4px;padding:0 3px;background:#1d2327;color:#8c8f94;font-size:7px;font-weight:600;letter-spacing:.08em;line-height:9px}#adminmenu li.calico-suite-separator-end{height:13px!important}#adminmenu li.calico-suite-separator-end:before{top:6px}.folded #adminmenu li.calico-suite-divider{height:8px!important}.folded #adminmenu li.calico-suite-divider:after{display:none}</style>';
    }
}
if ( function_exists( 'add_action' ) && function_exists( 'add_filter' ) ) calico_register_admin_suite_menu();




/**
 * Minimal dependency-free PDF writer for scheduled dashboard reports.
 * Uses the built-in Helvetica font and creates text-first, multi-page PDFs.
 */
final class CLI_Simple_PDF {
    private $pages = [];
    private $commands = [];
    private $images = [];
    private $y = 750;
    private $page_width = 612;
    private $page_height = 792;
    private $margin = 48;

    public function __construct() { $this->new_page(); }

    public function new_page() {
        if ( $this->commands ) $this->pages[] = implode( "\n", $this->commands );
        $this->commands = [];
        $this->y = 750;
    }

    public function line( $text, $size = 10, $bold = false, $indent = 0, $leading = 14 ) {
        $font = $bold ? 'F2' : 'F1';
        $text = $this->ascii( $text );
        $max_chars = max( 18, (int) floor( ( $this->page_width - ( 2 * $this->margin ) - $indent ) / max( 4.5, $size * 0.52 ) ) );
        $wrapped = $this->wrap( $text, $max_chars );
        foreach ( $wrapped as $row ) {
            if ( $this->y < 58 ) $this->new_page();
            $x = $this->margin + $indent;
            $this->commands[] = 'BT /' . $font . ' ' . (float) $size . ' Tf 1 0 0 1 ' . (float) $x . ' ' . (float) $this->y . ' Tm (' . $this->escape( $row ) . ') Tj ET';
            $this->y -= $leading;
        }
    }

    public function section_header( $title, $subtitle = '', $accent = '#2271b1' ) {
        $this->ensure_space( $subtitle ? 58 : 42 );
        $bottom = $this->y - 28;
        $this->rectangle( $this->margin, $bottom, $this->page_width - ( 2 * $this->margin ), 28, $accent, $accent );
        $this->draw_text( $title, $this->margin + 12, $bottom + 9, 13, true, '#ffffff' );
        $this->y = $bottom - 10;
        if ( $subtitle ) {
            foreach ( $this->wrap_width( $subtitle, 9, $this->page_width - ( 2 * $this->margin ) - 24 ) as $row ) {
                $this->draw_text( $row, $this->margin + 12, $this->y, 9, false, '#5b6573' );
                $this->y -= 12;
            }
            $this->y -= 6;
        }
    }

    public function metric_cards( $cards, $accent = '#2271b1' ) {
        $cards = array_values( array_filter( (array) $cards, 'is_array' ) );
        if ( ! $cards ) return;
        $this->ensure_space( 82 );
        $gap = 8;
        $count = count( $cards );
        $width = ( ( $this->page_width - ( 2 * $this->margin ) ) - ( $gap * ( $count - 1 ) ) ) / $count;
        $height = 70;
        $bottom = $this->y - $height;
        foreach ( $cards as $index=>$card ) {
            $x = $this->margin + ( $index * ( $width + $gap ) );
            $this->rectangle( $x, $bottom, $width, $height, '#f5f7fa', '#d7dce2', 0.7 );
            $this->rectangle( $x, $this->y - 4, $width, 4, $accent, $accent );
            $this->draw_text( $card['value'] ?? '0', $x + 10, $this->y - 27, 17, true, '#172132' );
            $label_y = $this->y - 43;
            foreach ( array_slice( $this->wrap_width( $card['label'] ?? '', 8, $width - 20 ), 0, 2 ) as $row ) {
                $this->draw_text( $row, $x + 10, $label_y, 8, true, '#303844' );
                $label_y -= 10;
            }
        }
        $this->y = $bottom - 16;
    }

    public function result_card( $title, $detail, $accent = '#2271b1' ) {
        $detail_rows = $this->wrap_width( $detail, 8.5, 346 );
        $height = max( 48, 28 + ( count( $detail_rows ) * 11 ) );
        $this->ensure_space( $height + 10 );
        $bottom = $this->y - $height;
        $this->rectangle( $this->margin, $bottom, $this->page_width - ( 2 * $this->margin ), $height, '#f8fafc', '#d7dce2', 0.7 );
        $this->rectangle( $this->margin, $bottom, 6, $height, $accent, $accent );
        $this->draw_text( $title, $this->margin + 17, $this->y - 26, 10, true, '#253140' );
        $detail_y = $this->y - 20;
        foreach ( $detail_rows as $row ) {
            $this->draw_text( $row, $this->margin + 166, $detail_y, 8.5, false, '#465363' );
            $detail_y -= 11;
        }
        $this->y = $bottom - 9;
    }

    public function two_column_lists( $left_title, $left_items, $right_title, $right_items, $accent = '#2271b1' ) {
        $gap = 14;
        $width = ( ( $this->page_width - ( 2 * $this->margin ) ) - $gap ) / 2;
        $left_rows = $this->ranked_rows( $left_items, 8.5, $width - 24 );
        $right_rows = $this->ranked_rows( $right_items, 8.5, $width - 24 );
        $height = max( 105, 48 + ( max( count( $left_rows ), count( $right_rows ) ) * 11 ) );
        $this->ensure_space( $height + 12 );
        $bottom = $this->y - $height;
        foreach ( [ [ $this->margin, $left_title, $left_rows ], [ $this->margin + $width + $gap, $right_title, $right_rows ] ] as $panel ) {
            [ $x, $title, $rows ] = $panel;
            $this->rectangle( $x, $bottom, $width, $height, '#ffffff', '#d7dce2', 0.7 );
            $this->rectangle( $x, $this->y - 28, $width, 28, $accent, $accent );
            $this->draw_text( $title ?: ' ', $x + 12, $this->y - 19, 11, true, '#ffffff' );
            $row_y = $this->y - 47;
            if ( ! $rows ) $rows = [ [ 'text'=>$title ? 'No data for this period.' : '', 'first'=>false ] ];
            foreach ( $rows as $row ) {
                if ( '' !== $row['text'] ) $this->draw_text( $row['text'], $x + ( $row['first'] ? 12 : 24 ), $row_y, 8.5, $row['first'], $row['first'] ? '#293442' : '#5b6573' );
                $row_y -= 11;
            }
        }
        $this->y = $bottom - 16;
    }

    public function list_panel( $title, $items, $accent = '#2271b1' ) {
        $width = $this->page_width - ( 2 * $this->margin );
        $rows = $this->ranked_rows( $items, 8.5, $width - 24 );
        $height = max( 82, 48 + ( max( 1, count( $rows ) ) * 11 ) );
        $this->ensure_space( $height + 12 );
        $bottom = $this->y - $height;
        $this->rectangle( $this->margin, $bottom, $width, $height, '#ffffff', '#d7dce2', 0.7 );
        $this->rectangle( $this->margin, $this->y - 28, $width, 28, $accent, $accent );
        $this->draw_text( $title, $this->margin + 12, $this->y - 19, 11, true, '#ffffff' );
        $row_y = $this->y - 47;
        if ( ! $rows ) $rows = [ [ 'text'=>'No data for this period.', 'first'=>false ] ];
        foreach ( $rows as $row ) {
            $this->draw_text( $row['text'], $this->margin + ( $row['first'] ? 12 : 24 ), $row_y, 8.5, $row['first'], $row['first'] ? '#293442' : '#5b6573' );
            $row_y -= 11;
        }
        $this->y = $bottom - 16;
    }

    public function rule() {
        if ( $this->y < 62 ) $this->new_page();
        $this->commands[] = sprintf( '0.75 w %.2f %.2f m %.2f %.2f l S', $this->margin, $this->y, $this->page_width - $this->margin, $this->y );
        $this->y -= 14;
    }

    public function spacer( $points = 8 ) { $this->y -= max( 0, (int) $points ); }

    public function ensure_space( $points = 80 ) {
        if ( $this->y - max( 0, (int) $points ) < 58 ) $this->new_page();
    }
    /**
     * Draw a JPEG logo at the top of the report. The image is always scaled
     * proportionally and never exceeds 160 x 55 points in the report header.
     */
    public function logo_header( $jpeg, $pixel_width, $pixel_height, $business = '', $max_width = 160, $max_height = 55 ) {
        if ( ! is_string( $jpeg ) || '' === $jpeg || $pixel_width < 1 || $pixel_height < 1 ) return false;
        $scale = min( $max_width / $pixel_width, $max_height / $pixel_height, 1 );
        $width = max( 1, $pixel_width * $scale );
        $height = max( 1, $pixel_height * $scale );
        $name = 'Im' . ( count( $this->images ) + 1 );
        $this->images[ $name ] = [ 'data' => $jpeg, 'width' => (int) $pixel_width, 'height' => (int) $pixel_height ];
        $bottom = $this->y - $height;
        $this->commands[] = sprintf( 'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q', $width, $height, $this->margin, $bottom, $name );
        $this->y -= $height + 18;
        return true;
    }

    /**
     * Render a compact, multi-page table and repeat its header after page breaks.
     */
    public function table( $headers, $rows, $widths, $font_size = 7, $leading = 9, $accent = '#2271b1' ) {
        $columns = min( count( (array) $headers ), count( (array) $widths ) );
        if ( $columns < 1 ) return;
        $headers = array_slice( array_values( (array) $headers ), 0, $columns );
        $widths = array_slice( array_map( 'floatval', array_values( (array) $widths ) ), 0, $columns );
        if ( $this->y < 92 ) $this->new_page();
        $this->draw_table_row( $headers, $widths, 20, $font_size, $leading, true, true, $accent );
        $alternate = false;
        foreach ( (array) $rows as $row ) {
            $cells = array_slice( array_pad( array_values( (array) $row ), $columns, '' ), 0, $columns );
            $height = $this->table_row_height( $cells, $widths, $font_size, $leading );
            if ( $this->y - $height < 58 ) {
                $this->new_page();
                $this->draw_table_row( $headers, $widths, 20, $font_size, $leading, true, true, $accent );
            }
            $this->draw_table_row( $cells, $widths, $height, $font_size, $leading, false, $alternate, '' );
            $alternate = ! $alternate;
        }
    }

    private function table_row_height( $cells, $widths, $font_size, $leading ) {
        $lines = 1;
        foreach ( $cells as $index => $cell ) {
            $max_chars = max( 4, (int) floor( ( $widths[$index] - 6 ) / max( 3.4, $font_size * 0.52 ) ) );
            $lines = max( $lines, count( $this->wrap( $this->ascii( $cell ), $max_chars ) ) );
        }
        return max( 18, ( $lines * $leading ) + 7 );
    }

    private function draw_table_row( $cells, $widths, $height, $font_size, $leading, $bold, $fill, $accent = '' ) {
        $x = $this->margin;
        $bottom = $this->y - $height;
        $total_width = array_sum( $widths );
        if ( $fill && $accent ) $this->rectangle( $x, $bottom, $total_width, $height, $accent, $accent );
        elseif ( $fill ) $this->commands[] = sprintf( '0.95 g %.2f %.2f %.2f %.2f re f 0 g', $x, $bottom, $total_width, $height );
        $this->commands[] = sprintf( '0.75 G 0.4 w %.2f %.2f %.2f %.2f re S 0 G', $x, $bottom, $total_width, $height );
        $font = $bold ? 'F2' : 'F1';
        foreach ( $cells as $index => $cell ) {
            $width = $widths[$index];
            if ( $index ) $this->commands[] = sprintf( '0.85 G 0.35 w %.2f %.2f m %.2f %.2f l S 0 G', $x, $bottom, $x, $this->y );
            $max_chars = max( 4, (int) floor( ( $width - 6 ) / max( 3.4, $font_size * 0.52 ) ) );
            $lines = $this->wrap( $this->ascii( $cell ), $max_chars );
            $text_y = $this->y - $font_size - 4;
            foreach ( $lines as $line_index => $line ) {
                $this->draw_text( $line, $x + 3, $text_y - ( $line_index * $leading ), $font_size, $bold, $accent ? '#ffffff' : '#1d2327' );
            }
            $x += $width;
        }
        $this->y = $bottom;
    }

    public function output() {
        if ( $this->commands ) { $this->pages[] = implode( "\n", $this->commands ); $this->commands = []; }
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $page_count = count( $this->pages );
        $font_regular = 3;
        $font_bold = 4;
        $objects[$font_regular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$font_bold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $next = 5;
        $image_resources = [];
        foreach ( $this->images as $name => $image ) {
            $image_obj = $next++;
            $image_resources[] = '/' . $name . ' ' . $image_obj . ' 0 R';
            $objects[$image_obj] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image['data'] ) . ">>\nstream\n" . $image['data'] . "\nendstream";
        }
        $xobjects = $image_resources ? ' /XObject << ' . implode( ' ', $image_resources ) . ' >>' : '';
        foreach ( $this->pages as $content ) {
            $page_obj = $next++;
            $content_obj = $next++;
            $kids[] = $page_obj . ' 0 R';
            $objects[$page_obj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $font_regular . ' 0 R /F2 ' . $font_bold . ' 0 R >>' . $xobjects . ' >> /Contents ' . $content_obj . ' 0 R >>';
            $objects[$content_obj] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Count ' . $page_count . ' /Kids [' . implode( ' ', $kids ) . '] >>';
        ksort( $objects );
        $pdf = "%PDF-1.4\n";
        $offsets = [ 0 ];
        $max_obj = max( array_keys( $objects ) );
        for ( $i = 1; $i <= $max_obj; $i++ ) {
            $offsets[$i] = strlen( $pdf );
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xref = strlen( $pdf );
        $pdf .= "xref\n0 " . ( $max_obj + 1 ) . "\n0000000000 65535 f \n";
        for ( $i = 1; $i <= $max_obj; $i++ ) $pdf .= sprintf( "%010d 00000 n \n", $offsets[$i] );
        $pdf .= "trailer\n<< /Size " . ( $max_obj + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function wrap( $text, $max ) {
        if ( '' === $text ) return [ '' ];
        return explode( "\n", wordwrap( $text, $max, "\n", true ) );
    }

    private function wrap_width( $text, $size, $width ) {
        $text = $this->ascii( $text );
        $max_chars = max( 10, (int) floor( $width / max( 4.2, $size * 0.5 ) ) );
        return $this->wrap( $text, $max_chars );
    }

    private function ranked_rows( $items, $size, $width ) {
        $rows = [];
        foreach ( array_values( (array) $items ) as $index=>$item ) {
            foreach ( $this->wrap_width( ( $index + 1 ) . '. ' . $item, $size, $width ) as $part_index=>$part ) {
                $rows[] = [ 'text'=>$part, 'first'=>0 === $part_index ];
            }
        }
        return $rows;
    }

    private function color_values( $hex, $fallback = '#000000' ) {
        $hex = is_string( $hex ) && preg_match( '/^#[0-9a-f]{6}$/i', $hex ) ? $hex : $fallback;
        return [ hexdec( substr( $hex, 1, 2 ) ) / 255, hexdec( substr( $hex, 3, 2 ) ) / 255, hexdec( substr( $hex, 5, 2 ) ) / 255 ];
    }

    private function rectangle( $x, $y, $width, $height, $fill, $stroke = '', $stroke_width = 0.5 ) {
        [ $fr, $fg, $fb ] = $this->color_values( $fill, '#ffffff' );
        $command = sprintf( 'q %.3f %.3f %.3f rg ', $fr, $fg, $fb );
        if ( $stroke ) {
            [ $sr, $sg, $sb ] = $this->color_values( $stroke, '#d7dce2' );
            $command .= sprintf( '%.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re B Q', $sr, $sg, $sb, $stroke_width, $x, $y, $width, $height );
        } else {
            $command .= sprintf( '%.2f %.2f %.2f %.2f re f Q', $x, $y, $width, $height );
        }
        $this->commands[] = $command;
    }

    private function draw_text( $text, $x, $y, $size = 10, $bold = false, $color = '#000000' ) {
        [ $r, $g, $b ] = $this->color_values( $color );
        $font = $bold ? 'F2' : 'F1';
        $text = $this->ascii( $text );
        $this->commands[] = sprintf( 'BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $font, $size, $r, $g, $b, $x, $y, $this->escape( $text ) );
    }

    private function ascii( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
        $text = str_replace( [ '–', '—', '−', '→', '←', '•', '“', '”', '’', '…' ], [ '-', '-', '-', '->', '<-', '-', '"', '"', "'", '...' ], $text );
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
            if ( false !== $converted ) $text = $converted;
        }
        return preg_replace( '/[^\x20-\x7E]/', '', $text );
    }

    private function escape( $text ) { return str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $text ); }
}

final class Calico_Lead_Intelligence {

    private static $instance = null;
    private $report_stats_request_cache = [];

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function access_owner_id() {
        return absint( get_option( 'cli_access_owner_user_id', 0 ) );
    }

    private function pro_enabled() {
        return 'preview-v1' === get_option( 'cli_pro_preview_unlock', '' );
    }

    private function set_pro_preview( $action, $code = '' ) {
        if ( ! current_user_can( 'manage_options' ) || ! $this->current_user_is_access_owner() ) return false;
        if ( 'deactivate' === $action ) {
            update_option( 'cli_pro_preview_unlock', '', false );
            wp_clear_scheduled_hook( 'cli_scheduled_report_event' );
            return true;
        }
        if ( 'activate' !== $action || ! is_string( $code ) || strlen( $code ) > 128 ) return false;
        // Temporary local testing gate, not tamper-proof licensing. Never store the entered code.
        $digest = hash( 'sha256', strtoupper( trim( $code ) ) );
        if ( ! hash_equals( 'a3c997942c8d4abd0188b025e8e25c57e2e01ec002c8d1a9a2c4fc41b822fbeb', $digest ) ) return false;
        update_option( 'cli_pro_preview_unlock', 'preview-v1', false );
        $this->schedule_next_report();
        return true;
    }

    private function require_pro() {
        if ( ! $this->pro_enabled() ) wp_die( 'Activate the temporary Pro unlock in Lead Intelligence Settings first.', 'Pro required', [ 'response' => 403 ] );
    }

    private function render_pro_settings() {
        echo '<div class="cli-card"><h2>Pro Testing Unlock</h2><p>Status: <strong>' . ( $this->pro_enabled() ? 'Pro active' : 'Pro inactive' ) . '</strong></p><p>Temporary local testing access for Scheduled PDF Reports and custom agency branding. This is not a paid license. Deactivating pauses report delivery without deleting settings, history, or lead data. Activation is site-specific and excluded from backups.</p>';
        if ( current_user_can( 'manage_options' ) && $this->current_user_is_access_owner() ) {
            echo '<form method="post">';
            wp_nonce_field( 'cli_pro_preview' );
            if ( $this->pro_enabled() ) {
                echo '<button class="button" name="cli_pro_action" value="deactivate">Deactivate Pro</button>';
            } else {
                echo '<label for="cli-pro-code">Testing unlock code</label> <input type="password" id="cli-pro-code" name="cli_pro_code" maxlength="128" autocomplete="off" required> <button class="button button-primary" name="cli_pro_action" value="activate">Activate Pro</button>';
            }
            echo '</form>';
        } else {
            echo '<p>Only the Access Owner can activate or deactivate Pro. If no owner is assigned, select one in Plugin Access below.</p>';
        }
        echo '</div>';
    }

    private function sanitize_access_mode( $mode ) {
        $mode = sanitize_key( (string) $mode );
        return in_array( $mode, [ 'no_restrictions', 'client', 'stealth' ], true ) ? $mode : '';
    }

    private function access_mode() {
        $mode = $this->sanitize_access_mode( get_option( 'cli_access_mode', '' ) );
        if ( $mode ) return $mode;
        return $this->access_owner_id() ? 'stealth' : 'no_restrictions';
    }

    private function current_user_is_access_owner() {
        $owner_id = $this->access_owner_id();
        return $owner_id && $owner_id === get_current_user_id();
    }

    private function current_user_can_access_plugin() {
        if ( ! current_user_can( 'manage_options' ) ) return false;
        if ( 'stealth' !== $this->access_mode() ) return true;
        return $this->current_user_is_access_owner();
    }

    private function current_user_can_manage_plugin() {
        if ( ! current_user_can( 'manage_options' ) ) return false;
        if ( 'no_restrictions' === $this->access_mode() ) return true;
        return $this->current_user_is_access_owner();
    }

    private function require_plugin_access( $message = '' ) {
        if ( $this->current_user_can_access_plugin() ) return;
        if ( ! $message ) $message = __( 'You are not allowed to access Lead Intelligence.', 'calico-lead-intelligence' );
        wp_die( esc_html( $message ), esc_html__( 'Lead Intelligence Access Restricted', 'calico-lead-intelligence' ), [ 'response' => 403 ] );
    }

    private function require_plugin_management_access( $message = '' ) {
        if ( $this->current_user_can_manage_plugin() ) return;
        if ( ! $message ) $message = __( 'Contact the Lead Intelligence Access Owner for full access.', 'calico-lead-intelligence' );
        wp_die( esc_html( $message ), esc_html__( 'Lead Intelligence Access Restricted', 'calico-lead-intelligence' ), [ 'response' => 403 ] );
    }

    private function render_owner_contact_page( $title ) {
        $owner = $this->valid_access_owner( $this->access_owner_id() );
        $this->page_header( $title, 'This area is reserved for the Lead Intelligence Access Owner.' );
        echo '<div class="cli-card cli-access-restricted"><h2>Contact the plugin owner for full access</h2><p>Your administrator account is in Client Mode. You can continue using the reporting areas, but this section contains protected configuration and delivery controls.</p>';
        if ( $owner ) {
            echo '<p><strong>Access Owner:</strong> ' . esc_html( $owner->display_name ) . '</p>';
            if ( ! empty( $owner->user_email ) ) echo '<p><a class="button button-primary" href="mailto:' . esc_attr( $owner->user_email ) . '">Contact ' . esc_html( $owner->display_name ) . '</a></p>';
        } else {
            echo '<p>Please contact the administrator who installed or manages Lead Intelligence.</p>';
        }
        echo '</div>';
        $this->page_footer();
    }

    private function valid_access_owner( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return null;
        $user = get_userdata( $user_id );
        return $user && user_can( $user, 'manage_options' ) ? $user : null;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'admin_init', [ $this, 'maybe_migrate_legacy_reports' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );
        add_action( 'wp_ajax_cli_diagnostics', [ $this, 'ajax_diagnostics' ] );
        add_action( 'wp_ajax_nopriv_cli_diagnostics', [ $this, 'ajax_diagnostics' ] );
        add_action( 'wp_ajax_cli_rule_test', [ $this, 'ajax_rule_test' ] );
        add_action( 'wp_ajax_cli_audit_leads', [ $this, 'ajax_audit_leads' ] );
        add_action( 'wp_ajax_cli_regression_tests', [ $this, 'ajax_regression_tests' ] );
        add_action( 'wp_ajax_cli_clean_unknowns', [ $this, 'ajax_clean_unknowns' ] );
        add_action( 'wp_ajax_cli_restore_cleaned', [ $this, 'ajax_restore_cleaned' ] );
        add_action( 'wp_ajax_cli_preview_reclassification', [ $this, 'ajax_preview_reclassification' ] );
        add_action( 'wp_ajax_cli_apply_reclassification', [ $this, 'ajax_apply_reclassification' ] );
        add_action( 'admin_post_cli_save_scheduled_report', [ $this, 'save_scheduled_report' ] );
        add_action( 'admin_post_cli_send_test_report', [ $this, 'send_test_report' ] );
        add_action( 'admin_post_cli_download_report', [ $this, 'download_report' ] );
        add_action( 'admin_post_cli_export_leads', [ $this, 'export_leads_csv' ] );
        add_action( 'admin_post_cli_export_backup', [ $this, 'export_backup' ] );
        add_action( 'admin_post_cli_import_backup', [ $this, 'import_backup' ] );
        add_action( 'cli_scheduled_report_event', [ $this, 'run_scheduled_report' ] );
    }

    public function plugins_loaded() {
        if ( class_exists( 'GFForms' ) ) {
            add_filter( 'gform_entry_meta', [ $this, 'entry_meta' ], 10, 2 );
            add_action( 'gform_after_submission', [ $this, 'save_submission' ], 10, 2 );
            add_filter( 'gform_entry_detail_meta_boxes', [ $this, 'entry_meta_box' ], 10, 3 );
            add_filter( 'gform_entry_list_columns', [ $this, 'entry_list_columns' ], 10, 2 );
            add_filter( 'gform_entries_column_filter', [ $this, 'entry_list_value' ], 10, 5 );
            add_filter( 'gform_notification', [ $this, 'append_attribution_to_notification' ], 20, 3 );
            add_filter( 'gform_notification_settings_fields', [ $this, 'notification_settings_fields' ], 20, 3 );
            add_filter( 'gform_form_settings_fields', [ $this, 'form_settings_fields' ], 20, 2 );
            add_action( 'gform_post_update_entry', [ $this, 'invalidate_reporting_cache' ], 10, 2 );
            add_action( 'gform_after_update_entry', [ $this, 'invalidate_reporting_cache' ], 10, 2 );
            add_action( 'gform_update_status', [ $this, 'invalidate_reporting_cache' ], 10, 3 );
            add_action( 'gform_delete_entry', [ $this, 'invalidate_reporting_cache' ], 10, 1 );
            add_action( 'gform_delete_entries', [ $this, 'invalidate_reporting_cache' ], 10, 2 );
            add_action( 'gform_post_add_entry', [ $this, 'invalidate_reporting_cache' ], 10, 2 );
            add_action( 'gform_after_save_form', [ $this, 'invalidate_reporting_cache' ], 10, 2 );
        }
    }

    public function admin_menu() {
        if ( ! $this->current_user_can_access_plugin() ) return;
        add_menu_page(
            'Lead Intelligence',
            'Lead Intelligence',
            'manage_options',
            'calico-lead-intelligence',
            [ $this, 'render_dashboard' ],
            'dashicons-chart-area',
            58
        );

        add_submenu_page( 'calico-lead-intelligence', 'Dashboard', 'Dashboard', 'manage_options', 'calico-lead-intelligence', [ $this, 'render_dashboard' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Leads', 'Leads', 'manage_options', 'calico-lead-intelligence-leads', [ $this, 'render_leads' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Sources', 'Sources', 'manage_options', 'calico-lead-intelligence-sources', [ $this, 'render_sources' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Campaigns', 'Campaigns', 'manage_options', 'calico-lead-intelligence-campaigns', [ $this, 'render_campaigns' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Journey Explorer', 'Journey Explorer', 'manage_options', 'calico-lead-intelligence-journeys', [ $this, 'render_journeys' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Rules', 'Rules', 'manage_options', 'calico-lead-intelligence-rules', [ $this, 'render_rules' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Diagnostics', 'Diagnostics', 'manage_options', 'calico-lead-intelligence-diagnostics', [ $this, 'render_diagnostics' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Scheduled Reports', 'Scheduled Reports <span class="awaiting-mod count-1"><span class="pending-count">PRO</span></span>', 'manage_options', 'calico-lead-intelligence-reports', [ $this, 'render_scheduled_reports' ] );
        add_submenu_page( 'calico-lead-intelligence', 'Settings', 'Settings', 'manage_options', 'calico-lead-intelligence-settings', [ $this, 'render_settings' ] );
    }

    public function admin_assets( $hook ) {
        if ( false === strpos( $hook, 'calico-lead-intelligence' ) && false === strpos( $hook, 'gf_entries' ) ) return;
        if ( ! $this->current_user_can_access_plugin() ) return;
        if ( false !== strpos( $hook, 'calico-lead-intelligence-reports' ) ) wp_enqueue_media();

        wp_enqueue_style( 'cli-admin', $this->runtime_asset_url( 'admin.css', 'assets/css/admin.css' ), [], CLI_VERSION );
        wp_enqueue_script( 'cli-admin', $this->runtime_asset_url( 'admin.js', 'assets/js/admin.js' ), [ 'jquery' ], CLI_VERSION, true );
        wp_localize_script( 'cli-admin', 'CLI_ADMIN', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cli_diagnostics' ),
        ] );
    }

    public function frontend_assets() {
        if ( is_admin() ) return;
        wp_enqueue_script( 'cli-tracker', $this->runtime_asset_url( 'tracker.js', 'assets/js/tracker.js' ), [], CLI_VERSION, true );
        wp_localize_script( 'cli-tracker', 'CLI_TRACKER', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'cli_public' ),
            'retentionDays'  => 90,
            'sessionMinutes' => 30,
            'payloadField'   => 'cli_attribution_payload',
            'allowedQueryKeys' => $this->attribution_query_keys(),
            'maxUrlLength'   => 2048,
            'maxValueLength' => 255,
        ] );
    }

    private function runtime_asset_url( $root_file, $legacy_file ) {
        $root_file = ltrim( (string) $root_file, '/\\' );
        $legacy_file = ltrim( (string) $legacy_file, '/\\' );
        return is_file( dirname( CLI_FILE ) . '/' . $root_file ) ? CLI_URL . $root_file : CLI_URL . $legacy_file;
    }

    public function entry_meta( $entry_meta, $form_id ) {
        $fields = [
            'cli_lead_source' => 'Lead Source',
            'cli_channel' => 'Lead Channel',
            'cli_legacy_source' => 'Legacy Attribution Label',
            'cli_first_source' => 'First Touch Source',
            'cli_first_channel' => 'First Touch Channel',
            'cli_last_source' => 'Last Touch Source',
            'cli_last_channel' => 'Last Touch Channel',
            'cli_campaign' => 'Campaign Name',
            'cli_campaign_label' => 'Campaign',
            'cli_campaign_id' => 'Campaign ID',
            'cli_campaign_source' => 'Campaign Source',
            'cli_campaign_medium' => 'Campaign Medium',
            'cli_campaign_content' => 'Campaign Content / Ad',
            'cli_campaign_term' => 'Campaign Term / Keyword',
            'cli_campaign_origin' => 'Campaign Evidence Origin',
            'cli_medium' => 'Medium',
            'cli_referrer' => 'Referrer',
            'cli_landing_page' => 'Landing Page',
            'cli_conversion_page' => 'Conversion Page',
            'cli_pages_viewed' => 'Pages Viewed',
            'cli_unique_pages_viewed' => 'Unique Pages Viewed',
            'cli_customer_journey' => 'Customer Journey',
            'cli_time_on_site' => 'Time on Site',
            'cli_click_id' => 'Advertising Click ID',
            'cli_attribution_status' => 'Attribution Status',
            'cli_attribution_rule' => 'Attribution Rule',
            'cli_attribution_evidence' => 'Attribution Evidence',
            'cli_customer_evidence' => 'Customer Evidence',
            'cli_external_referrer' => 'External Referrer',
            'cli_previous_page' => 'Previous Page',
            'cli_lead_state' => 'Lead State',
            'cli_capture_mode' => 'Capture Mode',
            'cli_reclassification_history' => 'Reclassification History',
            'cli_reclassified_at' => 'Reclassified At',
            'cli_reclassified_catalog' => 'Reclassified Catalog Version',
        ];

        foreach ( $fields as $key => $label ) {
            $entry_meta[ $key ] = [
                'label' => $label,
                'is_numeric' => in_array( $key, [ 'cli_pages_viewed', 'cli_unique_pages_viewed', 'cli_time_on_site' ], true ),
                'is_default_column' => 'cli_lead_source' === $key,
            ];
        }
        return $entry_meta;
    }

    public function save_submission( $entry, $form ) {
        if ( ! $this->is_form_tracking_enabled( $form ) ) {
            $this->invalidate_reporting_cache();
            return;
        }

        $data = $this->get_request_attribution();
        $capture_mode = $data ? 'Browser Tracking' : 'Server Fallback';
        if ( ! $data ) $data = $this->build_server_fallback( $entry );
        $data = $this->enrich_attribution_from_entry_fields( $data, $entry, $form );

        $first   = isset( $data['first_touch'] ) && is_array( $data['first_touch'] ) ? $data['first_touch'] : [];
        $last    = isset( $data['last_touch'] ) && is_array( $data['last_touch'] ) ? $data['last_touch'] : [];
        $session = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : [];

        // v3 uses one server-side rules engine for entries, emails, dashboards,
        // diagnostics, and future integrations. Browser classifications are
        // intentionally ignored so every surface always agrees.
        $first_result = $this->classify_touch( $first );
        $last_result  = $this->classify_touch( $last, $first );
        $first = array_merge( $first, $first_result );
        $last  = array_merge( $last, $last_result );
        $data['first_touch'] = $first;
        $data['last_touch']  = $last;
        $entry_id = (int) rgar( $entry, 'id' );

        $click_id = $this->first_value( [
            $last['gclid'] ?? '', $last['gbraid'] ?? '', $last['wbraid'] ?? '', $last['fbclid'] ?? '',
            $last['msclkid'] ?? '', $last['ttclid'] ?? '', $last['li_fat_id'] ?? '',
            $first['gclid'] ?? '', $first['fbclid'] ?? ''
        ] );

        $start = isset( $session['session_started'] ) ? (int) $session['session_started'] : 0;
        $lead_state = $this->extract_lead_state( $entry, $form );
        $visited_urls = isset( $session['visited_urls'] ) && is_array( $session['visited_urls'] ) ? $session['visited_urls'] : [];
        $unique_pages = $this->unique_page_count( $visited_urls );
        $journey = $this->build_journey( $visited_urls );
        $primary = $this->last_non_direct_attribution( $first, $last );
        $primary_touch = 'first' === $primary['touch'] ? $first : $last;
        $lead_source = $primary['source'];
        $lead_channel = $primary['channel'];
        $legacy_source = $primary_touch['legacy_source'] ?? $lead_source;
        $campaign = $this->campaign_attribution_from_touches( $first, $last, $legacy_source );
        $meta = [
            'cli_lead_source' => $lead_source,
            'cli_channel' => $lead_channel,
            'cli_legacy_source' => $legacy_source,
            'cli_first_source' => $first['source'] ?? 'Direct',
            'cli_first_channel' => $first['channel'] ?? 'Unknown',
            'cli_last_source' => $last['source'] ?? 'Direct',
            'cli_last_channel' => $last['channel'] ?? 'Unknown',
            'cli_campaign' => $campaign['name'],
            'cli_campaign_label' => $campaign['label'],
            'cli_campaign_id' => $campaign['id'],
            'cli_campaign_source' => $campaign['source'],
            'cli_campaign_medium' => $campaign['medium'],
            'cli_campaign_content' => $campaign['content'],
            'cli_campaign_term' => $campaign['term'],
            'cli_campaign_origin' => $campaign['origin'],
            'cli_medium' => $campaign['medium'],
            'cli_referrer' => $this->external_referrer_for_display( $last, $first ),
            'cli_external_referrer' => $this->external_referrer_for_display( $last, $first ),
            'cli_landing_page' => $first['landing_url'] ?? '',
            'cli_conversion_page' => $session['current_url'] ?? '',
            'cli_previous_page' => $session['previous_url'] ?? '',
            'cli_pages_viewed' => max( 1, (int) ( $session['pages_viewed'] ?? 1 ) ),
            'cli_unique_pages_viewed' => max( 1, $unique_pages ),
            'cli_customer_journey' => $journey,
            'cli_time_on_site' => $start ? max( 0, time() - $start ) : 0,
            'cli_click_id' => $click_id,
            'cli_attribution_status' => $primary['confidence'],
            'cli_attribution_rule' => $primary_touch['rule'] ?? '',
            'cli_attribution_evidence' => wp_json_encode( $primary_touch['evidence'] ?? [] ),
            'cli_customer_evidence' => $primary_touch['customer_evidence'] ?? $this->customer_evidence_message( $lead_source, $lead_channel, $primary['confidence'] ),
            'cli_lead_state' => $lead_state,
            'cli_device' => $session['device'] ?? '',
            'cli_browser' => $session['browser'] ?? '',
            'cli_payload' => wp_json_encode( $data ),
            'cli_capture_mode' => $capture_mode,
        ];

        foreach ( $meta as $key => $value ) gform_update_meta( $entry_id, $key, $value );
        $this->invalidate_reporting_cache();
    }

    /**
     * Adds a sales-friendly attribution block to internal Gravity Forms
     * notifications only. Customer/autoresponder notifications are excluded
     * when their recipients resolve to an email submitted in the form.
     */
    public function append_attribution_to_notification( $notification, $form, $entry ) {
        if ( ! $this->is_form_tracking_enabled( $form ) ) return $notification;
        if ( ! $this->is_form_email_attribution_enabled( $form ) ) return $notification;

        // Safety rule: notifications addressed to a submitted form email field
        // are customer/autoresponder messages and must never receive internal
        // attribution data. This runs before the per-notification preference so
        // Gravity Forms' Test Send feature cannot bypass the protection.
        if ( $this->is_customer_notification( $notification, $form, $entry ) ) return $notification;

        if ( ! $this->is_notification_attribution_enabled( $notification ) ) return $notification;
        if ( ! apply_filters( 'cli_include_attribution_in_notification', true, $notification, $form, $entry ) ) return $notification;

        $data = $this->get_request_attribution();
        $capture_mode = $data ? 'Browser Tracking' : '';
        if ( ! $data ) {
            $entry_id = (int) rgar( $entry, 'id' );
            $stored = $entry_id ? gform_get_meta( $entry_id, 'cli_payload' ) : '';
            $data = $stored ? json_decode( $stored, true ) : [];
            if ( $data ) $capture_mode = gform_get_meta( $entry_id, 'cli_capture_mode' ) ?: 'Saved Entry Data';
        }
        if ( ! is_array( $data ) || ! $data ) {
            $data = $this->build_server_fallback( $entry );
            $capture_mode = 'Server Fallback';
        }
        $data = $this->enrich_attribution_from_entry_fields( $data, $entry, $form );

        $first   = isset( $data['first_touch'] ) && is_array( $data['first_touch'] ) ? $data['first_touch'] : [];
        $last    = isset( $data['last_touch'] ) && is_array( $data['last_touch'] ) ? $data['last_touch'] : [];
        $session = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : [];
        $first = array_merge( $first, $this->classify_touch( $first ) );
        $last  = array_merge( $last, $this->classify_touch( $last, $first ) );

        $primary = $this->last_non_direct_attribution( $first, $last );
        $primary_touch = 'first' === $primary['touch'] ? $first : $last;
        $detected_source = $primary['source'];
        $detected_channel = $primary['channel'];
        $legacy_source = $primary_touch['legacy_source'] ?? $detected_source;
        $status = $primary['confidence'];
        $lead_state = $this->extract_lead_state( $entry, $form );
        $campaign = $this->campaign_attribution_from_touches( $first, $last, $legacy_source );
        $campaign_notification_label = $this->campaign_notification_label( $campaign );
        $evidence_text = $primary_touch['customer_evidence'] ?? $this->customer_evidence_message( $detected_source, $detected_channel, $status );

        // Internal notification emails are a reporting surface. Apply the
        // client-specific display mapping without changing captured evidence
        // or any Gravity Forms entry metadata.
        $reported = $this->reporting_source_attribution( $detected_source, $detected_channel, $status );
        $source = $reported['source'];
        $channel = $reported['channel'];
        $source_heading = $this->notification_source_heading( $source, $channel );
        $first_reported = $this->reporting_source_attribution(
            $first['source'] ?? $detected_source,
            $first['channel'] ?? $detected_channel,
            $first['status'] ?? $status
        );
        $last_reported = $this->reporting_source_attribution(
            $last['source'] ?? $detected_source,
            $last['channel'] ?? $detected_channel,
            $last['status'] ?? $status
        );
        $rows = [
            'Channel' => $channel,
            'Confidence' => $status,
            'State' => $lead_state,
            'Capture Mode' => $capture_mode,
            'Evidence' => $evidence_text,
            'Campaign' => $campaign_notification_label,
            'Campaign Name' => $campaign['name'],
            'Campaign ID' => $campaign['id'],
            'Campaign Source' => $campaign['source'],
            'Campaign Medium' => $campaign['medium'],
            'Campaign Content / Ad' => $campaign['content'],
            'Campaign Term / Keyword' => $campaign['term'],
            'Time on Site' => $this->format_time( $this->session_duration( $session ) ),
            'Unique Pages Viewed' => $this->unique_page_count( $session['visited_urls'] ?? [] ),
            'Customer Journey' => $this->build_journey( $session['visited_urls'] ?? [] ),
            'External Referrer' => $this->external_referrer_for_display( $last, $first ),
            'First Touch Source' => $first_reported['source'],
            'Last Touch Source' => $last_reported['source'],
            'Landing Page' => $first['landing_url'] ?? '',
            'Previous Page' => $session['previous_url'] ?? '',
            'Conversion Page' => $session['current_url'] ?? '',
        ];

        $format = isset( $notification['message_format'] ) ? strtolower( (string) $notification['message_format'] ) : 'html';
        $message = isset( $notification['message'] ) ? (string) $notification['message'] : '';

        if ( 'text' === $format || 'plain' === $format ) {
            $block = "

LEAD SOURCE
" . str_repeat( '=', 32 ) . "
";
            $block .= $source_heading . "
";
            $block .= 'Channel: ' . $channel . "
";
            if ( $status ) $block .= 'Confidence: ' . $status . "
";
            $block .= str_repeat( '-', 32 ) . "
";
            foreach ( $rows as $label => $value ) {
                if ( 'Confidence' === $label || '' === trim( (string) $value ) ) continue;
                $block .= $label . ': ' . $value . "
";
            }
        } else {
            $block = '<div style="margin-top:24px;font-family:Arial,sans-serif;border:1px solid #d9e2e8;border-radius:8px;overflow:hidden;">';
            $block .= '<div style="padding:10px 14px;background:#eaf2f8;font-size:13px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;">Lead Source</div>';
            $block .= '<div style="padding:18px 14px;background:#f8fbfd;">';
            $block .= '<div style="font-size:24px;line-height:1.25;font-weight:700;color:#162b3a;">' . esc_html( $source_heading ) . '</div>';
            $block .= '<div style="margin-top:6px;color:#526675;font-size:13px;"><strong>Channel:</strong> ' . esc_html( $channel ) . ' &nbsp; <strong>Confidence:</strong> ' . esc_html( $status ) . '</div>';
            $block .= '</div><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:14px;">';
            foreach ( $rows as $label => $value ) {
                if ( 'Confidence' === $label || '' === trim( (string) $value ) ) continue;
                $display = esc_html( $value );
                if ( in_array( $label, [ 'External Referrer', 'Landing Page', 'Previous Page', 'Conversion Page' ], true ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $display = '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
                }
                $block .= '<tr><td style="width:190px;padding:8px 10px;border-top:1px solid #e1e8ed;background:#f5f9fc;font-weight:bold;vertical-align:top;">' . esc_html( $label ) . '</td>';
                $block .= '<td style="padding:8px 10px;border-top:1px solid #e1e8ed;vertical-align:top;word-break:break-word;">' . $display . '</td></tr>';
            }
            $block .= '</table></div>';
        }
        $notification['message'] = $message . $block;
        return $notification;
    }

    private function notification_source_heading( $source, $channel ) {
        $source = trim( sanitize_text_field( (string) $source ) );
        $channel = trim( sanitize_text_field( (string) $channel ) );
        if ( 'Paid' === $channel && $source && ! preg_match( '/\bAds?$/i', $source ) ) return $source . ' Ads';
        return $source ?: 'Unknown';
    }

    private function campaign_notification_label( $campaign ) {
        $campaign = is_array( $campaign ) ? $campaign : [];
        $campaign_id = trim( sanitize_text_field( (string) ( $campaign['id'] ?? '' ) ) );
        if ( $campaign_id ) {
            $mappings = $this->get_google_campaign_mappings();
            if ( isset( $mappings[ $campaign_id ] ) ) {
                $mapped_name = trim( sanitize_text_field( (string) $mappings[ $campaign_id ] ) );
                if ( $mapped_name ) return $mapped_name;
            }
        }
        return trim( sanitize_text_field( (string) ( $campaign['label'] ?? '' ) ) );
    }

    /**
     * Adds an explicit switch to every Gravity Forms notification screen.
     * Gravity Forms saves custom settings added through this filter directly
     * into the notification object.
     */
    public function notification_settings_fields( $fields, $notification, $form ) {
        if ( ! $this->current_user_can_manage_plugin() ) return $fields;
        $enabled_by_default = $this->notification_default_enabled( $notification );
        $current = $enabled_by_default ? 'yes' : 'no';

        if ( is_array( $notification ) && isset( $notification['cli_attribution_email_mode'] ) ) {
            $current = 'yes' === strtolower( (string) $notification['cli_attribution_email_mode'] ) ? 'yes' : 'no';
        } elseif ( is_array( $notification ) && array_key_exists( 'cli_include_attribution', $notification ) ) {
            // Migrate the v2.2.2 checkbox value when the notification is opened.
            $current = $this->setting_is_enabled( $notification['cli_include_attribution'] ) ? 'yes' : 'no';
        }

        $fields[] = [
            'title'  => esc_html__( 'Lead Intelligence', 'calico-lead-intelligence' ),
            'fields' => [
                [
                    'name'          => 'cli_attribution_email_mode',
                    'label'         => esc_html__( 'Include Lead Attribution', 'calico-lead-intelligence' ),
                    'type'          => 'radio',
                    'default_value' => $current,
                    'choices'       => [
                        [
                            'label' => esc_html__( 'Yes — include attribution in this notification', 'calico-lead-intelligence' ),
                            'value' => 'yes',
                        ],
                        [
                            'label' => esc_html__( 'No — do not include attribution in this notification', 'calico-lead-intelligence' ),
                            'value' => 'no',
                        ],
                    ],
                    'tooltip'       => esc_html__( 'Customer notifications sent to a submitted email field are always protected and will never include attribution, even if Yes is selected.', 'calico-lead-intelligence' ),
                    'description'   => esc_html__( 'Use Yes for sales, office, or marketing emails. Use No for any other notification.', 'calico-lead-intelligence' ),
                ],
            ],
        ];

        return $fields;
    }

    private function notification_default_enabled( $notification ) {
        if ( is_array( $notification ) && isset( $notification['cli_attribution_email_mode'] ) ) {
            return 'yes' === strtolower( trim( (string) $notification['cli_attribution_email_mode'] ) );
        }

        if ( is_array( $notification ) && array_key_exists( 'cli_include_attribution', $notification ) ) {
            return $this->setting_is_enabled( $notification['cli_include_attribution'] );
        }

        // Notifications sent to a form field are customer-facing by default.
        $to_type = is_array( $notification ) ? strtolower( (string) rgar( $notification, 'toType' ) ) : '';
        return 'field' !== $to_type;
    }

    private function is_notification_attribution_enabled( $notification ) {
        if ( ! is_array( $notification ) ) return true;

        if ( isset( $notification['cli_attribution_email_mode'] ) ) {
            return 'yes' === strtolower( trim( (string) $notification['cli_attribution_email_mode'] ) );
        }

        if ( array_key_exists( 'cli_include_attribution', $notification ) ) {
            return $this->setting_is_enabled( $notification['cli_include_attribution'] );
        }

        return $this->notification_default_enabled( $notification );
    }

    private function setting_is_enabled( $value ) {
        if ( is_array( $value ) ) {
            $value = reset( $value );
        }

        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on', 'enabled' ], true );
    }

    /**
     * Adds per-form controls under Form Settings > Lead Intelligence.
     */
    public function form_settings_fields( $settings, $form ) {
        if ( ! $this->current_user_can_manage_plugin() ) return $settings;
        $settings['calico_lead_intelligence'] = [
            'title'  => esc_html__( 'Lead Intelligence', 'calico-lead-intelligence' ),
            'fields' => [
                [
                    'name'    => 'cli_tracking_enabled',
                    'label'   => esc_html__( 'Attribution Tracking', 'calico-lead-intelligence' ),
                    'type'    => 'checkbox',
                    'choices' => [
                        [
                            'name'          => 'cli_tracking_enabled',
                            'label'         => esc_html__( 'Track attribution and include this form in Lead Intelligence reports', 'calico-lead-intelligence' ),
                            'default_value' => '1',
                        ],
                    ],
                    'tooltip' => esc_html__( 'Disable this for forms that should not save source, campaign, journey, or geographic attribution.', 'calico-lead-intelligence' ),
                ],
                [
                    'name'    => 'cli_email_attribution_enabled',
                    'label'   => esc_html__( 'Internal Notification Emails', 'calico-lead-intelligence' ),
                    'type'    => 'checkbox',
                    'choices' => [
                        [
                            'name'          => 'cli_email_attribution_enabled',
                            'label'         => esc_html__( 'Add attribution to internal/admin notifications', 'calico-lead-intelligence' ),
                            'default_value' => '1',
                        ],
                    ],
                    'tooltip' => esc_html__( 'Customer and autoresponder notifications are always excluded automatically.', 'calico-lead-intelligence' ),
                ],
            ],
        ];
        return $settings;
    }

    private function is_form_tracking_enabled( $form ) {
        if ( ! is_array( $form ) ) return true;
        return ! isset( $form['cli_tracking_enabled'] ) || '0' !== (string) $form['cli_tracking_enabled'];
    }

    private function is_form_email_attribution_enabled( $form ) {
        if ( ! is_array( $form ) ) return true;
        return ! isset( $form['cli_email_attribution_enabled'] ) || '0' !== (string) $form['cli_email_attribution_enabled'];
    }

    /**
     * Treat a notification as customer-facing when Gravity Forms targets an
     * email field, or when any rendered recipient matches an email submitted
     * through the current entry. This covers custom To/CC/BCC configurations
     * that use merge tags but are not marked with toType=field.
     */
    private function is_customer_notification( $notification, $form, $entry ) {
        if ( isset( $notification['toType'] ) && 'field' === strtolower( (string) $notification['toType'] ) ) return true;

        $submitted_emails = [];
        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( ! is_object( $field ) || 'email' !== (string) $field->type ) continue;
                $value = sanitize_email( (string) rgar( $entry, (string) $field->id ) );
                if ( $value ) $submitted_emails[] = strtolower( $value );
            }
        }
        $submitted_emails = array_values( array_unique( $submitted_emails ) );
        if ( ! $submitted_emails ) return false;

        foreach ( [ 'to', 'cc', 'bcc' ] as $recipient_key ) {
            $raw = isset( $notification[ $recipient_key ] ) ? (string) $notification[ $recipient_key ] : '';
            if ( '' === trim( $raw ) ) continue;

            $rendered = class_exists( 'GFCommon' )
                ? GFCommon::replace_variables( $raw, $form, $entry, false, false, false, 'text' )
                : $raw;

            $recipients = preg_split( '/[;,\s]+/', strtolower( wp_strip_all_tags( $rendered ) ) );
            foreach ( $recipients as $recipient ) {
                $recipient = trim( $recipient, "<>\"'()[] " );
                if ( $recipient && in_array( $recipient, $submitted_emails, true ) ) return true;
            }

            foreach ( $submitted_emails as $email ) {
                if ( false !== strpos( strtolower( $rendered ), $email ) ) return true;
            }
        }

        return false;
    }


    /**
     * Creates a minimal attribution record when browser-side tracking data is
     * unavailable (privacy tools, JavaScript failures, cached markup, or an
     * unusual Gravity Forms submission path). This guarantees that internal
     * notifications and entries still receive one consistent source result.
     */
    private function build_server_fallback( $entry ) {
        $source_url = '';
        if ( is_array( $entry ) ) {
            $source_url = (string) rgar( $entry, 'source_url' );
        }
        if ( ! $source_url && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $source_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        }
        if ( ! $source_url ) $source_url = home_url( '/' );
        $source_url = $this->bounded_evidence_url( $source_url ) ?: home_url( '/' );

        $now = time();
        return [
            'first_touch' => [
                'landing_url' => $source_url,
                'external_referrer' => '',
                'timestamp' => $now,
            ],
            'last_touch' => [
                'landing_url' => $source_url,
                'external_referrer' => '',
                'timestamp' => $now,
            ],
            'session' => [
                'session_started' => $now,
                'pages_viewed' => 1,
                'unique_pages_viewed' => 1,
                'visited_urls' => [ $source_url ],
                'previous_url' => '',
                'current_url' => $source_url,
                'device' => '',
                'browser' => '',
            ],
        ];
    }

    /**
     * Browser tracking remains authoritative. Older form fields are used only
     * to fill evidence the browser could not retain, such as a platform
     * referrer removed during a redirect. Gravity Forms values are read-only.
     */
    private function enrich_attribution_from_entry_fields( $data, $entry, $form ) {
        $data = is_array( $data ) ? $data : [];
        foreach ( [ 'first_touch', 'last_touch' ] as $touch_key ) {
            if ( ! empty( $data[ $touch_key ] ) && is_array( $data[ $touch_key ] ) ) $data[ $touch_key ] = $this->landing_authoritative_touch( $data[ $touch_key ] );
        }
        $field_evidence = $this->recover_entry_field_evidence( $entry, $form );
        $landing = $field_evidence['landing'] ?? '';
        if ( ! $landing && is_array( $entry ) ) $landing = $this->bounded_evidence_url( rgar( $entry, 'source_url' ) );
        $referrer = $field_evidence['referrer'] ?? '';
        $parameters = $field_evidence['parameters'] ?? '';
        if ( $referrer && $this->is_internal_site_url( $referrer ) ) {
            // Some Gravity Forms integrations save the original tagged landing
            // URL in a field named Referrer. Keep that URL as classification
            // evidence when it contains an attribution parameter, even though
            // its host is internal. Ordinary internal navigation is discarded.
            $query = $this->extract_url_query( $referrer );
            $has_attribution = false;
            foreach ( $this->attribution_query_keys() as $key ) {
                if ( isset( $query[ $key ] ) && '' !== trim( (string) $query[ $key ] ) ) {
                    $has_attribution = true;
                    break;
                }
            }
            if ( ! $has_attribution ) $referrer = '';
        }
        if ( ! $landing && ! $referrer && ! $parameters ) return $data;

        if ( empty( $data['first_touch'] ) || ! is_array( $data['first_touch'] ) ) $data['first_touch'] = [];
        if ( $landing && empty( $data['first_touch']['landing_url'] ) ) $data['first_touch']['landing_url'] = $landing;
        if ( $referrer && empty( $data['first_touch']['external_referrer'] ) ) $data['first_touch']['external_referrer'] = $referrer;
        $data['first_touch'] = $this->landing_authoritative_touch( $data['first_touch'] );
        // Referral Parameters are independent evidence, not a fallback for the
        // Referrer field. Preserve the real referrer for display while filling
        // missing click IDs and UTMs from the saved parameter bundle. Explicit
        // landing-page values remain authoritative when the same key conflicts.
        foreach ( $this->extract_url_query_values( $parameters ) as $key => $value ) {
            if ( in_array( $key, $this->attribution_query_keys(), true ) && empty( $data['first_touch'][ $key ] ) ) {
                $value = $this->privacy_safe_attribution_value( $value );
                if ( '' !== $value ) $data['first_touch'][ $key ] = $value;
            }
        }
        return $data;
    }

    private function get_request_attribution() {
        $raw = isset( $_POST['cli_attribution_payload'] ) ? wp_unslash( $_POST['cli_attribution_payload'] ) : '';
        if ( ! $raw || strlen( $raw ) > 131072 ) return [];

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return [];

        return $this->sanitize_browser_attribution_payload( $data );
    }

    /**
     * Accept only bounded raw browser evidence and session telemetry. Browser
     * supplied classifications are intentionally discarded; Source, Channel,
     * Confidence, matched rule, and evidence language are always calculated by
     * the server-side attribution engine.
     */
    private function sanitize_browser_attribution_payload( $data ) {
        $clean = [];
        foreach ( [ 'first_touch', 'last_touch' ] as $touch_key ) {
            if ( ! empty( $data[ $touch_key ] ) && is_array( $data[ $touch_key ] ) ) {
                $clean[ $touch_key ] = $this->sanitize_browser_touch( $data[ $touch_key ] );
            }
        }

        if ( ! empty( $data['session'] ) && is_array( $data['session'] ) ) {
            $session = $data['session'];
            $urls = [];
            if ( ! empty( $session['visited_urls'] ) && is_array( $session['visited_urls'] ) ) {
                foreach ( array_slice( $session['visited_urls'], -50 ) as $url ) {
                    $url = $this->bounded_evidence_url( $url );
                    if ( $url ) $urls[] = $url;
                }
            }
            $clean['session'] = [
                'session_started' => max( 0, (int) ( $session['session_started'] ?? 0 ) ),
                'last_activity' => max( 0, (int) ( $session['last_activity'] ?? 0 ) ),
                'pages_viewed' => min( 50, max( 0, (int) ( $session['pages_viewed'] ?? count( $urls ) ) ) ),
                'unique_pages_viewed' => min( 50, max( 0, (int) ( $session['unique_pages_viewed'] ?? 0 ) ) ),
                'visited_urls' => $urls,
                'previous_url' => $this->bounded_evidence_url( $session['previous_url'] ?? '' ),
                'current_url' => $this->bounded_evidence_url( $session['current_url'] ?? '' ),
                'device' => substr( sanitize_text_field( (string) ( $session['device'] ?? '' ) ), 0, 32 ),
                'browser' => substr( sanitize_text_field( (string) ( $session['browser'] ?? '' ) ), 0, 32 ),
            ];
        }

        return $clean;
    }

    private function sanitize_browser_touch( $touch ) {
        $clean = [];
        foreach ( $this->attribution_query_keys() as $key ) {
            if ( ! isset( $touch[ $key ] ) || is_array( $touch[ $key ] ) || is_object( $touch[ $key ] ) ) continue;
            $value = $this->privacy_safe_attribution_value( $touch[ $key ] );
            if ( '' !== $value ) $clean[ $key ] = $value;
        }
        $clean['landing_url'] = $this->bounded_evidence_url( $touch['landing_url'] ?? '' );
        $external_referrer = $this->bounded_evidence_url( $touch['external_referrer'] ?? '' );
        $clean['external_referrer'] = $this->is_internal_site_url( $external_referrer ) ? '' : $external_referrer;
        $clean['timestamp'] = max( 0, (int) ( $touch['timestamp'] ?? 0 ) );
        return $this->landing_authoritative_touch( $clean );
    }

    private function landing_authoritative_touch( $touch ) {
        $touch = is_array( $touch ) ? $touch : [];
        $landing = $this->bounded_evidence_url( $touch['landing_url'] ?? '' );
        if ( ! $landing ) return $touch;
        $landing_values = $this->extract_url_query_values( $landing );
        $authoritative = [];
        foreach ( $this->attribution_query_keys() as $key ) {
            if ( isset( $landing_values[ $key ] ) && '' !== trim( (string) $landing_values[ $key ] ) ) {
                $value = $this->privacy_safe_attribution_value( $landing_values[ $key ] );
                if ( '' !== $value ) $authoritative[ $key ] = $value;
            }
        }
        if ( ! $authoritative ) return $touch;
        foreach ( $this->attribution_query_keys() as $key ) unset( $touch[ $key ] );
        return array_merge( $touch, $authoritative );
    }

    private function bounded_evidence_url( $url ) {
        if ( is_array( $url ) || is_object( $url ) ) return '';
        return $this->privacy_safe_url( $url );
    }

    private function is_internal_site_url( $url ) {
        $url_host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        $url_host = preg_replace( '/^www\./', '', $url_host );
        $site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $site_host = preg_replace( '/^www\./', '', $site_host );
        return $url_host && $site_host && (
            $url_host === $site_host ||
            ( strlen( $url_host ) > strlen( $site_host ) && substr( $url_host, -strlen( '.' . $site_host ) ) === '.' . $site_host )
        );
    }

    private function external_referrer_for_display( $last, $first ) {
        foreach ( [ $last, $first ] as $touch ) {
            $referrer = $this->bounded_evidence_url( is_array( $touch ) ? ( $touch['external_referrer'] ?? '' ) : '' );
            if ( $referrer && ! $this->is_internal_site_url( $referrer ) ) return $referrer;
        }
        return '';
    }

    private function attribution_query_keys() {
        return [
            'utm_source', 'utm_medium', 'utm_campaign', 'campaign', 'campaign_name', 'utm_term', 'utm_content',
            'utm_id', 'campaign_id', 'campaignid', 'gad_campaignid', 'gclid', 'gbraid', 'wbraid', 'fbclid',
            'msclkid', 'ttclid', 'li_fat_id', 'epik', 'mc_cid', 'mc_eid',
        ];
    }

    private function privacy_safe_attribution_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) return '';
        $value = trim( sanitize_text_field( (string) $value ) );
        if ( '' === $value ) return '';
        $decoded = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        for ( $i = 0; $i < 2; $i++ ) {
            $next = rawurldecode( $decoded );
            if ( $next === $decoded ) break;
            $decoded = $next;
        }
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $decoded ) ) return '';
        if ( preg_match( '#(?:https?://|www\.)#i', $decoded ) ) return '';
        return substr( $value, 0, 255 );
    }

    /**
     * Rebuild a URL without credentials, fragments, or arbitrary query data.
     * Only bounded attribution parameters survive into browser or entry data.
     */
    private function privacy_safe_url( $url ) {
        if ( is_array( $url ) || is_object( $url ) ) return '';
        $url = substr( trim( (string) $url ), 0, 8192 );
        if ( '' === $url ) return '';
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) return '';

        $host = strtolower( preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) $parts['host'] ) );
        if ( '' === $host ) return '';
        $port = ! empty( $parts['port'] ) && (int) $parts['port'] > 0 && (int) $parts['port'] <= 65535 ? ':' . (int) $parts['port'] : '';
        $path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
        if ( '/' !== $path[0] ) $path = '/' . $path;

        $allowed = array_flip( $this->attribution_query_keys() );
        $query = [];
        $raw_query = [];
        if ( ! empty( $parts['query'] ) ) parse_str( html_entity_decode( $parts['query'], ENT_QUOTES, 'UTF-8' ), $raw_query );
        foreach ( $raw_query as $key => $value ) {
            $key = strtolower( sanitize_key( (string) $key ) );
            if ( ! isset( $allowed[ $key ] ) ) continue;
            $value = is_array( $value ) ? reset( $value ) : $value;
            $value = $this->privacy_safe_attribution_value( $value );
            if ( '' !== $value ) $query[ $key ] = $value;
        }

        $safe = $scheme . '://' . $host . $port . $path;
        if ( $query ) $safe .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        $safe = substr( $safe, 0, 2048 );
        return esc_url_raw( $safe, [ 'http', 'https' ] );
    }

    public function entry_meta_box( $boxes, $entry, $form ) {
        if ( ! $this->current_user_can_access_plugin() ) return $boxes;
        $boxes['calico_lead_intelligence'] = [
            'title' => 'Lead Intelligence',
            'callback' => [ $this, 'render_entry_box' ],
            'context' => 'normal',
        ];
        return $boxes;
    }

    public function render_entry_box( $args ) {
        if ( ! $this->current_user_can_access_plugin() ) return;
        $entry = $args['entry'] ?? [];
        $id = (int) rgar( $entry, 'id' );
        $attribution = $this->customer_attribution_for_entry( $id );
        $customer_evidence = gform_get_meta( $id, 'cli_customer_evidence' );
        if ( ! $customer_evidence ) $customer_evidence = $this->customer_evidence_message( $attribution['source'], $attribution['channel'], $attribution['confidence'] );
        $rows = [
            'Lead Source' => $attribution['source'],
            'Channel' => $attribution['channel'],
            'Confidence' => $attribution['confidence'],
            'Evidence' => $customer_evidence,
            'Lead State' => gform_get_meta( $id, 'cli_lead_state' ),
            'Capture Mode' => gform_get_meta( $id, 'cli_capture_mode' ),
            'First Touch' => gform_get_meta( $id, 'cli_first_source' ),
            'Last Touch' => gform_get_meta( $id, 'cli_last_source' ),
            'Campaign' => $this->campaign_display_label( $id ),
            'Campaign Name' => gform_get_meta( $id, 'cli_campaign' ),
            'Campaign ID' => gform_get_meta( $id, 'cli_campaign_id' ),
            'Campaign Source' => gform_get_meta( $id, 'cli_campaign_source' ),
            'Campaign Medium' => gform_get_meta( $id, 'cli_campaign_medium' ) ?: gform_get_meta( $id, 'cli_medium' ),
            'Campaign Content / Ad' => gform_get_meta( $id, 'cli_campaign_content' ),
            'Campaign Term / Keyword' => gform_get_meta( $id, 'cli_campaign_term' ),
            'Landing Page' => gform_get_meta( $id, 'cli_landing_page' ),
            'Conversion Page' => gform_get_meta( $id, 'cli_conversion_page' ),
            'External Referrer' => gform_get_meta( $id, 'cli_external_referrer' ),
            'Previous Page' => gform_get_meta( $id, 'cli_previous_page' ),
            'Pages Viewed' => gform_get_meta( $id, 'cli_pages_viewed' ),
            'Unique Pages Viewed' => gform_get_meta( $id, 'cli_unique_pages_viewed' ),
            'Customer Journey' => gform_get_meta( $id, 'cli_customer_journey' ),
            'Time on Site' => $this->format_time( (int) gform_get_meta( $id, 'cli_time_on_site' ) ),
            'Click ID' => gform_get_meta( $id, 'cli_click_id' ),
            'Device' => gform_get_meta( $id, 'cli_device' ),
            'Browser' => gform_get_meta( $id, 'cli_browser' ),
        ];

        echo '<div class="cli-entry-box">';
        echo '<div class="cli-badge">' . esc_html( $rows['Lead Source'] ?: 'Unknown' ) . '</div>';
        echo '<div class="cli-entry-grid">';
        foreach ( $rows as $label => $value ) {
            if ( '' === (string) $value ) continue;
            echo '<div><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $value ) . '</span></div>';
        }
        echo '</div></div>';
    }

    private function format_evidence_for_display( $raw ) {
        if ( is_array( $raw ) ) return implode( '; ', array_filter( array_map( 'sanitize_text_field', $raw ) ) );
        $decoded = json_decode( (string) $raw, true );
        if ( is_array( $decoded ) ) return implode( '; ', array_filter( array_map( 'sanitize_text_field', $decoded ) ) );
        return sanitize_text_field( (string) $raw );
    }

    public function entry_list_columns( $columns, $form_id ) {
        if ( ! $this->current_user_can_access_plugin() ) return $columns;
        $columns['cli_lead_source'] = 'Calico Source';
        return $columns;
    }

    public function entry_list_value( $value, $form_id, $field_id, $entry, $query_string ) {
        if ( ! $this->current_user_can_access_plugin() ) return $value;
        if ( 'cli_lead_source' !== (string) $field_id ) return $value;
        $attribution = $this->customer_attribution_for_entry( (int) rgar( $entry, 'id' ) );
        return '<span class="cli-badge cli-badge-small">' . esc_html( $attribution['source'] ) . '</span>';
    }

    public function render_dashboard() {
        $this->require_plugin_access();
        $user_id = get_current_user_id();
        $allowed_ranges = [ 'rolling_7', 'rolling_30', 'this_month', 'last_month', 'last_2_months', 'last_3_months', 'year_to_date', 'all_time', 'custom_months', 'custom_dates' ];
        $saved_range = (string) get_user_meta( $user_id, 'cli_dashboard_range_v2', true );
        if ( ! in_array( $saved_range, $allowed_ranges, true ) ) $saved_range = 'rolling_30';
        $range = isset( $_GET['cli_range'] ) ? sanitize_key( wp_unslash( $_GET['cli_range'] ) ) : $saved_range;
        if ( ! in_array( $range, $allowed_ranges, true ) ) $range = 'rolling_30';

        $saved_start_month = (string) get_user_meta( $user_id, 'cli_dashboard_start_month', true );
        $saved_end_month = (string) get_user_meta( $user_id, 'cli_dashboard_end_month', true );
        $default_month = wp_date( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) );
        $start_month = isset( $_GET['cli_start_month'] ) ? $this->sanitize_month_key( wp_unslash( $_GET['cli_start_month'] ) ) : $this->sanitize_month_key( $saved_start_month );
        $end_month = isset( $_GET['cli_end_month'] ) ? $this->sanitize_month_key( wp_unslash( $_GET['cli_end_month'] ) ) : $this->sanitize_month_key( $saved_end_month );
        if ( ! $start_month ) $start_month = $default_month;
        if ( ! $end_month ) $end_month = $start_month;
        if ( $start_month > $end_month ) { $swap = $start_month; $start_month = $end_month; $end_month = $swap; }

        $timezone = wp_timezone();
        $today = new DateTimeImmutable( 'today', $timezone );
        $default_end_date = $today->format( 'Y-m-d' );
        $default_start_date = $today->modify( '-29 days' )->format( 'Y-m-d' );
        $saved_start_date = $this->sanitize_reporting_date( get_user_meta( $user_id, 'cli_dashboard_start_date', true ) );
        $saved_end_date = $this->sanitize_reporting_date( get_user_meta( $user_id, 'cli_dashboard_end_date', true ) );
        $start_date = isset( $_GET['cli_start_date'] ) ? $this->sanitize_reporting_date( wp_unslash( $_GET['cli_start_date'] ) ) : $saved_start_date;
        $end_date = isset( $_GET['cli_end_date'] ) ? $this->sanitize_reporting_date( wp_unslash( $_GET['cli_end_date'] ) ) : $saved_end_date;
        if ( ! $start_date ) $start_date = $default_start_date;
        if ( ! $end_date ) $end_date = $default_end_date;
        if ( $start_date > $default_end_date ) $start_date = $default_end_date;
        if ( $end_date > $default_end_date ) $end_date = $default_end_date;
        if ( $start_date > $end_date ) { $swap = $start_date; $start_date = $end_date; $end_date = $swap; }

        $saved_comparison = $this->sanitize_comparison_mode( get_user_meta( $user_id, 'cli_dashboard_comparison', true ) );
        if ( '' === $saved_comparison ) $saved_comparison = '1' === (string) get_user_meta( $user_id, 'cli_dashboard_compare', true ) ? 'previous' : 'off';
        if ( isset( $_GET['cli_filter_submit'] ) ) {
            $comparison_mode = isset( $_GET['cli_comparison'] ) ? $this->sanitize_comparison_mode( wp_unslash( $_GET['cli_comparison'] ) ) : ( isset( $_GET['cli_compare'] ) ? 'previous' : 'off' );
        } else {
            $comparison_mode = $saved_comparison;
        }
        if ( '' === $comparison_mode ) $comparison_mode = 'off';
        if ( 'all_time' === $range ) $comparison_mode = 'off';
        $compare = 'off' !== $comparison_mode;

        update_user_meta( $user_id, 'cli_dashboard_range_v2', $range );
        update_user_meta( $user_id, 'cli_dashboard_start_month', $start_month );
        update_user_meta( $user_id, 'cli_dashboard_end_month', $end_month );
        update_user_meta( $user_id, 'cli_dashboard_start_date', $start_date );
        update_user_meta( $user_id, 'cli_dashboard_end_date', $end_date );
        update_user_meta( $user_id, 'cli_dashboard_comparison', $comparison_mode );
        update_user_meta( $user_id, 'cli_dashboard_compare', $compare ? '1' : '0' );

        $period = $this->resolve_reporting_period( $range, $start_month, $end_month, $start_date, $end_date );
        $previous_period = $this->comparison_reporting_period( $period, $comparison_mode );
        $comparison_description = $this->comparison_mode_label( $comparison_mode );
        $comparison_short_label = 'year_over_year' === $comparison_mode ? 'same period last year' : 'previous period';
        $last_30 = $this->reporting_days_period( 30 );
        $report_periods = [
            'last_30' => $last_30,

            'detail' => [ 'start_utc' => $period['start_utc'], 'end_utc' => $period['end_utc'] ],
        ];
        if ( $previous_period ) $report_periods['previous'] = [ 'start_utc' => $previous_period['start_utc'], 'end_utc' => $previous_period['end_utc'] ];
        $report_stats = $this->get_stats_for_periods( $report_periods );
        $stats_30 = $report_stats['last_30'];

        $detail_stats = $report_stats['detail'];
        $previous_stats = $previous_period ? $report_stats['previous'] : null;
        $this->page_header( 'Lead Intelligence Dashboard', 'Attribution performance from Gravity Forms submissions.' );



        echo '<section class="cli-card cli-reporting-period">';
        echo '<div class="cli-period-heading"><div><span class="cli-period-eyebrow">Reporting Period</span><h2>' . esc_html( $period['label'] ) . '</h2><p>' . esc_html( $period['display'] ) . '</p></div>';
        if ( $previous_period ) echo '<div class="cli-compare-caption"><strong>' . esc_html( $comparison_description ) . '</strong><span>' . esc_html( $previous_period['display'] ) . '</span></div>';
        echo '</div>';
        echo '<form method="get" class="cli-range-filter"><input type="hidden" name="page" value="calico-lead-intelligence"><input type="hidden" name="cli_filter_submit" value="1">';
        echo '<div class="cli-filter-field"><label for="cli-dashboard-range">Period</label><select name="cli_range" id="cli-dashboard-range">';
        $range_options = [
            'rolling_7' => 'Last 7 Days',
            'rolling_30' => 'Last 30 Days',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'last_2_months' => 'Last 2 Months',
            'last_3_months' => 'Last 3 Months',
            'year_to_date' => 'Year to Date',
            'all_time' => 'All Time',
            'custom_months' => 'Choose Month Range',
            'custom_dates' => 'Custom Date Range',
        ];
        foreach ( $range_options as $value => $label ) echo '<option value="' . esc_attr( $value ) . '" ' . selected( $range, $value, false ) . '>' . esc_html( $label ) . '</option>';
        echo '</select></div>';
        echo '<div class="cli-custom-months' . ( 'custom_months' === $range ? ' is-visible' : '' ) . '">';
        echo '<div class="cli-filter-field"><label for="cli-start-month">From</label><select name="cli_start_month" id="cli-start-month">' . $this->month_options_html( $start_month ) . '</select></div>';
        echo '<div class="cli-filter-field"><label for="cli-end-month">Through</label><select name="cli_end_month" id="cli-end-month">' . $this->month_options_html( $end_month ) . '</select></div>';
        echo '</div>';
        echo '<div class="cli-custom-dates' . ( 'custom_dates' === $range ? ' is-visible' : '' ) . '">';
        echo '<div class="cli-filter-field"><label for="cli-dashboard-start-date">From</label><input type="date" name="cli_start_date" id="cli-dashboard-start-date" value="' . esc_attr( $start_date ) . '" max="' . esc_attr( $default_end_date ) . '"></div>';
        echo '<div class="cli-filter-field"><label for="cli-dashboard-end-date">Through</label><input type="date" name="cli_end_date" id="cli-dashboard-end-date" value="' . esc_attr( $end_date ) . '" max="' . esc_attr( $default_end_date ) . '"></div>';
        echo '</div>';
        echo $this->comparison_control_html( $comparison_mode, 'dashboard', 'all_time' === $range );
        echo '<button type="submit" class="button button-primary">Apply</button></form></section>';

        echo '<div class="cli-metrics cli-comparison-summary">';
        if ( $previous_stats ) {
            $this->comparison_metric( 'Leads This Period', $detail_stats['total'], $previous_stats['total'], false, $comparison_short_label );
            $this->comparison_metric( 'Paid Leads This Period', $detail_stats['paid_total'], $previous_stats['paid_total'], false, $comparison_short_label );
        } else {
            $this->metric( 'Leads This Period', $detail_stats['total'] );
            $this->metric( 'Paid Leads This Period', $detail_stats['paid_total'] );
        }
        $this->cost_per_lead_metric( $period, $detail_stats['paid_total'] );
        echo '</div>';

        $winners = [
            'Top Lead Source' => $this->top_item( $stats_30['sources'] ),
            'Top Lead Channel' => $this->top_item( $stats_30['channels'] ),
            'Top Campaign' => $this->top_item( $stats_30['campaigns'] ),
            'Top Lead State' => $this->top_item( $stats_30['states'] ),
            'Top Paid Lead State' => $this->top_item( $stats_30['paid_states'] ),
            'Top Landing Page' => $this->top_item( $stats_30['landing_pages'] ),
            'Top Conversion Page' => $this->top_item( $stats_30['conversion_pages'] ),
            'Most Viewed Page' => $this->top_item( $stats_30['viewed_pages'] ),
        ];
        $this->winners_card( $winners );

        $this->top_lead_sources_card( $detail_stats['sources'], $detail_stats['paid_sources'], $period );


        echo '<div class="cli-grid">';
        $this->paid_leads_card( $this->dashboard_items( $detail_stats['paid_sources'] ), $detail_stats['paid_total'], $period );
        $this->table_card( 'Lead Sources', $this->dashboard_items( $detail_stats['sources'] ), $detail_stats['total'], 'sources', $previous_stats['sources'] ?? [], $compare, $period, $comparison_description );
        $this->table_card( 'Lead Channels', $this->dashboard_items( $detail_stats['channels'] ), $detail_stats['total'], 'channels', $previous_stats['channels'] ?? [], $compare, $period, $comparison_description );
        $this->table_card( 'Top Campaigns', $this->dashboard_items( $detail_stats['campaigns'] ), $detail_stats['total'], 'campaigns', $previous_stats['campaigns'] ?? [], $compare, [], $comparison_description );
        $this->table_card( 'Lead States', $this->dashboard_items( $detail_stats['states'] ), $detail_stats['total'], '', $previous_stats['states'] ?? [], $compare, [], $comparison_description );
        $this->table_card( 'Lead States from Paid Ads', $this->dashboard_items( $detail_stats['paid_states'] ), $detail_stats['paid_total'], '', $previous_stats['paid_states'] ?? [], $compare, [], $comparison_description );
        $this->table_card( 'Top Landing Pages', $this->dashboard_items( $detail_stats['landing_pages'] ), $detail_stats['total'], '', $previous_stats['landing_pages'] ?? [], $compare, [], $comparison_description );
        $this->table_card( 'Top Conversion Pages', $this->dashboard_items( $detail_stats['conversion_pages'] ), $detail_stats['total'], '', $previous_stats['conversion_pages'] ?? [], $compare, [], $comparison_description );
        $this->table_card( 'Most Viewed Pages', $this->dashboard_items( $detail_stats['viewed_pages'] ), array_sum( $detail_stats['viewed_pages'] ), '', $previous_stats['viewed_pages'] ?? [], $compare, [], $comparison_description );
        echo '</div>';
        echo '<script>(function(){var range=document.getElementById("cli-dashboard-range"),months=document.querySelector(".cli-custom-months"),dates=document.querySelector(".cli-custom-dates"),choices=document.querySelectorAll("#cli-comparison-dashboard input");if(!range)return;function sync(){var disabled=range.value==="all_time";months.classList.toggle("is-visible",range.value==="custom_months");dates.classList.toggle("is-visible",range.value==="custom_dates");choices.forEach(function(choice){choice.disabled=disabled;if(disabled)choice.checked=choice.value==="off";});}range.addEventListener("change",sync);sync();})();</script>';
        $this->page_footer();
    }

    public function render_leads() {
        $this->require_plugin_access();
        $this->page_header( 'Leads', 'Filter attributed Gravity Forms entries, review contact details, and export lead data.' );
        if ( ! class_exists( 'GFAPI' ) ) { $this->gf_missing(); $this->page_footer(); return; }

        $forms = GFAPI::get_forms( true );
        $context = $this->reporting_page_context( 'leads' );
        $context['compare'] = false;
        $context['previous'] = null;
        $legacy_from = isset( $_GET['cli_from'] ) ? $this->sanitize_reporting_date( wp_unslash( $_GET['cli_from'] ) ) : '';
        $legacy_to = isset( $_GET['cli_to'] ) ? $this->sanitize_reporting_date( wp_unslash( $_GET['cli_to'] ) ) : '';
        if ( $legacy_from || $legacy_to ) {
            $timezone = wp_timezone();
            if ( ! $legacy_from ) $legacy_from = $legacy_to;
            if ( ! $legacy_to ) $legacy_to = $legacy_from;
            if ( $legacy_from > $legacy_to ) { $swap = $legacy_from; $legacy_from = $legacy_to; $legacy_to = $swap; }
            $start_local = new DateTimeImmutable( $legacy_from . ' 00:00:00', $timezone );
            $end_local = new DateTimeImmutable( $legacy_to . ' 23:59:59', $timezone );
            $context['period'] = [
                'key' => 'custom_dates',
                'label' => 'Selected Dates',
                'display' => wp_date( 'M j, Y', $start_local->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M j, Y', $end_local->getTimestamp(), $timezone ),
                'start_local' => $start_local,
                'end_local' => $end_local,
                'start_utc' => $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
                'end_utc' => $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            ];
            $context['previous'] = null;
            $context['compare'] = false;
        }

        $filters = [
            'source' => isset( $_GET['cli_source'] ) ? sanitize_text_field( wp_unslash( $_GET['cli_source'] ) ) : '',
            'channel' => isset( $_GET['cli_channel'] ) ? sanitize_text_field( wp_unslash( $_GET['cli_channel'] ) ) : '',
            'form_id' => isset( $_GET['cli_form_id'] ) ? absint( $_GET['cli_form_id'] ) : 0,
        ];
        $start_utc = $context['period']['start_utc'];
        $end_utc = $context['period']['end_utc'];
        $filter_stats = $this->get_stats_for_period( $start_utc, $end_utc );
        $source_options = array_keys( $filter_stats['sources'] );
        $channel_options = array_keys( $filter_stats['channels'] );
        if ( $filters['source'] && ! in_array( $filters['source'], $source_options, true ) ) $source_options[] = $filters['source'];
        if ( $filters['channel'] && ! in_array( $filters['channel'], $channel_options, true ) ) $channel_options[] = $filters['channel'];
        natcasesort( $source_options );
        natcasesort( $channel_options );

        $extra_fields = '<div class="cli-filter-field"><label for="cli-leads-source">Source</label><select id="cli-leads-source" name="cli_source"><option value="">All Sources</option>';
        foreach ( $source_options as $source ) $extra_fields .= '<option value="' . esc_attr( $source ) . '" ' . selected( $filters['source'], $source, false ) . '>' . esc_html( $source ) . '</option>';
        $extra_fields .= '</select></div><div class="cli-filter-field"><label for="cli-leads-channel">Channel</label><select id="cli-leads-channel" name="cli_channel"><option value="">All Channels</option>';
        foreach ( $channel_options as $channel ) $extra_fields .= '<option value="' . esc_attr( $channel ) . '" ' . selected( $filters['channel'], $channel, false ) . '>' . esc_html( $channel ) . '</option>';
        $extra_fields .= '</select></div><div class="cli-filter-field"><label for="cli-leads-form">Form</label><select id="cli-leads-form" name="cli_form_id"><option value="0">All Forms</option>';
        foreach ( $forms as $form ) $extra_fields .= '<option value="' . esc_attr( $form['id'] ) . '" ' . selected( $filters['form_id'], (int) $form['id'], false ) . '>' . esc_html( $form['title'] ) . '</option>';
        $extra_fields .= '</select></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=calico-lead-intelligence-leads' ) ) . '">Reset</a>';
        $this->render_reporting_period_filter( 'calico-lead-intelligence-leads', $context, $extra_fields, false );

        $result = $this->filtered_live_leads( $forms, $filters, $start_utc, $end_utc );
        $export_args = [
            'action' => 'cli_export_leads',
            'scope' => 'filtered',
            'cli_range' => $context['range'],
            'cli_start_month' => $context['start_month'],
            'cli_end_month' => $context['end_month'],
            'cli_source' => $filters['source'],
            'cli_channel' => $filters['channel'],
            'cli_form_id' => $filters['form_id'],
        ];
        if ( $legacy_from || $legacy_to ) { $export_args['cli_from'] = $legacy_from; $export_args['cli_to'] = $legacy_to; }
        $filtered_export = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'cli_export_leads' );
        $all_export = wp_nonce_url( add_query_arg( [ 'action'=>'cli_export_leads', 'scope'=>'all' ], admin_url( 'admin-post.php' ) ), 'cli_export_leads' );
        echo '<div class="cli-live-toolbar"><div class="cli-live-summary"><strong>' . esc_html( number_format_i18n( $result['total'] ) ) . '</strong> matching ' . ( 1 === $result['total'] ? 'lead' : 'leads' ) . '</div><div><a class="button button-primary" href="' . esc_url( $filtered_export ) . '">Export Filtered Leads</a> <a class="button" href="' . esc_url( $all_export ) . '">Export All Leads</a></div></div>';
        if ( $result['truncated'] ) echo '<div class="notice notice-warning inline"><p>On-screen results are limited to the newest scanned entries. CSV exports scan the complete selected range.</p></div>';

        $per_page = 50;
        $page_number = isset( $_GET['cli_leads_page'] ) ? max( 1, absint( $_GET['cli_leads_page'] ) ) : 1;
        $total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
        $page_number = min( $page_number, $total_pages );
        $rows = array_slice( $result['rows'], ( $page_number - 1 ) * $per_page, $per_page );
        echo '<div class="cli-card cli-live-results"><div class="cli-table-scroll"><table class="widefat striped"><thead><tr><th>Date</th><th>Name</th><th>Email</th><th>Source</th><th>Channel</th><th>Campaign</th><th>Form</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $email = $row['email'] ? '<a href="mailto:' . esc_attr( $row['email'] ) . '">' . esc_html( $row['email'] ) . '</a>' : '&mdash;';
            echo '<tr><td>' . esc_html( $row['date'] ) . '</td><td>' . esc_html( $row['name'] ?: '—' ) . '</td><td>' . $email . '</td><td><span class="cli-badge cli-badge-small">' . esc_html( $row['source'] ) . '</span></td><td>' . esc_html( $row['channel'] ) . '</td><td>' . esc_html( $row['campaign'] ?: '—' ) . '</td><td>' . esc_html( $row['form'] ) . '</td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="7">No leads match these filters.</td></tr>';
        echo '</tbody></table></div></div>';

        if ( $total_pages > 1 ) {
            $page_args = [ 'page'=>'calico-lead-intelligence-leads', 'cli_range'=>$context['range'], 'cli_start_month'=>$context['start_month'], 'cli_end_month'=>$context['end_month'], 'cli_source'=>$filters['source'], 'cli_channel'=>$filters['channel'], 'cli_form_id'=>$filters['form_id'] ];
            if ( $legacy_from || $legacy_to ) { $page_args['cli_from'] = $legacy_from; $page_args['cli_to'] = $legacy_to; }
            echo '<nav class="cli-live-pagination" aria-label="Leads pages">';
            if ( $page_number > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'cli_leads_page' => $page_number - 1 ] ), admin_url( 'admin.php' ) ) ) . '">&larr; Previous</a>';
            echo '<span>Page ' . esc_html( $page_number ) . ' of ' . esc_html( $total_pages ) . '</span>';
            if ( $page_number < $total_pages ) echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'cli_leads_page' => $page_number + 1 ] ), admin_url( 'admin.php' ) ) ) . '">Next &rarr;</a>';
            echo '</nav>';
        }
        $this->page_footer();
    }
    private function sanitize_reporting_date( $value ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) return '';
        return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
    }

    private function filtered_live_leads( $forms, $filters, $start_utc, $end_utc, $scan_limit_override = null ) {
        $form_map = [];
        foreach ( (array) $forms as $form ) $form_map[ (int) $form['id'] ] = $form;
        $form_ids = $filters['form_id'] && isset( $form_map[ $filters['form_id'] ] ) ? [ $filters['form_id'] ] : array_keys( $form_map );
        if ( ! $form_ids ) return [ 'rows' => [], 'total' => 0, 'truncated' => false ];

        $criteria = [ 'status' => 'active' ];
        if ( $start_utc ) $criteria['start_date'] = $start_utc;
        if ( $end_utc ) $criteria['end_date'] = $end_utc;
        $query_forms = 1 === count( $form_ids ) ? reset( $form_ids ) : $form_ids;
        $campaign_mappings = $this->get_google_campaign_mappings();
        $exclude_unknowns = empty( $filters['include_unknowns'] ) && 'exclude' === get_option( 'cli_reporting_unknown_policy', 'include' );
        if ( null === $scan_limit_override ) {
            $scan_limit = (int) apply_filters( 'cli_live_leads_scan_limit', 10000 );
            $scan_limit = max( 200, min( 50000, $scan_limit ) );
        } else {
            $scan_limit = max( 0, (int) $scan_limit_override );
        }
        $offset = 0;
        $scanned = 0;
        $rows = [];
        $truncated = false;

        do {
            $page_size = $scan_limit ? min( 200, $scan_limit - $scanned ) : 200;
            if ( $page_size < 1 ) { $truncated = true; break; }
            $entries = GFAPI::get_entries( $query_forms, $criteria, [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => $offset, 'page_size' => $page_size ] );
            if ( is_wp_error( $entries ) || ! $entries ) break;
            $entry_ids = array_map( function( $entry ) { return (int) rgar( $entry, 'id' ); }, $entries );
            $meta_by_entry = $this->load_reporting_meta( $entry_ids );
            foreach ( $entries as $entry ) {
                $entry_id = (int) rgar( $entry, 'id' );
                $form_id = (int) rgar( $entry, 'form_id' );
                if ( ! isset( $form_map[ $form_id ] ) ) continue;
                $meta = $meta_by_entry[ $entry_id ] ?? [];
                $report_entry = $this->prepare_reporting_entry( $entry, $form_map[ $form_id ], $meta, $campaign_mappings );
                if ( $exclude_unknowns && 'Unknown' === $report_entry['source'] ) continue;
                if ( $filters['source'] && 0 !== strcasecmp( $filters['source'], $report_entry['source'] ) ) continue;
                if ( $filters['channel'] && 0 !== strcasecmp( $filters['channel'], $report_entry['channel'] ) ) continue;
                $contact = $this->lead_contact_details( $entry, $form_map[ $form_id ] );
                $rows[] = [
                    'entry_id' => $entry_id,
                    'form_id' => $form_id,
                    'date' => (string) rgar( $entry, 'date_created' ),
                    'name' => $contact['name'],
                    'email' => $contact['email'],
                    'source' => $report_entry['source'],
                    'channel' => $report_entry['channel'],
                    'campaign' => $report_entry['campaign'],
                    'state' => $report_entry['state'],
                    'landing' => $report_entry['landing'],
                    'conversion' => $report_entry['conversion'],
                    'journey' => implode( ' > ', (array) $report_entry['journey'] ),
                    'form' => (string) $form_map[ $form_id ]['title'],
                ];
            }
            $count = count( $entries );
            $offset += $count;
            $scanned += $count;
            if ( $scan_limit && $scanned >= $scan_limit && $count === $page_size ) $truncated = true;
        } while ( $count === $page_size && ( ! $scan_limit || $scanned < $scan_limit ) );

        usort( $rows, function( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
        return [ 'rows' => $rows, 'total' => count( $rows ), 'truncated' => $truncated ];
    }

    public function export_leads_csv() {
        $this->require_plugin_access( __( 'You are not allowed to export leads.', 'calico-lead-intelligence' ) );
        check_admin_referer( 'cli_export_leads' );
        if ( ! class_exists( 'GFAPI' ) ) wp_die( esc_html__( 'Gravity Forms is required to export leads.', 'calico-lead-intelligence' ) );

        $scope = isset( $_GET['scope'] ) && 'all' === sanitize_key( wp_unslash( $_GET['scope'] ) ) ? 'all' : 'filtered';
        $forms = GFAPI::get_forms( true );
        $filters = [
            'source' => 'all' === $scope ? '' : sanitize_text_field( wp_unslash( $_GET['cli_source'] ?? '' ) ),
            'channel' => 'all' === $scope ? '' : sanitize_text_field( wp_unslash( $_GET['cli_channel'] ?? '' ) ),
            'form_id' => 'all' === $scope ? 0 : absint( $_GET['cli_form_id'] ?? 0 ),
            'include_unknowns' => 'all' === $scope,
        ];
        $start_utc = null;
        $end_utc = null;
        if ( 'filtered' === $scope ) {
            $range = sanitize_key( wp_unslash( $_GET['cli_range'] ?? 'rolling_30' ) );
            $period = $this->resolve_reporting_period( $range, wp_unslash( $_GET['cli_start_month'] ?? '' ), wp_unslash( $_GET['cli_end_month'] ?? '' ) );
            $start_utc = $period['start_utc'];
            $end_utc = $period['end_utc'];
            $from = $this->sanitize_reporting_date( wp_unslash( $_GET['cli_from'] ?? '' ) );
            $to = $this->sanitize_reporting_date( wp_unslash( $_GET['cli_to'] ?? '' ) );
            if ( $from || $to ) {
                if ( ! $from ) $from = $to;
                if ( ! $to ) $to = $from;
                if ( $from > $to ) { $swap = $from; $from = $to; $to = $swap; }
                $timezone = wp_timezone();
                $start_utc = ( new DateTimeImmutable( $from . ' 00:00:00', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
                $end_utc = ( new DateTimeImmutable( $to . ' 23:59:59', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            }
        }
        $result = $this->filtered_live_leads( $forms, $filters, $start_utc, $end_utc, 0 );
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        $filename = 'calico-leads-' . ( 'all' === $scope ? 'all' : 'filtered' ) . '-' . wp_date( 'Y-m-d-His', current_time( 'timestamp' ) ) . '.csv';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        $output = fopen( 'php://output', 'wb' );
        fwrite( $output, "\xEF\xBB\xBF" );
        fputcsv( $output, [ 'Entry ID', 'Date', 'Name', 'Email', 'Source', 'Channel', 'Campaign', 'State', 'Landing Page', 'Conversion Page', 'Customer Journey', 'Form', 'Form ID' ], ',', '"', '\\' );
        foreach ( $result['rows'] as $row ) {
            $values = [ $row['entry_id'], $row['date'], $row['name'], $row['email'], $row['source'], $row['channel'], $row['campaign'], $row['state'], $row['landing'], $row['conversion'], $row['journey'], $row['form'], $row['form_id'] ];
            $values = array_map( [ $this, 'csv_safe_cell' ], $values );
            fputcsv( $output, $values, ',', '"', '\\' );
        }
        fclose( $output );
        exit;
    }

    public function csv_safe_cell( $value ) {
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value );
        return preg_match( '/^[=+\-@]/', ltrim( $value ) ) ? "'" . $value : $value;
    }
    private function lead_contact_details( $entry, $form ) {
        $name = '';
        $email = '';
        foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
            if ( ! is_object( $field ) ) continue;
            $type = (string) ( $field->type ?? '' );
            $id = (string) ( $field->id ?? '' );
            $label = trim( (string) ( $field->label ?? '' ) );
            if ( ! $email && 'email' === $type ) $email = sanitize_email( (string) rgar( $entry, $id ) );
            if ( ! $name && 'name' === $type ) {
                $parts = [];
                foreach ( [ '.3', '.4', '.6' ] as $suffix ) {
                    $part = trim( sanitize_text_field( (string) rgar( $entry, $id . $suffix ) ) );
                    if ( $part ) $parts[] = $part;
                }
                $name = $parts ? implode( ' ', $parts ) : trim( sanitize_text_field( (string) rgar( $entry, $id ) ) );
            }
            if ( ! $name && in_array( $type, [ 'text', 'hidden' ], true ) && preg_match( '/^(full\s+name|name)$/i', $label ) ) {
                $name = trim( sanitize_text_field( (string) rgar( $entry, $id ) ) );
            }
            if ( ! $email && in_array( $type, [ 'text', 'hidden' ], true ) && preg_match( '/e-?mail/i', $label ) ) {
                $email = sanitize_email( (string) rgar( $entry, $id ) );
            }
        }
        return [ 'name' => $name, 'email' => $email ];
    }

    public function render_sources() {
        $this->require_plugin_access();
        $mapping_action = isset( $_POST['cli_source_mapping_action'] ) ? sanitize_key( wp_unslash( $_POST['cli_source_mapping_action'] ) ) : '';
        if ( 'save' === $mapping_action || isset( $_POST['cli_save_source_mappings'] ) || isset( $_POST['cli_reset_source_mappings'] ) ) {
            check_admin_referer( 'cli_save_source_mappings' );
            $this->require_plugin_management_access( __( 'Contact the Lead Intelligence Access Owner to change source mappings.', 'calico-lead-intelligence' ) );
            if ( isset( $_POST['cli_reset_source_mappings'] ) ) {
                update_option( 'cli_source_mappings', [], false );
                $notice = 'Source mappings reset. Reports now use the original detected classifications.';
                $notice_class = 'notice-success';
            } else {
                $detected = isset( $_POST['cli_detected_source'] ) ? (array) wp_unslash( $_POST['cli_detected_source'] ) : [];
                $targets = isset( $_POST['cli_source_mapping_target'] ) ? (array) wp_unslash( $_POST['cli_source_mapping_target'] ) : [];
                $mappings = [];
                $rejected = 0;
                foreach ( $detected as $index => $raw_source ) {
                    $raw_source = sanitize_text_field( (string) $raw_source );
                    $target = sanitize_text_field( (string) ( $targets[ $index ] ?? '' ) );
                    if ( ! $raw_source || '' === $target ) continue;
                    $decoded_target = $this->decode_source_mapping_target( $target );
                    if ( ! $decoded_target ) {
                        $rejected++;
                        continue;
                    }
                    $mappings[ $this->source_mapping_key( $raw_source ) ] = [
                        'detected' => $raw_source,
                        'source' => $decoded_target['source'],
                        'channel' => $decoded_target['channel'],
                    ];
                }
                update_option( 'cli_source_mappings', $mappings, false );
                $saved_count = count( $mappings );
                $notice = sprintf(
                    '%d source mapping%s saved. Reporting caches were cleared and all reports recalculated without changing Gravity Forms entries.',
                    $saved_count,
                    1 === $saved_count ? '' : 's'
                );
                $notice_class = $rejected ? 'notice-warning' : 'notice-success';
                if ( $rejected ) $notice .= ' ' . sprintf( '%d invalid selection%s rejected.', $rejected, 1 === $rejected ? ' was' : 's were' );
            }
            $this->invalidate_reporting_cache();
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }
        $context = $this->reporting_page_context( 'sources' );
        $periods = [ 'current' => [ 'start_utc' => $context['period']['start_utc'], 'end_utc' => $context['period']['end_utc'] ] ];
        if ( $context['previous'] ) $periods['previous'] = [ 'start_utc' => $context['previous']['start_utc'], 'end_utc' => $context['previous']['end_utc'] ];
        $sets = $this->get_stats_for_periods( $periods );
        $stats = $sets['current'];
        $previous = $sets['previous'] ?? null;
        $this->page_header( 'Sources', 'Lead source breakdown for the selected reporting period.' );
        $this->render_reporting_period_filter( 'calico-lead-intelligence-sources', $context );
        $this->table_card( 'Reported Sources', $stats['sources'], $stats['total'], '', $previous['sources'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        $this->table_card( 'All Channels', $stats['channels'], $stats['total'], '', $previous['channels'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        if ( ! $this->current_user_can_manage_plugin() ) {
            echo '<div class="cli-card cli-access-restricted"><h2>Reporting Mappings</h2><p>Source mappings are managed by the Lead Intelligence Access Owner. Contact the plugin owner if a reporting classification needs to be changed.</p></div>';
            $this->page_footer();
            return;
        }
        $mappings = $this->get_source_mappings();
        $detected_sources = $stats['detected_sources'];
        foreach ( $mappings as $mapping ) if ( ! isset( $detected_sources[ $mapping['detected'] ] ) ) $detected_sources[ $mapping['detected'] ] = 0;
        arsort( $detected_sources );
        $targets = $this->reporting_mapping_targets( array_keys( $detected_sources ) );
        echo '<div class="cli-card cli-source-mappings"><h2>Detected Sources and Reporting Mappings</h2><p>Original detected sources remain unchanged. Choose a client-specific reporting classification, then save to recalculate dashboards, Leads, comparisons, journeys, and PDFs.</p><form method="post">';
        wp_nonce_field( 'cli_save_source_mappings' );
        echo '<input type="hidden" name="cli_source_mapping_action" value="save">';
        echo '<table class="widefat striped"><thead><tr><th>Detected Source</th><th class="cli-number-col">Leads</th><th class="cli-share-col">Share</th><th>Remap to…</th></tr></thead><tbody>';
        foreach ( $detected_sources as $detected_source => $count ) {
            $key = $this->source_mapping_key( $detected_source );
            $selected_target = isset( $mappings[ $key ] ) ? $this->encode_source_mapping_target( $mappings[ $key ]['source'], $mappings[ $key ]['channel'] ) : '';
            $share = $stats['total'] ? round( $count / $stats['total'] * 100, 1 ) : 0;
            echo '<tr><td><strong>' . esc_html( $detected_source ) . '</strong><input type="hidden" name="cli_detected_source[]" value="' . esc_attr( $detected_source ) . '"></td><td class="cli-number-col">' . esc_html( $count ) . '</td><td class="cli-share-col">' . esc_html( $share ) . '%</td><td><select name="cli_source_mapping_target[]"><option value="">No mapping</option>';
            foreach ( $targets as $value => $label ) echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_target, $value, false ) . '>' . esc_html( $label ) . '</option>';
            echo '</select></td></tr>';
        }
        if ( ! $detected_sources ) echo '<tr><td colspan="4">No detected sources are available yet.</td></tr>';
        echo '</tbody></table><p><button type="submit" class="button button-primary" name="cli_save_source_mappings" value="1" onclick="return window.confirm(\'Save these client reporting mappings and recalculate reports? Original Gravity Forms entries will remain unchanged.\');">Save and Recalculate</button> <button type="submit" class="button" name="cli_reset_source_mappings" value="1" onclick="return window.confirm(\'Reset every reporting source mapping? Original Gravity Forms entries will remain unchanged.\');">Reset Source Mappings</button></p></form></div>';
        $this->page_footer();
    }

    public function render_campaigns() {
        $this->require_plugin_access();
        $context = $this->reporting_page_context( 'campaigns' );
        $allowed_channels = [ 'Paid', 'Organic', 'Direct', 'Email', 'Referral', 'AI', 'Unknown' ];
        $user_id = get_current_user_id();
        $saved_channel = sanitize_text_field( (string) get_user_meta( $user_id, 'cli_campaigns_channel', true ) );
        $channel = isset( $_GET['cli_channel'] ) ? sanitize_text_field( wp_unslash( $_GET['cli_channel'] ) ) : $saved_channel;
        if ( ! in_array( $channel, $allowed_channels, true ) ) $channel = '';
        $saved_source = sanitize_text_field( (string) get_user_meta( $user_id, 'cli_campaigns_source', true ) );
        $source = isset( $_GET['cli_source'] ) ? sanitize_text_field( wp_unslash( $_GET['cli_source'] ) ) : $saved_source;
        $source = substr( $source, 0, 191 );
        update_user_meta( $user_id, 'cli_campaigns_channel', $channel );
        update_user_meta( $user_id, 'cli_campaigns_source', $source );

        $periods = [ 'current' => [ 'start_utc' => $context['period']['start_utc'], 'end_utc' => $context['period']['end_utc'] ] ];
        if ( $context['previous'] ) $periods['previous'] = [ 'start_utc' => $context['previous']['start_utc'], 'end_utc' => $context['previous']['end_utc'] ];
        $option_sets = $this->get_stats_for_periods( $periods );
        $source_options = array_keys( $option_sets['current']['sources'] );
        if ( $source && ! in_array( $source, $source_options, true ) ) $source_options[] = $source;
        natcasesort( $source_options );
        $sets = ( ! $channel && ! $source ) ? $option_sets : $this->get_stats_for_periods( $periods, [ 'channel' => $channel, 'source' => $source ] );
        $stats = $sets['current'];
        $previous = $sets['previous'] ?? null;

        $campaign_fields = '<div class="cli-filter-field"><label for="cli-campaign-channel">Channel</label><select name="cli_channel" id="cli-campaign-channel"><option value="">All Channels</option>';
        foreach ( $allowed_channels as $option ) $campaign_fields .= '<option value="' . esc_attr( $option ) . '" ' . selected( $channel, $option, false ) . '>' . esc_html( $option ) . '</option>';
        $campaign_fields .= '</select></div><div class="cli-filter-field"><label for="cli-campaign-source">Source</label><select name="cli_source" id="cli-campaign-source"><option value="">All Sources</option>';
        foreach ( $source_options as $option ) $campaign_fields .= '<option value="' . esc_attr( $option ) . '" ' . selected( $source, $option, false ) . '>' . esc_html( $option ) . '</option>';
        $campaign_fields .= '</select></div>';

        $this->page_header( 'Campaigns', 'Campaign attribution for the selected reporting period, channel, and source. Campaign names are reported exactly as captured; IDs are shown when a platform does not provide a name.' );
        $this->render_reporting_period_filter( 'calico-lead-intelligence-campaigns', $context, $campaign_fields );
        $coverage = $stats['paid_total'] > 0 ? round( ( $stats['paid_campaign_total'] / $stats['paid_total'] ) * 100, 1 ) . '%' : '0%';
        echo '<div class="cli-metrics cli-metrics-four">';
        $this->metric( 'Leads With Campaign Data', $stats['campaign_total'] );
        $this->metric( 'Paid Leads With Campaign Data', $stats['paid_campaign_total'] );
        $this->metric( 'Paid Leads Missing Campaign', max( 0, $stats['paid_total'] - $stats['paid_campaign_total'] ) );
        $this->metric( 'Paid Campaign Coverage', $coverage );
        echo '</div>';
        if ( $previous ) {
            echo '<div class="cli-metrics cli-comparison-summary">';
            $comparison_short_label = 'year_over_year' === $context['comparison_mode'] ? 'same period last year' : 'previous period';
            $this->comparison_metric( 'Campaign-Attributed Leads', $stats['campaign_total'], $previous['campaign_total'], false, $comparison_short_label );
            $this->comparison_metric( 'Paid Leads With Campaign Data', $stats['paid_campaign_total'], $previous['paid_campaign_total'], false, $comparison_short_label );
            $this->comparison_metric( 'Paid Leads Missing Campaign', max( 0, $stats['paid_total'] - $stats['paid_campaign_total'] ), max( 0, $previous['paid_total'] - $previous['paid_campaign_total'] ), true, $comparison_short_label );
            $this->comparison_metric( 'Paid Leads', $stats['paid_total'], $previous['paid_total'], false, $comparison_short_label );
            echo '</div>';
        }
        $this->table_card( 'All Campaigns', $stats['campaigns'], $stats['total'], 'campaigns', $previous['campaigns'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        $this->page_footer();
    }
    public function render_journeys() {
        $this->require_plugin_access();
        $context = $this->reporting_page_context( 'journeys' );
        $periods = [ 'current' => [ 'start_utc' => $context['period']['start_utc'], 'end_utc' => $context['period']['end_utc'] ] ];
        if ( $context['previous'] ) $periods['previous'] = [ 'start_utc' => $context['previous']['start_utc'], 'end_utc' => $context['previous']['end_utc'] ];
        $sets = $this->get_stats_for_periods( $periods );
        $stats = $sets['current'];
        $previous = $sets['previous'] ?? null;
        $this->page_header( 'Customer Journey Explorer', 'Discover the pages and common paths that contributed to Gravity Forms conversions.' );
        $this->render_reporting_period_filter( 'calico-lead-intelligence-journeys', $context );
        $this->mvp_card( [
            'Most Viewed Page' => $this->top_item( $stats['viewed_pages'] ),
            'Most Conversions' => $this->top_item( $stats['conversion_pages'] ),
            'Top Assisting Page' => $this->top_item( $stats['assisted_pages'] ),
            'Top Landing Page' => $this->top_item( $stats['landing_pages'] ),
        ] );
        echo '<div class="cli-grid cli-mvp-grid">';
        $this->table_card( 'Most Viewed Pages', $this->dashboard_items( $stats['viewed_pages'] ), array_sum( $stats['viewed_pages'] ), '', $previous['viewed_pages'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        $this->table_card( 'Most Conversions', $this->dashboard_items( $stats['conversion_pages'] ), $stats['total'], '', $previous['conversion_pages'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        $this->table_card( 'Top Assisting Pages', $this->dashboard_items( $stats['assisted_pages'] ), $stats['total'], '', $previous['assisted_pages'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        $this->table_card( 'Top Landing Pages', $this->dashboard_items( $stats['landing_pages'] ), $stats['total'], '', $previous['landing_pages'] ?? [], (bool) $previous, [], $context['comparison_label'] );
        echo '</div>';
        $this->journey_paths_card( array_slice( $stats['journeys'], 0, 20, true ) );
        $this->page_footer();
    }

    public function render_rules() {
        $this->require_plugin_access();
        $this->page_header( 'Attribution Rules', 'Attribution Catalog v' . CLI_CATALOG_VERSION . ' evaluates every visit in priority order. The first complete evidence match wins; the engine never guesses.' );
        echo '<div class="cli-card"><p><strong>One engine, one answer.</strong> Entries, notification emails, dashboards, audits, and the Rule Tester all use this exact catalog.</p></div>';
        echo '<div class="cli-card"><table class="widefat striped"><thead><tr><th>Priority</th><th>Source</th><th>Rule</th><th>Required Evidence</th></tr></thead><tbody>';
        foreach ( $this->attribution_catalog() as $rule ) {
            echo '<tr><td>' . esc_html( $rule['priority'] ) . '</td><td><strong>' . esc_html( $rule['source'] ) . '</strong></td><td>' . esc_html( $rule['name'] ) . '</td><td>' . esc_html( implode( ' OR ', $rule['summary'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->page_footer();
    }

    public function render_diagnostics() {
        $this->require_plugin_access();
        $this->page_header( 'Diagnostics', 'Confirm that WordPress, Gravity Forms, JavaScript tracking, and form capture are working.' );
        echo '<div class="cli-grid cli-diagnostics">';
        $this->status_card( 'Plugin Loaded', true, 'Calico Lead Intelligence v' . CLI_VERSION . ' is active. Attribution Catalog v' . CLI_CATALOG_VERSION . '.' );
        $this->status_card( 'Gravity Forms', class_exists( 'GFForms' ), class_exists( 'GFForms' ) ? 'Gravity Forms is available.' : 'Gravity Forms was not detected.' );
        $this->status_card( 'Gravity Forms API', class_exists( 'GFAPI' ), class_exists( 'GFAPI' ) ? 'GFAPI is available.' : 'GFAPI is unavailable.' );
        $this->status_card( 'Tracking Script', true, 'The front-end tracker is registered and should load for public visitors.' );
        echo '</div>';
        echo '<div class="cli-card"><h2>Rule Tester</h2><p>Paste a landing URL and, when known, the external referrer. The engine returns one definitive source, the exact matched rule, and the evidence used.</p><table class="form-table"><tr><th><label for="cli-test-url">Landing URL</label></th><td><input type="url" class="regular-text code" id="cli-test-url" placeholder="https://example.com/?utm_source=google&utm_medium=cpc&gclid=ABC123"></td></tr><tr><th><label for="cli-test-referrer">External Referrer</label></th><td><input type="url" class="regular-text code" id="cli-test-referrer" placeholder="https://www.google.com/"></td></tr></table><button type="button" class="button button-primary" id="cli-run-rule-test">Analyze Attribution</button><pre id="cli-rule-test-output">Waiting for a URL...</pre></div>';
        echo '<div class="cli-card"><h2>Recent Lead Audit</h2><p>Compare the source currently stored on recent Gravity Forms entries with the result from the new strict rules engine. This report is read-only and does not change entries.</p><button type="button" class="button" id="cli-run-audit">Audit Recent Leads</button><pre id="cli-audit-output">Waiting to run audit...</pre></div>';
        echo '<div class="cli-card"><h2>Regression Test Suite</h2><p>Run permanent test cases for paid search, organic search, Meta, AI, email, link-in-bio, social, ticketing, referrals, direct, and unknown traffic.</p><button type="button" class="button" id="cli-run-regression">Run Regression Tests</button><pre id="cli-regression-output">Waiting to run tests...</pre></div>';
        echo '<div class="cli-card"><h2>Browser Tracking Test</h2><p>Open this page in the same browser after visiting the public website. The test below checks browser storage and the current attribution payload.</p><button type="button" class="button" id="cli-run-diagnostics">Run Browser Test</button><pre id="cli-diagnostics-output">Waiting to run test...</pre></div>';
        echo '<div class="cli-card"><h2>Expected Storage Keys</h2><code>cli_first_touch</code> <code>cli_last_touch</code> <code>cli_session</code></div>';
        $this->page_footer();
    }

    public function render_settings() {
        $this->require_plugin_access();
        if ( ! $this->current_user_can_manage_plugin() ) {
            $this->render_owner_contact_page( 'Settings' );
            return;
        }
        $pro_notice = '';
        if ( isset( $_POST['cli_pro_action'] ) ) {
            check_admin_referer( 'cli_pro_preview' );
            $action = is_string( $_POST['cli_pro_action'] ) ? sanitize_key( wp_unslash( $_POST['cli_pro_action'] ) ) : '';
            $code = isset( $_POST['cli_pro_code'] ) && is_string( $_POST['cli_pro_code'] ) ? wp_unslash( $_POST['cli_pro_code'] ) : '';
            $pro_notice = $this->set_pro_preview( $action, $code )
                ? ( 'deactivate' === $action ? 'Pro deactivated. Report delivery paused; saved settings and data retained.' : 'Pro activated. Any enabled saved report schedule has resumed.' )
                : 'Unable to change Pro access. Check the code and sign in as the Access Owner.';
        }
        $access_owner_saved = false;
        $access_owner_error = '';
        if ( isset( $_POST['cli_save_access_owner'] ) ) {
            check_admin_referer( 'cli_save_access_owner' );
            $this->require_plugin_management_access( __( 'You are not allowed to change the Lead Intelligence access owner.', 'calico-lead-intelligence' ) );
            $new_access_mode = $this->sanitize_access_mode( wp_unslash( $_POST['cli_access_mode'] ?? '' ) );
            $new_owner_id = absint( $_POST['cli_access_owner_user_id'] ?? 0 );
            $new_owner = $this->valid_access_owner( $new_owner_id );
            if ( ! $new_access_mode ) {
                $access_owner_error = 'Choose a valid access mode.';
            } elseif ( 'no_restrictions' !== $new_access_mode && ! $new_owner ) {
                $access_owner_error = 'Choose an active WordPress administrator for Client or Stealth Mode.';
            } elseif ( $new_owner_id && ! $new_owner ) {
                $access_owner_error = 'Choose an active WordPress administrator.';
            } else {
                update_option( 'cli_access_owner_user_id', $new_owner_id, false );
                update_option( 'cli_access_mode', $new_access_mode, false );
                if ( 'no_restrictions' !== $new_access_mode && $new_owner_id !== get_current_user_id() ) {
                    wp_safe_redirect( admin_url( 'index.php?cli_access_transferred=1' ) );
                    exit;
                }
                $access_owner_saved = true;
            }
        }
        $budget_saved = false;
        if ( isset( $_POST['cli_save_client_budget'] ) ) {
            check_admin_referer( 'cli_save_client_budget' );
            $this->require_plugin_management_access( __( 'Contact the Lead Intelligence Access Owner to change budget settings.', 'calico-lead-intelligence' ) );
            $budget = $this->sanitize_client_budget( wp_unslash( $_POST['cli_client_30_day_budget'] ?? '' ) );
            update_option( 'cli_client_30_day_budget', $budget, false );
            $budget_saved = true;
            $this->invalidate_reporting_cache();
        }
        if ( isset( $_POST['cli_save_campaign_mappings'] ) ) {
            check_admin_referer( 'cli_save_campaign_mappings' );
            $this->require_plugin_management_access( __( 'Contact the Lead Intelligence Access Owner to change campaign mappings.', 'calico-lead-intelligence' ) );
            $ids = isset( $_POST['cli_campaign_map_id'] ) ? (array) wp_unslash( $_POST['cli_campaign_map_id'] ) : [];
            $names = isset( $_POST['cli_campaign_map_name'] ) ? (array) wp_unslash( $_POST['cli_campaign_map_name'] ) : [];
            $mappings = [];
            foreach ( $ids as $index => $raw_id ) {
                $id = preg_replace( '/[^0-9A-Za-z_-]/', '', (string) $raw_id );
                $name = sanitize_text_field( $names[ $index ] ?? '' );
                if ( '' !== $id && '' !== $name ) $mappings[ $id ] = $name;
            }
            update_option( 'cli_google_campaign_mappings', $mappings, false );
            $this->invalidate_reporting_cache();
            echo '<div class="notice notice-success is-dismissible"><p>Google Ads campaign mappings saved.</p></div>';
        }

        $this->page_header( 'Settings', 'Global defaults, form-level controls, and complete Lead Intelligence backups.' );
        if ( $pro_notice ) echo '<div class="notice notice-info"><p>' . esc_html( $pro_notice ) . '</p></div>';
        $this->render_pro_settings();
        if ( $access_owner_saved ) echo '<div class="notice notice-success is-dismissible"><p>Lead Intelligence access owner saved.</p></div>';
        if ( $access_owner_error ) echo '<div class="notice notice-error"><p>' . esc_html( $access_owner_error ) . '</p></div>';
        if ( $budget_saved ) echo '<div class="notice notice-success is-dismissible"><p>Client 30-day budget saved.</p></div>';
        if ( isset( $_GET['cli_backup_notice'] ) && 'imported' === sanitize_key( wp_unslash( $_GET['cli_backup_notice'] ) ) ) {
            $entry_count = absint( $_GET['entries'] ?? 0 );
            $meta_count = absint( $_GET['meta'] ?? 0 );
            $form_count = absint( $_GET['forms'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>Backup restored: ' . esc_html( $meta_count ) . ' tracking values across ' . esc_html( $entry_count ) . ' entries, plus ' . esc_html( $form_count ) . ' form configurations and all included settings.</p></div>';
        }
        $access_owner_id = $this->access_owner_id();
        $access_mode = $this->access_mode();
        $administrators = get_users( [ 'role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC' ] );
        $current_owner = $access_owner_id ? $this->valid_access_owner( $access_owner_id ) : null;
        echo '<div class="cli-card cli-access-owner-settings"><h2>Plugin Access</h2><p>Choose how other WordPress administrators can see and use Lead Intelligence. Front-end tracking, internal notifications, and scheduled report delivery continue normally in every mode.</p>';
        if ( 'no_restrictions' === $access_mode ) echo '<p><strong>No Restrictions is active.</strong> Every administrator has full access.</p>';
        elseif ( $current_owner ) echo '<p><strong>Current owner:</strong> ' . esc_html( $current_owner->display_name . ' (' . $current_owner->user_login . ')' ) . '</p>';
        else echo '<p class="notice notice-warning inline"><strong>The saved owner account is unavailable.</strong> Hosting-level access is required to repair the saved owner option.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'cli_save_access_owner' );
        echo '<table class="form-table"><tbody><tr><th><label for="cli-access-mode">Access Mode</label></th><td><select id="cli-access-mode" name="cli_access_mode">';
        foreach ( [ 'no_restrictions' => 'No Restrictions', 'client' => 'Client Mode', 'stealth' => 'Stealth Mode' ] as $mode_value => $mode_label ) {
            echo '<option value="' . esc_attr( $mode_value ) . '" ' . selected( $access_mode, $mode_value, false ) . '>' . esc_html( $mode_label ) . '</option>';
        }
        echo '</select><p class="description"><strong>No Restrictions:</strong> every administrator has full access.<br><strong>Client Mode:</strong> other administrators can view reporting tabs, but configuration, source mappings, Scheduled Reports, and Settings remain owner-only.<br><strong>Stealth Mode:</strong> only the Access Owner can see or open Lead Intelligence.</p></td></tr>';
        echo '<tr><th><label for="cli-access-owner">Access Owner</label></th><td><select id="cli-access-owner" name="cli_access_owner_user_id"><option value="">Choose an administrator</option>';
        foreach ( $administrators as $administrator ) {
            if ( ! user_can( $administrator, 'manage_options' ) ) continue;
            echo '<option value="' . esc_attr( $administrator->ID ) . '" ' . selected( $access_owner_id, $administrator->ID, false ) . '>' . esc_html( $administrator->display_name . ' (' . $administrator->user_login . ')' ) . '</option>';
        }
        echo '</select><p class="description">An owner is required for Client and Stealth Mode. Selecting someone else transfers protected access immediately. The Access Owner and mode are site-specific and intentionally excluded from backups.</p></td></tr></tbody></table><p><button type="submit" class="button button-primary" name="cli_save_access_owner" value="1" onclick="return window.confirm(\'Save this Lead Intelligence access mode and owner? You may immediately lose protected access if another owner is selected.\');">Save Plugin Access</button></p></form></div>';
        $client_budget = $this->client_30_day_budget();
        echo '<div class="cli-card cli-budget-settings"><h2>Client Lead Acquisition Budget</h2><p>Enter the client advertising budget for a 30-day period. Filtered dashboards and PDF reports prorate this amount by calendar day and divide it by paid leads to calculate average cost per lead.</p><form method="post">';
        wp_nonce_field( 'cli_save_client_budget' );
        echo '<table class="form-table"><tbody><tr><th><label for="cli-client-30-day-budget">Client 30-Day Budget</label></th><td><span class="cli-currency-input"><span>$</span><input type="number" min="0" max="999999999.99" step="0.01" inputmode="decimal" id="cli-client-30-day-budget" name="cli_client_30_day_budget" value="' . esc_attr( $client_budget > 0 ? number_format( $client_budget, 2, '.', '' ) : '' ) . '" placeholder="0.00"></span><p class="description">Leave blank or enter 0 to disable budget-based CPL reporting.</p></td></tr></tbody></table><p><button type="submit" class="button button-primary" name="cli_save_client_budget" value="1">Save Client Budget</button></p></form></div>';
        echo '<div class="cli-card"><h2>Current Defaults</h2><ul><li>Attribution retention: 90 days</li><li>Session timeout: 30 minutes</li><li>First-touch and last-touch attribution: enabled</li><li>Customer/autoresponder notifications: always excluded</li></ul></div>';
        echo '<div class="cli-card"><h2>Per-Form Controls</h2><p>Open a form, then go to <strong>Settings &rarr; Form Settings &rarr; Lead Intelligence</strong>. You can disable attribution tracking for the form or disable attribution in its internal notification emails.</p></div>';
        $mappings = $this->get_google_campaign_mappings();
        echo '<div class="cli-card"><h2>Google Ads Campaign Mapping</h2><p>Give captured Google Ads campaign IDs friendly names. Reports will keep the original ID visible for verification.</p><form method="post">';
        wp_nonce_field( 'cli_save_campaign_mappings' );
        echo '<table class="widefat striped cli-mapping-table"><thead><tr><th>Campaign ID</th><th>Friendly Name</th><th></th></tr></thead><tbody id="cli-campaign-map-rows">';
        if ( ! $mappings ) $mappings = [ '' => '' ];
        foreach ( $mappings as $campaign_id => $campaign_name ) {
            echo '<tr><td><input type="text" name="cli_campaign_map_id[]" value="' . esc_attr( $campaign_id ) . '" class="regular-text code" placeholder="20058424758"></td><td><input type="text" name="cli_campaign_map_name[]" value="' . esc_attr( $campaign_name ) . '" class="regular-text" placeholder="Commercial Search"></td><td><button type="button" class="button-link-delete cli-remove-map-row">Remove</button></td></tr>';
        }
        echo '</tbody></table><p><button type="button" class="button" id="cli-add-map-row">Add Mapping</button> <button type="submit" class="button button-primary" name="cli_save_campaign_mappings" value="1">Save Campaign Mappings</button></p></form></div>';
        echo '<div class="cli-card"><h2>Recalculate Historical Reports</h2><p>Preview how saved evidence is interpreted by Attribution Catalog v' . esc_html( CLI_CATALOG_VERSION ) . '. Recalculation clears reporting caches only; Gravity Forms entries and Lead Intelligence metadata remain unchanged.</p><table class="form-table"><tr><th><label for="cli-reclass-days">Date Range</label></th><td><select id="cli-reclass-days"><option value="30">Last 30 days</option><option value="90" selected>Last 90 days</option><option value="180">Last 180 days</option><option value="365">Last 365 days</option><option value="0">All time</option></select></td></tr><tr><th>Scope</th><td><label><input type="checkbox" id="cli-reclass-legacy-only" value="1" checked> Preview changed, legacy, or non-canonical classifications</label></td></tr></table><p><button type="button" class="button button-primary" id="cli-preview-reclass">Preview Report Recalculation</button> <button type="button" class="button" id="cli-apply-reclass" disabled>Recalculate Reports</button></p><pre id="cli-reclass-output">No preview has been run.</pre></div>';
        echo '<div class="cli-card"><h2>Unknown Source Reporting</h2><p>Choose whether blank and Unknown historical sources are included in report totals. This preference never changes Gravity Forms entries.</p><p><button type="button" class="button button-secondary" id="cli-clean-unknowns">Exclude Unknowns from Reports</button> <button type="button" class="button" id="cli-restore-cleaned">Include Unknowns in Reports</button></p><pre id="cli-clean-output">Current policy: ' . esc_html( 'exclude' === get_option( 'cli_reporting_unknown_policy', 'include' ) ? 'Exclude Unknowns' : 'Include Unknowns' ) . '.</pre></div>';
        echo '<div class="cli-card"><h2>Backup and Restore</h2><p>Download a complete JSON backup of Lead Intelligence settings, form controls, and plugin-owned tracking metadata. Restoring never changes submitted Gravity Forms field values. Full tracking restoration is restricted to the same WordPress site and matches data by Gravity Forms entry ID.</p>';
        echo '<div class="cli-backup-actions"><form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cli_export_backup">';
        wp_nonce_field( 'cli_export_backup' );
        echo '<button type="submit" class="button button-primary">Export All Tracking Data and Settings</button></form>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(\'Validate and restore this Lead Intelligence backup? Submitted Gravity Forms fields will not be changed.\');"><input type="hidden" name="action" value="cli_import_backup">';
        wp_nonce_field( 'cli_import_backup' );
        echo '<label for="cli-backup-file" class="screen-reader-text">Lead Intelligence backup file</label><input type="file" id="cli-backup-file" name="cli_backup_file" accept="application/json,.json" required> <button type="submit" class="button">Validate and Import Backup</button></form></div></div>';
        $this->page_footer();
    }


    private function sanitize_client_budget( $value ) {
        if ( is_string( $value ) ) $value = str_replace( [ '$', ',', ' ' ], '', trim( $value ) );
        if ( ! is_numeric( $value ) ) return 0.0;
        return min( 999999999.99, max( 0.0, round( (float) $value, 2 ) ) );
    }

    private function client_30_day_budget() {
        return $this->sanitize_client_budget( get_option( 'cli_client_30_day_budget', 0 ) );
    }

    private function cost_per_lead_calculation( $period, $paid_leads ) {
        $budget_30 = $this->client_30_day_budget();
        $paid_leads = max( 0, (int) $paid_leads );
        $result = [
            'available' => false,
            'reason' => '',
            'budget_30' => $budget_30,
            'days' => null,
            'period_budget' => null,
            'paid_leads' => $paid_leads,
            'cost_per_lead' => null,
        ];
        if ( $budget_30 <= 0 ) {
            $result['reason'] = 'no_budget';
            return $result;
        }
        if ( empty( $period['start_local'] ) || empty( $period['end_local'] ) || ! ( $period['start_local'] instanceof DateTimeInterface ) || ! ( $period['end_local'] instanceof DateTimeInterface ) ) {
            $result['reason'] = 'unbounded_period';
            return $result;
        }
        $start = new DateTimeImmutable( $period['start_local']->format( 'Y-m-d' ) . ' 00:00:00', wp_timezone() );
        $end = new DateTimeImmutable( $period['end_local']->format( 'Y-m-d' ) . ' 00:00:00', wp_timezone() );
        if ( $start > $end ) {
            $swap = $start;
            $start = $end;
            $end = $swap;
        }
        $days = max( 1, (int) $start->diff( $end )->format( '%a' ) + 1 );
        $period_budget = ( $budget_30 * $days ) / 30;
        $result['days'] = $days;
        $result['period_budget'] = $period_budget;
        if ( $paid_leads < 1 ) {
            $result['reason'] = 'no_paid_leads';
            return $result;
        }
        $result['available'] = true;
        $result['cost_per_lead'] = $period_budget / $paid_leads;
        return $result;
    }

    private function backup_option_keys() {
        return [
            'cli_source_mappings', 'cli_google_campaign_mappings', 'cli_reporting_unknown_policy', 'cli_client_30_day_budget',
            'cli_scheduled_report_settings', 'cli_report_history', 'cli_report_storage_version',
            'cli_unknown_cleanup_last_run', 'cli_reclassification_last_run',
        ];
    }

    private function plugin_tracking_meta_keys() {
        return [
            'cli_lead_source', 'cli_channel', 'cli_legacy_source', 'cli_first_source', 'cli_first_channel',
            'cli_last_source', 'cli_last_channel', 'cli_campaign', 'cli_campaign_label', 'cli_campaign_id',
            'cli_campaign_source', 'cli_campaign_medium', 'cli_campaign_content', 'cli_campaign_term',
            'cli_campaign_origin', 'cli_medium', 'cli_referrer', 'cli_external_referrer', 'cli_landing_page',
            'cli_conversion_page', 'cli_previous_page', 'cli_pages_viewed', 'cli_unique_pages_viewed',
            'cli_customer_journey', 'cli_time_on_site', 'cli_click_id', 'cli_attribution_status',
            'cli_attribution_rule', 'cli_attribution_evidence', 'cli_customer_evidence', 'cli_lead_state',
            'cli_device', 'cli_browser', 'cli_payload', 'cli_capture_mode', 'cli_reclassification_history',
            'cli_reclassified_at', 'cli_reclassified_catalog',
        ];
    }

    private function backup_form_settings() {
        $backup = [];
        if ( ! class_exists( 'GFAPI' ) ) return $backup;
        foreach ( (array) GFAPI::get_forms() as $form ) {
            $form_id = (int) ( $form['id'] ?? 0 );
            if ( ! $form_id ) continue;
            $item = [
                'title' => sanitize_text_field( (string) ( $form['title'] ?? '' ) ),
                'tracking_enabled' => isset( $form['cli_tracking_enabled'] ) ? (string) $form['cli_tracking_enabled'] : '1',
                'email_attribution_enabled' => isset( $form['cli_email_attribution_enabled'] ) ? (string) $form['cli_email_attribution_enabled'] : '1',
                'notifications' => [],
            ];
            foreach ( (array) ( $form['notifications'] ?? [] ) as $notification_id => $notification ) {
                $settings = [];
                if ( array_key_exists( 'cli_attribution_email_mode', $notification ) ) $settings['cli_attribution_email_mode'] = (string) $notification['cli_attribution_email_mode'];
                if ( array_key_exists( 'cli_include_attribution', $notification ) ) $settings['cli_include_attribution'] = $notification['cli_include_attribution'];
                if ( $settings ) $item['notifications'][ sanitize_key( (string) $notification_id ) ] = $settings;
            }
            $backup[ $form_id ] = $item;
        }
        return $backup;
    }

    private function backup_entry_meta() {
        $backup = [];
        $keys = $this->plugin_tracking_meta_keys();
        global $wpdb;
        $table = '';
        if ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_entry_meta_table_name' ) ) $table = GFFormsModel::get_entry_meta_table_name();
        elseif ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_lead_meta_table_name' ) ) $table = GFFormsModel::get_lead_meta_table_name();
        if ( $wpdb && $table && preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql = $wpdb->prepare( "SELECT entry_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ({$placeholders}) ORDER BY entry_id ASC, id ASC", $keys );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $entry_id = absint( $row['entry_id'] ?? 0 );
                    $key = (string) ( $row['meta_key'] ?? '' );
                    if ( $entry_id && in_array( $key, $keys, true ) ) $backup[ $entry_id ][ $key ] = $row['meta_value'];
                }
                return $backup;
            }
        }
        if ( ! class_exists( 'GFAPI' ) ) return $backup;
        foreach ( (array) GFAPI::get_forms() as $form ) {
            $offset = 0;
            do {
                $entries = GFAPI::get_entries( (int) $form['id'], [], null, [ 'offset'=>$offset, 'page_size'=>200 ] );
                if ( is_wp_error( $entries ) || ! $entries ) break;
                foreach ( $entries as $entry ) {
                    $entry_id = absint( rgar( $entry, 'id' ) );
                    foreach ( $keys as $key ) {
                        $value = gform_get_meta( $entry_id, $key );
                        if ( '' !== (string) $value ) $backup[ $entry_id ][ $key ] = $value;
                    }
                }
                $offset += count( $entries );
            } while ( count( $entries ) === 200 );
        }
        return $backup;
    }

    public function export_backup() {
        $this->require_plugin_management_access( __( 'Contact the Lead Intelligence Access Owner to export complete plugin data.', 'calico-lead-intelligence' ) );
        check_admin_referer( 'cli_export_backup' );
        $settings = [];
        foreach ( $this->backup_option_keys() as $key ) {
            if ( false !== get_option( $key, false ) ) $settings[ $key ] = get_option( $key );
        }
        $package = [
            'product' => 'calico-lead-intelligence',
            'schema' => 1,
            'plugin_version' => CLI_VERSION,
            'catalog_version' => CLI_CATALOG_VERSION,
            'exported_at_utc' => gmdate( 'c' ),
            'site_url' => home_url( '/' ),
            'settings' => $settings,
            'form_settings' => $this->backup_form_settings(),
            'entry_meta' => $this->backup_entry_meta(),
        ];
        $json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( false === $json ) wp_die( esc_html__( 'The backup could not be encoded.', 'calico-lead-intelligence' ) );
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="calico-lead-intelligence-backup-' . wp_date( 'Y-m-d-His', current_time( 'timestamp' ) ) . '.json"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json;
        exit;
    }

    public function import_backup() {
        $this->require_plugin_management_access( __( 'Contact the Lead Intelligence Access Owner to restore plugin data.', 'calico-lead-intelligence' ) );
        check_admin_referer( 'cli_import_backup' );
        $file = $_FILES['cli_backup_file'] ?? null;
        if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) wp_die( esc_html__( 'Select a valid Lead Intelligence JSON backup.', 'calico-lead-intelligence' ) );
        $size = (int) ( $file['size'] ?? 0 );
        if ( $size < 2 || $size > 100 * 1024 * 1024 ) wp_die( esc_html__( 'The backup must be smaller than 100 MB.', 'calico-lead-intelligence' ) );
        $raw = file_get_contents( (string) $file['tmp_name'] );
        $package = json_decode( (string) $raw, true );
        if ( ! is_array( $package ) || 'calico-lead-intelligence' !== ( $package['product'] ?? '' ) || 1 !== (int) ( $package['schema'] ?? 0 ) ) wp_die( esc_html__( 'This is not a supported Lead Intelligence backup.', 'calico-lead-intelligence' ) );
        if ( untrailingslashit( strtolower( (string) ( $package['site_url'] ?? '' ) ) ) !== untrailingslashit( strtolower( home_url( '/' ) ) ) ) wp_die( esc_html__( 'This backup belongs to a different WordPress site. Full tracking restoration is limited to the originating site.', 'calico-lead-intelligence' ) );

        $allowed_options = $this->backup_option_keys();
        foreach ( (array) ( $package['settings'] ?? [] ) as $key => $value ) {
            if ( ! in_array( $key, $allowed_options, true ) ) continue;
            if ( 'cli_reporting_unknown_policy' === $key ) $value = 'exclude' === $value ? 'exclude' : 'include';
            elseif ( 'cli_client_30_day_budget' === $key ) $value = $this->sanitize_client_budget( $value );
            else $value = $this->sanitize_recursive( $value );
            update_option( $key, $value, false );
        }

        $forms_restored = 0;
        if ( class_exists( 'GFAPI' ) ) {
            foreach ( (array) ( $package['form_settings'] ?? [] ) as $form_id => $saved ) {
                $form = GFAPI::get_form( absint( $form_id ) );
                if ( ! is_array( $form ) || ! is_array( $saved ) ) continue;
                if ( isset( $saved['title'] ) && (string) $saved['title'] !== (string) ( $form['title'] ?? '' ) ) continue;
                $form['cli_tracking_enabled'] = '0' === (string) ( $saved['tracking_enabled'] ?? '1' ) ? '0' : '1';
                $form['cli_email_attribution_enabled'] = '0' === (string) ( $saved['email_attribution_enabled'] ?? '1' ) ? '0' : '1';
                foreach ( (array) ( $saved['notifications'] ?? [] ) as $notification_id => $notification_settings ) {
                    if ( ! isset( $form['notifications'][ $notification_id ] ) || ! is_array( $notification_settings ) ) continue;
                    if ( array_key_exists( 'cli_attribution_email_mode', $notification_settings ) ) $form['notifications'][ $notification_id ]['cli_attribution_email_mode'] = 'yes' === strtolower( (string) $notification_settings['cli_attribution_email_mode'] ) ? 'yes' : 'no';
                    if ( array_key_exists( 'cli_include_attribution', $notification_settings ) ) $form['notifications'][ $notification_id ]['cli_include_attribution'] = $this->setting_is_enabled( $notification_settings['cli_include_attribution'] ) ? '1' : '0';
                }
                $updated = GFAPI::update_form( $form );
                if ( ! is_wp_error( $updated ) ) $forms_restored++;
            }
        }

        $allowed_meta = $this->plugin_tracking_meta_keys();
        $entries_restored = 0;
        $meta_restored = 0;
        if ( class_exists( 'GFAPI' ) ) {
            foreach ( (array) ( $package['entry_meta'] ?? [] ) as $entry_id => $metadata ) {
                $entry_id = absint( $entry_id );
                if ( ! $entry_id || ! is_array( $metadata ) ) continue;
                $entry = GFAPI::get_entry( $entry_id );
                if ( is_wp_error( $entry ) || ! is_array( $entry ) ) continue;
                $entry_changed = false;
                foreach ( $metadata as $key => $value ) {
                    if ( ! in_array( $key, $allowed_meta, true ) || ! is_scalar( $value ) ) continue;
                    $value = substr( (string) $value, 0, 131072 );
                    gform_update_meta( $entry_id, $key, $value );
                    $meta_restored++;
                    $entry_changed = true;
                }
                if ( $entry_changed ) $entries_restored++;
            }
        }
        $this->invalidate_reporting_cache();
        $this->schedule_next_report();
        $url = add_query_arg( [ 'page'=>'calico-lead-intelligence-settings', 'cli_backup_notice'=>'imported', 'entries'=>$entries_restored, 'meta'=>$meta_restored, 'forms'=>$forms_restored ], admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }
    public function render_scheduled_reports() {
        $this->require_plugin_access();
        if ( ! $this->current_user_can_manage_plugin() ) {
            $this->render_owner_contact_page( 'Scheduled Reports' );
            return;
        }
        if ( ! $this->pro_enabled() ) {
            $this->page_header( 'Scheduled Dashboard Reports', 'Pro testing unlock required.' );
            echo '<div class="cli-card"><h2>Activate Pro in Settings</h2><p>Ask the Access Owner to enter the temporary testing code in Lead Intelligence Settings. Existing report settings and history are retained; delivery is paused until Pro is activated.</p></div>';
            $this->page_footer();
            return;
        }
        $settings = $this->report_settings();
        $history = get_option( 'cli_report_history', [] );
        $next = wp_next_scheduled( 'cli_scheduled_report_event' );
        $this->page_header( 'Scheduled Dashboard Reports', 'Automatically email a client-ready PDF summary of Lead Intelligence performance.' );
        $storage_error = get_option( 'cli_report_storage_error' );
        if ( $storage_error ) echo '<div class="notice notice-error"><p>' . esc_html( $storage_error ) . '</p></div>';
        if ( isset( $_GET['cli_report_notice'] ) ) {
            $notice = sanitize_key( wp_unslash( $_GET['cli_report_notice'] ) );
            $messages = [ 'saved' => 'Scheduled report settings saved.', 'sent' => 'Test report generated and sent.', 'failed' => 'The report could not be sent. Check the recipient addresses and WordPress mail configuration.' ];
            if ( isset( $messages[$notice] ) ) echo '<div class="notice ' . ( 'failed' === $notice ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[$notice] ) . '</p></div>';
        }
        echo '<div class="cli-card cli-pro-card"><div class="cli-pro-heading"><div><span class="cli-pro-badge">PRO</span><h2>Scheduled PDF Reports</h2></div><p>Temporary Pro testing access is active on this site.</p></div></div>';
        echo '<div class="cli-card"><h2>Report Schedule</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'cli_save_scheduled_report' );
        echo '<input type="hidden" name="action" value="cli_save_scheduled_report">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Enable automatic reports</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Send this report automatically</label></td></tr>';
        echo '<tr><th><label for="cli-report-recipients">Recipients</label></th><td><textarea class="large-text" rows="3" id="cli-report-recipients" name="recipients" placeholder="owner@example.com, agency@example.com">' . esc_textarea( implode( ', ', $settings['recipients'] ) ) . '</textarea><p class="description">Separate multiple email addresses with commas or new lines.</p></td></tr>';
        echo '<tr><th><label for="cli-report-frequency">Frequency</label></th><td><select id="cli-report-frequency" name="frequency">';
        foreach ( [ 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly' ] as $key => $label ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $settings['frequency'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        echo '</select></td></tr>';
        echo '<tr><th><label for="cli-report-weekday">Weekly delivery day</label></th><td><select id="cli-report-weekday" name="weekday">';
        foreach ( $this->report_weekdays() as $num=>$day ) echo '<option value="' . esc_attr( $num ) . '" ' . selected( (int) $settings['weekday'], $num, false ) . '>' . esc_html( $day ) . '</option>';
        echo '</select><p class="description">The day the completed weekly report is emailed.</p></td></tr>';
        echo '<tr><th><label for="cli-report-week-start">Reporting week starts</label></th><td><select id="cli-report-week-start" name="week_start">';
        foreach ( $this->report_weekdays() as $num=>$day ) echo '<option value="' . esc_attr( $num ) . '" ' . selected( (int) $settings['week_start'], $num, false ) . '>' . esc_html( $day ) . '</option>';
        echo '</select><p class="description">Weekly reports cover the previous completed seven-day week. Sunday produces a Sunday-Saturday reporting window.</p></td></tr>';
        echo '<tr><th><label for="cli-report-day">Monthly send day</label></th><td><input type="number" min="1" max="28" id="cli-report-day" name="day_of_month" value="' . esc_attr( $settings['day_of_month'] ) . '" class="small-text"><p class="description">Monthly reports cover the previous calendar month. Quarterly reports cover the previous completed quarter.</p></td></tr>';
        echo '<tr><th><label for="cli-report-time">Send time</label></th><td><input type="time" id="cli-report-time" name="send_time" value="' . esc_attr( $settings['send_time'] ) . '"> <span class="description">' . esc_html( wp_timezone_string() ?: 'WordPress timezone' ) . '</span></td></tr>';
        echo '<tr><th><label for="cli-reporting-range">Reporting window</label></th><td><select id="cli-reporting-range" name="reporting_range">';
        $range_options = $this->scheduled_reporting_range_options();
        if ( 'last_completed_quarter' === $settings['reporting_range'] ) $range_options = [ 'last_completed_quarter' => 'Last Completed Quarter (Legacy)' ] + $range_options;
        foreach ( $range_options as $key => $label ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $settings['reporting_range'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        echo '</select><p class="description">Controls the data included in the PDF and email summary. It does not change when the report is delivered.</p></td></tr>';
        echo '<tr id="cli-report-custom-dates"><th>Custom date range</th><td><label for="cli-report-start-date">From</label> <input type="date" id="cli-report-start-date" name="report_start_date" value="' . esc_attr( $settings['report_start_date'] ) . '"> <label for="cli-report-end-date">Through</label> <input type="date" id="cli-report-end-date" name="report_end_date" value="' . esc_attr( $settings['report_end_date'] ) . '"><p class="description">Dates are interpreted in the WordPress site timezone.</p></td></tr>';
        echo '<tr><th>Comparison</th><td>' . $this->comparison_control_html( $settings['comparison_mode'], 'scheduled-report', 'all_time' === $settings['reporting_range'] ) . '<p class="description" id="cli-report-compare-note">Comparison is unavailable for All Time reports.</p></td></tr>';
        echo '</tbody></table>';
        $logo_id = absint( $settings['logo_id'] ?? 0 );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        echo '<h3>Branding</h3><table class="form-table"><tbody>';
        echo '<tr><th><label for="cli-report-business">Business name</label></th><td><input type="text" class="regular-text" id="cli-report-business" name="business_name" value="' . esc_attr( $settings['business_name'] ) . '"></td></tr>';
        echo '<tr><th><label>Client logo</label></th><td><input type="hidden" id="cli-report-logo-id" name="logo_id" value="' . esc_attr( $logo_id ) . '"><div id="cli-report-logo-preview" class="cli-report-logo-preview">' . ( $logo_url ? '<img src="' . esc_url( $logo_url ) . '" alt="">' : '<span>No logo selected.</span>' ) . '</div><p><button type="button" class="button" id="cli-report-logo-select">Choose Logo</button> <button type="button" class="button-link-delete" id="cli-report-logo-remove"' . ( $logo_id ? '' : ' style="display:none"' ) . '>Remove Logo</button></p><p class="description">Displayed proportionally above the business name, up to 160 × 55 points (about 2.2 × 0.75 inches). A transparent PNG or JPEG around 600 × 200 pixels is recommended.</p></td></tr>';
        echo '<tr><th><label for="cli-report-agency">Prepared by <span class="cli-pro-badge">PRO</span></label></th><td><input type="text" class="regular-text" id="cli-report-agency" name="agency_name" value="' . esc_attr( $settings['agency_name'] ) . '" placeholder="Calico Design"><p class="description">Changes only the “Prepared by” agency name. The Calico Lead Intelligence generation line always remains in the report.</p></td></tr>';
        echo '<tr><th><label for="cli-report-footer">Custom footer</label></th><td><input type="text" class="regular-text" id="cli-report-footer" name="footer" value="' . esc_attr( $settings['footer'] ) . '"><p class="description">Optional note displayed at the end of the report.</p></td></tr>';
        echo '<tr><th><label for="cli-report-accent">Accent color</label></th><td><input type="color" id="cli-report-accent" name="accent_color" value="' . esc_attr( $settings['accent_color'] ) . '"><p class="description">Used for PDF section headers, summary cards, report panels, and paid-lead table headings.</p></td></tr>';
        echo '</tbody></table>';
        echo '<h3>Sections</h3><div class="cli-report-section-grid">';
        $section_labels = $this->report_section_labels();
        foreach ( $section_labels as $key => $label ) echo '<label><input type="checkbox" name="sections[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $settings['sections'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
        echo '</div><h3>Lead Details</h3><p><label><input type="checkbox" name="include_paid_leads" value="1" ' . checked( ! empty( $settings['include_paid_leads'] ), true, false ) . '> Include paid lead details at the end of the PDF</label></p><p class="description">Includes date, name, email, mapped source, channel, mapped campaign, and form for paid leads in the reporting period. This may expose personal information and is off by default.</p>';
        echo '<p><button type="submit" class="button button-primary">Save Schedule</button></p></form>';
        echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'cli_send_test_report' );
        echo '<input type="hidden" name="action" value="cli_send_test_report"><button type="submit" class="button">Generate &amp; Send Test Report</button> <span class="description">Uses the saved recipients and reporting window.</span></form>';
        echo '<script>(function(){var range=document.getElementById("cli-reporting-range"),custom=document.getElementById("cli-report-custom-dates"),choices=document.querySelectorAll("#cli-comparison-scheduled-report input"),note=document.getElementById("cli-report-compare-note");function sync(){var isCustom=range&&range.value==="custom",isAll=range&&range.value==="all_time";if(custom)custom.style.display=isCustom?"table-row":"none";choices.forEach(function(choice){choice.disabled=isAll;if(isAll)choice.checked=choice.value==="off";});if(note)note.style.display=isAll?"block":"none";}if(range){range.addEventListener("change",sync);sync();}})();</script>';
        if ( $next ) echo '<p><strong>Next scheduled run:</strong> ' . esc_html( wp_date( 'F j, Y g:i a', $next, wp_timezone() ) ) . '</p>';
        elseif ( ! empty( $settings['enabled'] ) ) echo '<p><strong>Next scheduled run:</strong> Pending reschedule. Save the settings again to rebuild the event.</p>';
        echo '</div>';

        echo '<div class="cli-card"><h2>Report History</h2><table class="widefat striped"><thead><tr><th>Created</th><th>Reporting Period</th><th>Recipients</th><th>Status</th><th>PDF</th></tr></thead><tbody>';
        if ( ! is_array( $history ) || ! $history ) echo '<tr><td colspan="5">No reports have been generated yet.</td></tr>';
        foreach ( array_slice( array_reverse( (array) $history ), 0, 20 ) as $item ) {
            $download = ! empty( $item['file'] ) ? wp_nonce_url( admin_url( 'admin-post.php?action=cli_download_report&file=' . rawurlencode( basename( $item['file'] ) ) ), 'cli_download_report' ) : '';
            echo '<tr><td>' . esc_html( $item['created'] ?? '' ) . '</td><td>' . esc_html( $item['period'] ?? '' ) . '</td><td>' . esc_html( implode( ', ', (array) ( $item['recipients'] ?? [] ) ) ) . '</td><td>' . esc_html( $item['status'] ?? '' ) . '</td><td>' . ( $download ? '<a class="button button-small" href="' . esc_url( $download ) . '">Download</a>' : '&mdash;' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->page_footer();
    }

    public function save_scheduled_report() {
        $this->require_pro();
        $this->require_plugin_management_access( 'Contact the Lead Intelligence Access Owner to change scheduled reports.' );
        check_admin_referer( 'cli_save_scheduled_report' );
        $raw_recipients = sanitize_textarea_field( wp_unslash( $_POST['recipients'] ?? '' ) );
        $recipients = $this->sanitize_recipients( $raw_recipients );
        $frequency = sanitize_key( wp_unslash( $_POST['frequency'] ?? 'monthly' ) );
        if ( ! in_array( $frequency, [ 'weekly', 'monthly', 'quarterly' ], true ) ) $frequency = 'monthly';
        $reporting_range = sanitize_key( wp_unslash( $_POST['reporting_range'] ?? 'last_completed_month' ) );
        $allowed_ranges = array_merge( array_keys( $this->scheduled_reporting_range_options() ), [ 'last_completed_quarter' ] );
        if ( ! in_array( $reporting_range, $allowed_ranges, true ) ) $reporting_range = 'last_completed_month';
        $report_start_date = $this->sanitize_reporting_date( wp_unslash( $_POST['report_start_date'] ?? '' ) );
        $report_end_date = $this->sanitize_reporting_date( wp_unslash( $_POST['report_end_date'] ?? '' ) );
        $comparison_mode = $this->sanitize_comparison_mode( wp_unslash( $_POST['cli_comparison'] ?? 'off' ) ) ?: 'off';
        if ( 'all_time' === $reporting_range ) $comparison_mode = 'off';
        $sections = isset( $_POST['sections'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sections'] ) ) : [];
        $sections = array_values( array_intersect( array_keys( $this->report_section_labels() ), $sections ) );
        $logo_id = absint( $_POST['logo_id'] ?? 0 );
        if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) $logo_id = 0;
        $settings = [
            'enabled' => ! empty( $_POST['enabled'] ),
            'recipients' => $recipients,
            'frequency' => $frequency,
            'weekday' => min( 7, max( 1, absint( $_POST['weekday'] ?? 1 ) ) ),
            'week_start' => min( 7, max( 1, absint( $_POST['week_start'] ?? 7 ) ) ),
            'day_of_month' => min( 28, max( 1, absint( $_POST['day_of_month'] ?? 1 ) ) ),
            'send_time' => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) ( $_POST['send_time'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_POST['send_time'] ) ) : '08:00',
            'reporting_range' => $reporting_range,
            'report_start_date' => $report_start_date,
            'report_end_date' => $report_end_date,
            'comparison_mode' => $comparison_mode,
            'compare' => 'off' !== $comparison_mode,
            'include_paid_leads' => ! empty( $_POST['include_paid_leads'] ),
            'business_name' => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? get_bloginfo( 'name' ) ) ),
            'logo_id' => $logo_id,
            'agency_name' => sanitize_text_field( wp_unslash( $_POST['agency_name'] ?? 'Calico Design' ) ),
            'footer' => sanitize_text_field( wp_unslash( $_POST['footer'] ?? '' ) ),
            'accent_color' => $this->sanitize_report_accent_color( wp_unslash( $_POST['accent_color'] ?? '#2271b1' ) ),
            'sections' => $sections ?: array_keys( $this->report_section_labels() ),
        ];
        update_option( 'cli_scheduled_report_settings', $settings, false );
        $this->schedule_next_report( $settings );
        wp_safe_redirect( admin_url( 'admin.php?page=calico-lead-intelligence-reports&cli_report_notice=saved' ) );
        exit;
    }

    public function send_test_report() {
        $this->require_pro();
        $this->require_plugin_management_access( 'Contact the Lead Intelligence Access Owner to send report tests.' );
        check_admin_referer( 'cli_send_test_report' );
        $settings = $this->report_settings();
        $ok = $this->generate_and_send_report( $settings, true );
        wp_safe_redirect( admin_url( 'admin.php?page=calico-lead-intelligence-reports&cli_report_notice=' . ( $ok ? 'sent' : 'failed' ) ) );
        exit;
    }

    public function run_scheduled_report() {
        $settings = $this->report_settings();
        if ( ! empty( $settings['enabled'] ) && $settings['recipients'] ) $this->generate_and_send_report( $settings, false );
        $this->schedule_next_report( $settings );
    }

    public function download_report() {
        $this->require_plugin_management_access( 'Contact the Lead Intelligence Access Owner to download scheduled reports.' );
        check_admin_referer( 'cli_download_report' );
        $file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
        $path = $this->locate_report_path( $file );
        if ( ! $path ) wp_die( 'Report not found.' );
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }

    private function report_settings() {
        $defaults = [
            'enabled' => false,
            'recipients' => [ get_option( 'admin_email' ) ],
            'frequency' => 'monthly',
            'weekday' => 1,
            'week_start' => 7,
            'day_of_month' => 1,
            'send_time' => '08:00',
            'reporting_range' => 'last_completed_month',
            'report_start_date' => '',
            'report_end_date' => '',
            'comparison_mode' => 'previous',
            'compare' => true,
            'include_paid_leads' => false,
            'business_name' => get_bloginfo( 'name' ),
            'logo_id' => 0,
            'agency_name' => 'Calico Design',
            'footer' => '',
            'accent_color' => '#2271b1',
            'sections' => array_keys( $this->report_section_labels() ),
        ];
        $saved = get_option( 'cli_scheduled_report_settings', [] );
        $saved = is_array( $saved ) ? $saved : [];
        if ( ! array_key_exists( 'agency_name', $saved ) && preg_match( '/^Prepared by\s+(.+)$/i', trim( (string) ( $saved['footer'] ?? '' ) ), $legacy_agency ) ) {
            $saved['agency_name'] = sanitize_text_field( $legacy_agency[1] );
            $saved['footer'] = '';
        }
        if ( ! array_key_exists( 'reporting_range', $saved ) ) {
            $legacy_frequency = sanitize_key( $saved['frequency'] ?? $defaults['frequency'] );
            $saved['reporting_range'] = 'weekly' === $legacy_frequency ? 'last_completed_week' : ( 'quarterly' === $legacy_frequency ? 'last_completed_quarter' : 'last_completed_month' );
        }
        if ( ! array_key_exists( 'comparison_mode', $saved ) ) $saved['comparison_mode'] = ! empty( $saved['compare'] ) ? 'previous' : 'off';
        $settings = wp_parse_args( $saved, $defaults );
        $allowed_ranges = array_merge( array_keys( $this->scheduled_reporting_range_options() ), [ 'last_completed_quarter' ] );
        $settings['reporting_range'] = sanitize_key( $settings['reporting_range'] ?? 'last_completed_month' );
        if ( ! in_array( $settings['reporting_range'], $allowed_ranges, true ) ) $settings['reporting_range'] = 'last_completed_month';
        $settings['report_start_date'] = $this->sanitize_reporting_date( $settings['report_start_date'] ?? '' );
        $settings['report_end_date'] = $this->sanitize_reporting_date( $settings['report_end_date'] ?? '' );
        $settings['comparison_mode'] = $this->sanitize_comparison_mode( $settings['comparison_mode'] ?? '' ) ?: ( ! empty( $settings['compare'] ) ? 'previous' : 'off' );
        if ( 'all_time' === $settings['reporting_range'] ) $settings['comparison_mode'] = 'off';
        $settings['compare'] = 'off' !== $settings['comparison_mode'];
        $settings['recipients'] = array_values( array_filter( array_map( 'sanitize_email', (array) $settings['recipients'] ) ) );
        $settings['weekday'] = min( 7, max( 1, absint( $settings['weekday'] ?? 1 ) ) );
        $settings['week_start'] = min( 7, max( 1, absint( $settings['week_start'] ?? 7 ) ) );
        $settings['include_paid_leads'] = ! empty( $settings['include_paid_leads'] );
        $settings['logo_id'] = absint( $settings['logo_id'] ?? 0 );
        $settings['agency_name'] = sanitize_text_field( $settings['agency_name'] ?? 'Calico Design' ) ?: 'Calico Design';
        $settings['footer'] = sanitize_text_field( $settings['footer'] ?? '' );
        $settings['accent_color'] = $this->sanitize_report_accent_color( $settings['accent_color'] ?? '#2271b1' );
        $settings['sections'] = array_values( array_intersect( array_keys( $this->report_section_labels() ), (array) $settings['sections'] ) );
        return $settings;
    }

    private function report_section_labels() {
        return [
            'sources' => 'Lead Sources',
            'channels' => 'Lead Channels',
            'campaigns' => 'Top Campaigns',
            'states' => 'Lead States',
            'paid_states' => 'Paid Lead States',
            'landing_pages' => 'Top Landing Pages',
            'conversion_pages' => 'Top Conversion Pages',
            'viewed_pages' => 'Most Viewed Pages',
        ];
    }

    private function scheduled_reporting_range_options() {
        return [
            'rolling_30' => 'Last 30 Days',
            'rolling_7' => 'Last 7 Days',
            'last_completed_month' => 'Last Completed Month',
            'last_completed_week' => 'Last Completed Week',
            'year_to_date' => 'This Year',
            'all_time' => 'All Time',
            'custom' => 'Custom Date Range',
        ];
    }

    private function report_weekdays() {
        return [ 1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday' ];
    }

    private function sanitize_recipients( $raw ) {
        $parts = preg_split( '/[\s,;]+/', (string) $raw );
        $emails = [];
        foreach ( $parts as $part ) { $email = sanitize_email( $part ); if ( $email && is_email( $email ) ) $emails[$email] = $email; }
        return array_values( $emails );
    }

    private function schedule_next_report( $settings = null ) {
        wp_clear_scheduled_hook( 'cli_scheduled_report_event' );
        if ( ! $this->pro_enabled() ) return;
        $settings = is_array( $settings ) ? $settings : $this->report_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['recipients'] ) ) return;
        $timezone = wp_timezone();
        $now = new DateTimeImmutable( 'now', $timezone );
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['send_time'] ) );
        if ( 'weekly' === $settings['frequency'] ) {
            $target = $now->setTime( $hour, $minute, 0 );
            $days = ( (int) $settings['weekday'] - (int) $target->format( 'N' ) + 7 ) % 7;
            $target = $target->modify( '+' . $days . ' days' );
            if ( $target <= $now ) $target = $target->modify( '+7 days' );
        } elseif ( 'quarterly' === $settings['frequency'] ) {
            $month = (int) $now->format( 'n' );
            $next_quarter_month = ( floor( ( $month - 1 ) / 3 ) + 1 ) * 3 + 1;
            $year = (int) $now->format( 'Y' );
            if ( $next_quarter_month > 12 ) { $next_quarter_month -= 12; $year++; }
            $target = new DateTimeImmutable( sprintf( '%04d-%02d-%02d %02d:%02d:00', $year, $next_quarter_month, (int) $settings['day_of_month'], $hour, $minute ), $timezone );
            if ( $target <= $now ) $target = $target->modify( '+3 months' );
        } else {
            $target = new DateTimeImmutable( sprintf( '%s-%02d %02d:%02d:00', $now->format( 'Y-m' ), (int) $settings['day_of_month'], $hour, $minute ), $timezone );
            if ( $target <= $now ) $target = $target->modify( '+1 month' );
        }
        wp_schedule_single_event( $target->getTimestamp(), 'cli_scheduled_report_event' );
    }

    private function scheduled_report_period( $settings, $test = false ) {
        $settings = is_array( $settings ) ? $settings : [ 'reporting_range' => (string) $settings ];
        $range = sanitize_key( $settings['reporting_range'] ?? 'last_completed_month' );
        $allowed = array_merge( array_keys( $this->scheduled_reporting_range_options() ), [ 'last_completed_quarter' ] );
        if ( ! in_array( $range, $allowed, true ) ) $range = 'last_completed_month';

        $timezone = wp_timezone();
        $now = apply_filters( 'cli_scheduled_report_now', new DateTimeImmutable( 'now', $timezone ), $settings, $test );
        if ( ! $now instanceof DateTimeImmutable ) $now = new DateTimeImmutable( 'now', $timezone );
        $now = $now->setTimezone( $timezone );
        $today = $now->setTime( 0, 0, 0 );
        $start = null;
        $end = $today->setTime( 23, 59, 59 );
        $label = '';
        $key = $range;

        switch ( $range ) {
            case 'rolling_7':
                $start = $today->modify( '-6 days' );
                $label = 'Last 7 Days';
                break;
            case 'rolling_30':
                $start = $today->modify( '-29 days' );
                $label = 'Last 30 Days';
                break;
            case 'last_completed_week':
                $week_start = min( 7, max( 1, absint( $settings['week_start'] ?? 7 ) ) );
                $days_since_start = ( (int) $today->format( 'N' ) - $week_start + 7 ) % 7;
                $current_week_start = $today->modify( '-' . $days_since_start . ' days' );
                $start = $current_week_start->modify( '-7 days' );
                $end = $current_week_start->modify( '-1 second' );
                $label = 'Week ending ' . wp_date( 'F j, Y', $end->getTimestamp(), $timezone );
                $key = 'last_week';
                break;
            case 'last_completed_quarter':
                $current_quarter_start_month = ( floor( ( (int) $now->format( 'n' ) - 1 ) / 3 ) * 3 ) + 1;
                $current_start = $now->setDate( (int) $now->format( 'Y' ), $current_quarter_start_month, 1 )->setTime( 0, 0, 0 );
                $start = $current_start->modify( '-3 months' );
                $end = $current_start->modify( '-1 second' );
                $label = 'Q' . (int) ceil( (int) $start->format( 'n' ) / 3 ) . ' ' . $start->format( 'Y' );
                $key = 'custom_months';
                break;
            case 'year_to_date':
                $start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
                $label = 'This Year';
                break;
            case 'all_time':
                $label = 'All Time';
                break;
            case 'custom':
                $from = $this->sanitize_reporting_date( $settings['report_start_date'] ?? '' );
                $to = $this->sanitize_reporting_date( $settings['report_end_date'] ?? '' );
                if ( ! $from && ! $to ) {
                    $range = 'last_completed_month';
                    $key = 'last_month';
                    $start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                    $end = $start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
                    $label = wp_date( 'F Y', $start->getTimestamp(), $timezone );
                    break;
                }
                if ( ! $from ) $from = $to;
                if ( ! $to ) $to = $from;
                $start = new DateTimeImmutable( $from . ' 00:00:00', $timezone );
                $end = new DateTimeImmutable( $to . ' 23:59:59', $timezone );
                if ( $start > $end ) {
                    $swap = $start;
                    $start = $end->setTime( 0, 0, 0 );
                    $end = $swap->setTime( 23, 59, 59 );
                }
                $label = 'Custom Range';
                break;
            case 'last_completed_month':
            default:
                $start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                $end = $start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
                $label = wp_date( 'F Y', $start->getTimestamp(), $timezone );
                $key = 'last_month';
                break;
        }

        $display = $start
            ? wp_date( 'M j, Y', $start->getTimestamp(), $timezone ) . ' - ' . wp_date( 'M j, Y', $end->getTimestamp(), $timezone )
            : 'All recorded leads';

        return [
            'key' => $key,
            'range' => $range,
            'label' => $label,
            'display' => $display,
            'start_local' => $start,
            'end_local' => $end,
            'start_utc' => $start ? $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
            'end_utc' => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }

    private function report_paid_lead_rows( $period ) {
        $forms = GFAPI::get_forms( true );
        if ( ! $forms ) return [];
        $result = $this->filtered_live_leads(
            $forms,
            [ 'source'=>'', 'channel'=>'Paid', 'form_id'=>0, 'include_unknowns'=>true ],
            $period['start_utc'],
            $period['end_utc'],
            0
        );
        return (array) ( $result['rows'] ?? [] );
    }

    private function generate_and_send_report( $settings, $test = false ) {
        if ( ! $this->pro_enabled() ) return false;
        if ( empty( $settings['recipients'] ) || ! class_exists( 'GFAPI' ) ) return false;
        $this->maybe_migrate_legacy_reports();
        $period = $this->scheduled_report_period( $settings, $test );
        $previous_period = 'all_time' !== ( $period['range'] ?? '' ) ? $this->comparison_reporting_period( $period, $settings['comparison_mode'] ?? 'off' ) : null;
        $report_periods = [ 'current' => [ 'start_utc' => $period['start_utc'], 'end_utc' => $period['end_utc'] ] ];
        if ( $previous_period ) $report_periods['previous'] = [ 'start_utc' => $previous_period['start_utc'], 'end_utc' => $previous_period['end_utc'] ];
        $report_stats = $this->get_stats_for_periods( $report_periods );
        $stats = $report_stats['current'];
        $previous = $previous_period ? $report_stats['previous'] : null;
        $paid_leads = ! empty( $settings['include_paid_leads'] ) ? $this->report_paid_lead_rows( $period ) : [];
        $pdf = $this->build_report_pdf( $settings, $period, $stats, $previous_period, $previous, $paid_leads );
        $dir = $this->reports_directory();
        if ( ! $this->ensure_reports_directory( $dir['path'] ) ) return false;
        $slug = sanitize_title( $settings['business_name'] ?: get_bloginfo( 'name' ) );
        $period_slug = $period['start_local']
            ? $period['start_local']->format( 'Y-m-d' ) . '-to-' . $period['end_local']->format( 'Y-m-d' )
            : 'all-time';
        $filename = $slug . '-lead-report-' . $period_slug . '-' . wp_generate_password( 6, false, false ) . '.pdf';
        $path = trailingslashit( $dir['path'] ) . $filename;
        if ( false === file_put_contents( $path, $pdf ) ) return false;
        $subject = ( $test ? '[Test] ' : '' ) . ( $settings['business_name'] ?: get_bloginfo( 'name' ) ) . ' Lead Report - ' . $period['label'];
        $trend = $previous ? $this->percentage_change( $stats['total'], $previous['total'] ) : null;
        $body = '<p>Your lead report summary for <strong>' . esc_html( $period['display'] ) . '</strong> is below.</p>';
        $body .= '<ul><li><strong>' . (int) $stats['total'] . '</strong> total leads</li><li><strong>' . (int) $stats['paid_total'] . '</strong> paid leads</li>';
        if ( $trend ) $body .= '<li><strong>' . esc_html( $trend['label'] ) . '</strong> ' . esc_html( strtolower( $this->comparison_mode_label( $settings['comparison_mode'] ?? 'previous' ) ) ) . '</li>';
        $body .= '</ul><p>The attached PDF includes a detailed report of your leads for this time period.' . ( ! empty( $settings['include_paid_leads'] ) ? ' Paid lead contact details are included and should be handled securely.' : '' ) . '</p>';
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $sent = wp_mail( $settings['recipients'], $subject, $body, $headers, [ $path ] );
        $history = get_option( 'cli_report_history', [] );
        if ( ! is_array( $history ) ) $history = [];
        $history[] = [ 'created' => current_time( 'mysql' ), 'period' => $period['display'], 'recipients' => $settings['recipients'], 'status' => $sent ? 'Sent' : 'Mail failed', 'file' => $filename ];
        if ( count( $history ) > 50 ) $history = array_slice( $history, -50 );
        update_option( 'cli_report_history', $history, false );
        return (bool) $sent;
    }

    /**
     * Convert the selected Media Library image into a compact RGB JPEG that
     * the dependency-free PDF writer can embed. Transparent pixels are
     * flattened onto white for predictable print output.
     */
    private function report_logo_image( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) return null;
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) return null;
        $bytes = @file_get_contents( $path );
        if ( false === $bytes || '' === $bytes || strlen( $bytes ) > 15 * MB_IN_BYTES ) return null;
        $size = @getimagesize( $path );
        if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) return null;
        $source_width = (int) $size[0];
        $source_height = (int) $size[1];

        if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) && $source_width * $source_height <= 30000000 ) {
            $source = @imagecreatefromstring( $bytes );
            if ( $source ) {
                $scale = min( 1200 / $source_width, 412 / $source_height, 1 );
                $width = max( 1, (int) round( $source_width * $scale ) );
                $height = max( 1, (int) round( $source_height * $scale ) );
                $canvas = imagecreatetruecolor( $width, $height );
                if ( $canvas ) {
                    $white = imagecolorallocate( $canvas, 255, 255, 255 );
                    imagefilledrectangle( $canvas, 0, 0, $width, $height, $white );
                    imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $width, $height, $source_width, $source_height );
                    ob_start();
                    $written = imagejpeg( $canvas, null, 90 );
                    $jpeg = ob_get_clean();
                    imagedestroy( $canvas );
                    imagedestroy( $source );
                    if ( $written && is_string( $jpeg ) && '' !== $jpeg ) return [ 'data' => $jpeg, 'width' => $width, 'height' => $height ];
                } else {
                    imagedestroy( $source );
                }
            }
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) return null;
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) return null;
        $editor->resize( 1200, 412, false );
        $temporary = wp_tempnam( 'cli-report-logo' );
        if ( ! $temporary ) return null;
        $jpeg_path = $temporary . '.jpg';
        $saved = $editor->save( $jpeg_path, 'image/jpeg' );
        $saved_path = ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ? $saved['path'] : '';
        $jpeg = $saved_path && is_readable( $saved_path ) ? @file_get_contents( $saved_path ) : false;
        $jpeg_size = $saved_path ? @getimagesize( $saved_path ) : false;
        if ( is_file( $temporary ) ) wp_delete_file( $temporary );
        if ( $saved_path && is_file( $saved_path ) ) wp_delete_file( $saved_path );
        if ( false === $jpeg || ! is_array( $jpeg_size ) || empty( $jpeg_size[0] ) || empty( $jpeg_size[1] ) ) return null;
        return [ 'data' => $jpeg, 'width' => (int) $jpeg_size[0], 'height' => (int) $jpeg_size[1] ];
    }
    private function build_report_pdf( $settings, $period, $stats, $previous_period, $previous, $paid_leads = [] ) {
        $pdf = new CLI_Simple_PDF();
        $business = $settings['business_name'] ?: get_bloginfo( 'name' );
        $agency = sanitize_text_field( $settings['agency_name'] ?? 'Calico Design' ) ?: 'Calico Design';
        $accent = $this->sanitize_report_accent_color( $settings['accent_color'] ?? '#2271b1' );
        $generated = 'Generated by Calico Lead Intelligence v' . CLI_VERSION . ' on ' . wp_date( 'F j, Y g:i a', current_time( 'timestamp' ), wp_timezone() );
        $logo = $this->report_logo_image( $settings['logo_id'] ?? 0 );
        if ( $logo ) $pdf->logo_header( $logo['data'], $logo['width'], $logo['height'], '', 160, 55 );
        $pdf->line( 'Prepared by ' . $agency, 9, false, 0, 13 );
        $pdf->line( $generated, 8, false, 0, 12 );
        $pdf->spacer( 10 );
        $pdf->line( $business, 20, true, 0, 24 );
        $pdf->line( 'Lead Intelligence Dashboard Summary', 14, true, 0, 20 );
        $pdf->line( $period['label'] . ' | ' . $period['display'], 10, false );
        $comparison_short_label = 'year_over_year' === ( $settings['comparison_mode'] ?? '' ) ? 'same period last year' : 'previous period';
        if ( $previous_period ) $pdf->line( ucfirst( $this->comparison_mode_label( $settings['comparison_mode'] ?? 'previous' ) ) . ': ' . $previous_period['display'], 9, false );
        $pdf->spacer( 14 );
        $pdf->section_header( 'Executive Summary', 'A clear view of lead volume, paid performance, and estimated acquisition cost.', $accent );
        $cpl = $this->cost_per_lead_calculation( $period, $stats['paid_total'] );
        $paid_share = $stats['total'] ? round( ( (int) $stats['paid_total'] / (int) $stats['total'] ) * 100, 1 ) . '%' : '0%';
        $pdf->metric_cards( [
            [ 'value'=>(int) $stats['total'], 'label'=>'Total Leads' ],
            [ 'value'=>(int) $stats['paid_total'], 'label'=>'Paid Leads' ],
            [ 'value'=>$paid_share, 'label'=>'Paid Lead Share' ],
            [ 'value'=>$cpl['available'] ? '$' . number_format_i18n( $cpl['cost_per_lead'], 2 ) : 'Not set', 'label'=>'Est. Cost / Paid Lead' ],
        ], $accent );
        $this->pdf_metric_line( $pdf, 'Total Leads', $stats['total'], $previous ? $previous['total'] : null, $accent, $comparison_short_label );
        $this->pdf_metric_line( $pdf, 'Paid Leads', $stats['paid_total'], $previous ? $previous['paid_total'] : null, $accent, $comparison_short_label );
        $this->pdf_cost_per_lead_line( $pdf, $period, $stats['paid_total'], $accent );

        $labels = $this->report_section_labels();
        $panels = [];
        foreach ( $settings['sections'] as $section ) {
            if ( ! isset( $labels[$section] ) ) continue;
            $items = array_slice( (array) ( $stats[$section] ?? [] ), 0, 15, true );
            $previous_items = $previous ? (array) ( $previous[$section] ?? [] ) : [];
            $total = 'viewed_pages' === $section ? array_sum( $stats['viewed_pages'] ) : ( 'paid_states' === $section ? $stats['paid_total'] : $stats['total'] );
            $panel_items = [];
            $rank = 1;
            foreach ( $items as $name => $count ) {
                $share = $total ? round( ( $count / $total ) * 100, 1 ) : 0;
                $row = $name . ' - ' . $count . ' leads (' . $share . '%)';
                if ( $previous ) {
                    $trend = $this->percentage_change( $count, (int) ( $previous_items[$name] ?? 0 ) );
                    $row .= ' | ' . $trend['label'] . ' vs ' . $comparison_short_label;
                }
                $panel_items[] = $row;
                $rank++;
            }
            $panels[] = [ 'title'=>$labels[$section], 'items'=>$panel_items ];
        }
        foreach ( array_chunk( $panels, 2 ) as $panel_pair ) {
            if ( isset( $panel_pair[1] ) ) {
                $pdf->two_column_lists( $panel_pair[0]['title'], $panel_pair[0]['items'], $panel_pair[1]['title'], $panel_pair[1]['items'], $accent );
            } else {
                $pdf->list_panel( $panel_pair[0]['title'], $panel_pair[0]['items'], $accent );
            }
        }
        if ( ! empty( $settings['include_paid_leads'] ) ) {
            $pdf->spacer( 8 );
            $pdf->ensure_space( 124 );
            $pdf->section_header( 'Paid Lead Details', $period['display'] . ' | ' . count( $paid_leads ) . ' paid ' . ( 1 === count( $paid_leads ) ? 'lead' : 'leads' ), $accent );
            $pdf->line( 'Contains personal information. Handle and share this report securely.', 8, false, 0, 14 );
            $pdf->spacer( 5 );
            if ( $paid_leads ) {
                $table_rows = [];
                foreach ( $paid_leads as $lead ) {
                    $date = ! empty( $lead['date'] ) ? get_date_from_gmt( $lead['date'], 'Y-m-d H:i' ) : '';
                    $table_rows[] = [
                        $date,
                        $lead['name'] ?: '-',
                        $lead['email'] ?: '-',
                        $lead['source'] ?: '-',
                        $lead['channel'] ?: '-',
                        $lead['campaign'] ?: '-',
                        $lead['form'] ?: '-',
                    ];
                }
                $pdf->table( [ 'Date', 'Name', 'Email', 'Source', 'Channel', 'Campaign', 'Form' ], $table_rows, [ 70, 70, 105, 60, 45, 105, 61 ], 7, 9, $accent );
            } else {
                $pdf->line( 'No paid leads were recorded for this reporting period.', 10, false );
            }
        }
        if ( ! empty( $settings['footer'] ) ) { $pdf->rule(); $pdf->line( $settings['footer'], 9, false ); }
        return $pdf->output();
    }

    private function pdf_cost_per_lead_line( $pdf, $period, $paid_leads, $accent = '#2271b1' ) {
        $cpl = $this->cost_per_lead_calculation( $period, $paid_leads );
        if ( $cpl['available'] ) {
            $budget = '$' . number_format_i18n( $cpl['period_budget'], 2 );
            $cost = '$' . number_format_i18n( $cpl['cost_per_lead'], 2 );
            $budget_type = 30 === (int) $cpl['days'] ? 'Budget' : 'Prorated Budget';
            $pdf->result_card( 'Estimated Cost', 'Average Cost Per Lead Based on ' . $budget . ' ' . $budget_type . ': ' . $cost . ' per lead', $accent );
            return;
        }
        $message = 'no_budget' === $cpl['reason']
            ? 'no client 30-day budget is configured'
            : ( 'no_paid_leads' === $cpl['reason'] ? 'no paid leads were recorded for this period' : 'the selected reporting period has no defined start date' );
        $pdf->result_card( 'Estimated Cost', 'Average Cost Per Lead Based on Budget: Not available - ' . $message . '.', $accent );
    }

    private function pdf_metric_line( $pdf, $label, $current, $previous = null, $accent = '#2271b1', $comparison_label = 'previous period' ) {
        $text = (int) $current . ' leads during this reporting period.';
        if ( null !== $previous ) { $trend = $this->percentage_change( $current, $previous ); $text .= ' ' . $trend['label'] . ' vs ' . $comparison_label . '; comparison total: ' . (int) $previous . '.'; }
        $pdf->result_card( $label, $text, $accent );
    }

    private function sanitize_report_accent_color( $color ) {
        $color = strtolower( trim( sanitize_text_field( (string) $color ) ) );
        return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '#2271b1';
    }

    /**
     * Store reports outside the public document root. Hosts with a custom
     * filesystem layout can define CLI_PRIVATE_REPORTS_PATH or use the
     * cli_private_reports_path filter.
     */
    private function reports_directory() {
        $wordpress_root = untrailingslashit( wp_normalize_path( ABSPATH ) );
        $document_root = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false;
        $private_parent = $document_root ? dirname( wp_normalize_path( $document_root ) ) : dirname( $wordpress_root );
        $default = trailingslashit( $private_parent ) . 'calico-lead-intelligence-private/reports';
        $configured = defined( 'CLI_PRIVATE_REPORTS_PATH' ) ? CLI_PRIVATE_REPORTS_PATH : $default;
        $path = apply_filters( 'cli_private_reports_path', $configured, $default );
        return [ 'path' => untrailingslashit( wp_normalize_path( (string) $path ) ) ];
    }

    private function legacy_reports_directory() {
        $uploads = wp_upload_dir();
        return [ 'path' => trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'calico-lead-intelligence/reports' ];
    }

    private function ensure_reports_directory( $path ) {
        $path = untrailingslashit( wp_normalize_path( (string) $path ) );
        if ( $this->path_is_public( $path ) ) {
            update_option( 'cli_report_storage_error', 'The private report directory must be outside the public web root. Configure CLI_PRIVATE_REPORTS_PATH to a non-public writable directory.', false );
            return false;
        }
        if ( ! $path || ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) ) {
            update_option( 'cli_report_storage_error', 'The private report directory could not be created. Configure CLI_PRIVATE_REPORTS_PATH to a writable directory outside the public web root.', false );
            return false;
        }
        $this->protect_report_directory( $path );
        if ( ! is_writable( $path ) ) {
            update_option( 'cli_report_storage_error', 'The private report directory is not writable. Check its filesystem permissions or configure CLI_PRIVATE_REPORTS_PATH.', false );
            return false;
        }
        delete_option( 'cli_report_storage_error' );
        return true;
    }

    private function protect_report_directory( $path ) {
        if ( ! is_dir( $path ) ) return;
        $files = [
            'index.php' => "<?php\nhttp_response_code( 404 );\nexit;\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><directoryBrowse enabled=\"false\" /><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ( $files as $name => $contents ) {
            $file = trailingslashit( $path ) . $name;
            if ( ! file_exists( $file ) ) @file_put_contents( $file, $contents, LOCK_EX );
        }
    }

    public function maybe_migrate_legacy_reports() {
        if ( get_option( 'cli_report_storage_version' ) === CLI_REPORT_STORAGE_VERSION ) return;
        if ( function_exists( 'is_admin' ) && is_admin() && ! current_user_can( 'manage_options' ) ) return;

        $private = $this->reports_directory();
        if ( ! $this->ensure_reports_directory( $private['path'] ) ) return;
        $legacy = $this->legacy_reports_directory();
        if ( ! is_dir( $legacy['path'] ) ) {
            update_option( 'cli_report_storage_version', CLI_REPORT_STORAGE_VERSION, false );
            return;
        }

        $this->protect_report_directory( $legacy['path'] );
        $failed = [];
        $legacy_files = glob( trailingslashit( $legacy['path'] ) . '*.pdf' );
        foreach ( is_array( $legacy_files ) ? $legacy_files : [] as $legacy_file ) {
            $filename = sanitize_file_name( basename( $legacy_file ) );
            if ( ! $filename || '.pdf' !== strtolower( substr( $filename, -4 ) ) ) continue;
            $target = trailingslashit( $private['path'] ) . $filename;
            if ( is_file( $target ) ) {
                if ( hash_equals( hash_file( 'sha256', $legacy_file ), hash_file( 'sha256', $target ) ) ) @unlink( $legacy_file );
                if ( is_file( $legacy_file ) ) $failed[] = $filename;
                continue;
            }
            if ( @rename( $legacy_file, $target ) ) continue;
            if ( @copy( $legacy_file, $target ) ) {
                if ( hash_equals( hash_file( 'sha256', $legacy_file ), hash_file( 'sha256', $target ) ) ) {
                    @unlink( $legacy_file );
                } else {
                    @unlink( $target );
                }
            }
            if ( is_file( $legacy_file ) ) $failed[] = $filename;
        }

        if ( $failed ) {
            update_option( 'cli_report_storage_error', 'Some legacy report PDFs could not be moved out of the public uploads directory. Check filesystem permissions and retry.', false );
            return;
        }
        update_option( 'cli_report_storage_version', CLI_REPORT_STORAGE_VERSION, false );
    }

    private function locate_report_path( $file ) {
        $file = sanitize_file_name( (string) $file );
        if ( ! $file || '.pdf' !== strtolower( substr( $file, -4 ) ) || basename( $file ) !== $file ) return false;
        foreach ( [ $this->reports_directory(), $this->legacy_reports_directory() ] as $directory ) {
            $root = realpath( $directory['path'] );
            if ( ! $root ) continue;
            $candidate = realpath( trailingslashit( $root ) . $file );
            if ( $candidate && is_file( $candidate ) && $this->path_is_within( $candidate, $root ) ) return $candidate;
        }
        return false;
    }

    private function path_is_within( $path, $root ) {
        $path = wp_normalize_path( (string) $path );
        $root = untrailingslashit( wp_normalize_path( (string) $root ) );
        if ( 'Windows' === PHP_OS_FAMILY ) {
            $path = strtolower( $path );
            $root = strtolower( $root );
        }
        return $path === $root || 0 === strpos( $path, trailingslashit( $root ) );
    }

    private function path_is_public( $path ) {
        if ( empty( $_SERVER['DOCUMENT_ROOT'] ) ) return false;
        $document_root = realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
        if ( ! $document_root ) return false;
        $resolved_path = realpath( $path );
        return $this->path_is_within( $resolved_path ?: $path, $document_root );
    }

    public function ajax_diagnostics() {
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'cli_diagnostics' ) && ! wp_verify_nonce( $nonce, 'cli_public' ) ) wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
        wp_send_json_success( [
            'plugin_version' => CLI_VERSION,
            'catalog_version' => CLI_CATALOG_VERSION,
            'gravity_forms' => class_exists( 'GFForms' ),
            'gfapi' => class_exists( 'GFAPI' ),
            'server_time' => current_time( 'mysql' ),
        ] );
    }

    public function ajax_rule_test() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_access_plugin() ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        $referrer = esc_url_raw( wp_unslash( $_POST['referrer'] ?? '' ) );
        if ( ! $url ) wp_send_json_error( [ 'message' => 'Enter a valid landing URL.' ] );
        wp_send_json_success( $this->classify_url( $url, $referrer ) );
    }

    public function ajax_audit_leads() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_access_plugin() ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        if ( ! class_exists( 'GFAPI' ) ) wp_send_json_error( [ 'message' => 'Gravity Forms API is unavailable.' ] );
        $results = [];
        foreach ( GFAPI::get_forms( true ) as $form ) {
            $entries = GFAPI::get_entries( (int) $form['id'], [ 'status' => 'active' ], [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => 0, 'page_size' => 50 ] );
            if ( is_wp_error( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                $id = (int) rgar( $entry, 'id' );
                $payload = json_decode( (string) gform_get_meta( $id, 'cli_payload' ), true );
                if ( ! is_array( $payload ) ) continue;
                $touch = ! empty( $payload['last_touch'] ) ? $payload['last_touch'] : ( $payload['first_touch'] ?? [] );
                $landing = $touch['landing_url'] ?? '';
                $referrer = $touch['external_referrer'] ?? '';
                if ( ! $landing ) continue;
                $classified = $this->classify_touch( $touch );
                $current = gform_get_meta( $id, 'cli_lead_source' ) ?: 'Unknown';
                $results[] = [
                    'entry_id' => $id,
                    'date' => rgar( $entry, 'date_created' ),
                    'form' => $form['title'],
                    'current' => $current,
                    'strict_result' => $classified['source'],
                    'status' => $classified['status'],
                    'rule' => $classified['rule'],
                    'changed' => $current !== $classified['source'],
                ];
            }
        }
        usort( $results, function( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
        wp_send_json_success( array_slice( $results, 0, 100 ) );
    }

    public function ajax_preview_reclassification() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_manage_plugin() ) wp_send_json_error( [ 'message' => 'Contact the Lead Intelligence Access Owner to recalculate reporting.' ], 403 );
        if ( ! class_exists( 'GFAPI' ) ) wp_send_json_error( [ 'message' => 'Gravity Forms API is unavailable.' ] );

        $days = isset( $_POST['days'] ) ? max( 0, absint( $_POST['days'] ) ) : 90;
        $legacy_only = ! empty( $_POST['legacy_only'] );
        $scan = $this->scan_reclassification_candidates( $days, $legacy_only );
        $token = wp_generate_password( 24, false, false );
        set_transient( 'cli_reclass_' . get_current_user_id() . '_' . $token, $scan['changes'], 30 * MINUTE_IN_SECONDS );

        wp_send_json_success( [
            'catalog_version' => CLI_CATALOG_VERSION,
            'days' => $days,
            'legacy_only' => $legacy_only,
            'reviewed' => $scan['reviewed'],
            'eligible' => $scan['eligible'],
            'changing' => count( $scan['changes'] ),
            'unchanged' => $scan['unchanged'],
            'skipped_no_evidence' => $scan['skipped_no_evidence'],
            'summary' => $scan['summary'],
            'preview' => array_slice( $scan['changes'], 0, 100 ),
            'preview_limited' => count( $scan['changes'] ) > 100,
            'token' => $token,
        ] );
    }

    public function ajax_apply_reclassification() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_manage_plugin() ) wp_send_json_error( [ 'message' => 'Contact the Lead Intelligence Access Owner to recalculate reporting.' ], 403 );
        if ( ! class_exists( 'GFAPI' ) ) wp_send_json_error( [ 'message' => 'Gravity Forms API is unavailable.' ] );

        $token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) );
        if ( ! $token ) wp_send_json_error( [ 'message' => 'Run a fresh preview before applying changes.' ] );
        $key = 'cli_reclass_' . get_current_user_id() . '_' . $token;
        $changes = get_transient( $key );
        if ( ! is_array( $changes ) ) wp_send_json_error( [ 'message' => 'The preview expired. Run it again before applying.' ] );

        delete_transient( $key );
        update_option( 'cli_reclassification_last_run', current_time( 'mysql' ), false );
        $this->invalidate_reporting_cache();
        wp_send_json_success( [
            'message' => 'Reporting caches cleared. Reports will recalculate from unchanged Gravity Forms evidence.',
            'reviewed_changes' => count( $changes ),
            'entries_updated' => 0,
            'catalog_version' => CLI_CATALOG_VERSION,
        ] );
    }

    private function scan_reclassification_candidates( $days, $legacy_only ) {
        $reviewed = 0; $eligible = 0; $unchanged = 0; $skipped_no_evidence = 0;
        $changes = []; $summary = [];
        $criteria = [ 'status' => 'active' ];
        if ( $days > 0 ) $criteria['start_date'] = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days );

        foreach ( GFAPI::get_forms( true ) as $form ) {
            $offset = 0;
            do {
                $entries = GFAPI::get_entries( (int) $form['id'], $criteria, [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => $offset, 'page_size' => 200 ] );
                if ( is_wp_error( $entries ) || ! $entries ) break;
                foreach ( $entries as $entry ) {
                    $reviewed++;
                    $id = (int) rgar( $entry, 'id' );
                    $stored = trim( (string) gform_get_meta( $id, 'cli_lead_source' ) );
                    $stored_channel = trim( (string) gform_get_meta( $id, 'cli_channel' ) );
                    $stored_confidence = trim( (string) gform_get_meta( $id, 'cli_attribution_status' ) ) ?: 'Verified';
                    $legacy_dimensions = $stored_channel
                        ? [ 'source' => ( $stored ?: 'Unknown' ), 'channel' => $stored_channel ]
                        : $this->source_channel_from_legacy_label( $stored ?: 'Unknown', $stored_confidence );
                    $current_dimensions = $this->normalize_customer_attribution( $legacy_dimensions['source'], $legacy_dimensions['channel'], $stored_confidence );
                    $current = $current_dimensions['source'];
                    $current_channel = $current_dimensions['channel'];
                    $current_confidence = $current_dimensions['confidence'];
                    $classification = $this->classify_saved_entry( $id, $entry, $form );
                    $campaign = $this->recover_saved_campaign( $id, $entry, $form, $classification );
                    if ( ! $classification && empty( $campaign['label'] ) ) { $skipped_no_evidence++; continue; }
                    $new = $classification ? ( $classification['source'] ?? $current ) : $current;
                    $new_channel = $classification ? ( $classification['channel'] ?? $current_channel ) : $current_channel;
                    $new_confidence = $classification ? ( $classification['status'] ?? $current_confidence ) : $current_confidence;
                    $stored_campaign = trim( (string) ( gform_get_meta( $id, 'cli_campaign_label' ) ?: gform_get_meta( $id, 'cli_campaign' ) ) );
                    $new_campaign = trim( (string) ( $campaign['label'] ?? '' ) );
                    $dimension_change = $classification && ( $current !== $new || $current_channel !== $new_channel || $current_confidence !== $new_confidence || ! $stored_channel || $stored_confidence !== $new_confidence );
                    $source_change = $dimension_change && ( ! $stored_channel || $this->is_safe_reclassification( $stored, $current, $new, $classification ) || $current === $new );
                    $campaign_change = '' !== $new_campaign && $stored_campaign !== $new_campaign;
                    $is_legacy = ! $stored_channel || $this->is_legacy_source( $stored ) || in_array( $stored_confidence, [ 'Unverified', 'Unclassified' ], true ) || $current !== $stored || $current !== $new || $current_channel !== $new_channel || $current_confidence !== $new_confidence || $campaign_change;
                    if ( $legacy_only && ! $is_legacy ) continue;
                    $eligible++;
                    if ( ! $source_change && ! $campaign_change ) { $unchanged++; continue; }
                    $change = [
                        'entry_id' => $id,
                        'date' => rgar( $entry, 'date_created' ),
                        'form' => $form['title'],
                        'current' => $stored ?: 'Unknown',
                        'new' => $new,
                        'current_channel' => $current_channel,
                        'new_channel' => $new_channel,
                        'current_confidence' => $current_confidence,
                        'new_confidence' => $new_confidence,
                        'current_campaign' => $stored_campaign,
                        'new_campaign' => $new_campaign,
                        'status' => $new_confidence,
                        'rule' => $classification['rule'] ?? gform_get_meta( $id, 'cli_attribution_rule' ),
                        'evidence' => $classification['evidence'] ?? [],
                    ];
                    $changes[] = $change;
                    if ( $source_change ) {
                        $label = ( $stored ?: 'Unknown' ) . ' [' . $current_channel . ', ' . $current_confidence . '] -> ' . $new . ' [' . $new_channel . ', ' . $new_confidence . ']';
                        $summary[ $label ] = ( $summary[ $label ] ?? 0 ) + 1;
                    }
                    if ( $campaign_change ) {
                        $label = 'Campaign: ' . ( $stored_campaign ?: 'Missing' ) . ' -> ' . $new_campaign;
                        $summary[ $label ] = ( $summary[ $label ] ?? 0 ) + 1;
                    }
                }
                $offset += count( $entries );
            } while ( count( $entries ) === 200 );
        }
        arsort( $summary );
        return compact( 'reviewed', 'eligible', 'unchanged', 'skipped_no_evidence', 'changes', 'summary' );
    }

    private function classify_saved_entry( $entry_id, $entry = null, $form = null ) {
        $payload = json_decode( (string) gform_get_meta( $entry_id, 'cli_payload' ), true );
        $first = is_array( $payload ) && ! empty( $payload['first_touch'] ) && is_array( $payload['first_touch'] ) ? $payload['first_touch'] : [];
        $last  = is_array( $payload ) && ! empty( $payload['last_touch'] ) && is_array( $payload['last_touch'] ) ? $payload['last_touch'] : [];

        // Historical releases stored the same attribution URL under several
        // different keys. Reclassification must inspect every saved location,
        // rather than assuming the newest payload shape is present.
        $landing = $this->first_value( [
            $last['landing_url'] ?? '',
            $first['landing_url'] ?? '',
            gform_get_meta( $entry_id, 'cli_landing_page' ),
            gform_get_meta( $entry_id, 'cli_conversion_page' ),
        ] );

        $referrer = $this->first_value( [
            $last['external_referrer'] ?? '',
            $first['external_referrer'] ?? '',
            gform_get_meta( $entry_id, 'cli_external_referrer' ),
            gform_get_meta( $entry_id, 'cli_referrer' ),
        ] );

        // Some older forms saved attribution in actual Gravity Forms fields
        // named Referrer or Referral Parameters rather than entry meta. Recover
        // those values so historical submissions use the same classifier as
        // new leads.
        if ( ! is_array( $entry ) ) {
            $entry = class_exists( 'GFAPI' ) ? GFAPI::get_entry( $entry_id ) : null;
            if ( is_wp_error( $entry ) ) $entry = null;
        }
        if ( ! is_array( $form ) && is_array( $entry ) ) {
            $form = class_exists( 'GFAPI' ) ? GFAPI::get_form( (int) rgar( $entry, 'form_id' ) ) : null;
        }
        $field_evidence = $this->recover_entry_field_evidence( $entry, $form );
        if ( ! $referrer && ! empty( $field_evidence['referrer'] ) ) $referrer = $field_evidence['referrer'];
        if ( ! $landing && ! empty( $field_evidence['landing'] ) ) $landing = $field_evidence['landing'];
        $touch = array_merge( $first, $last );
        if ( $landing ) $touch['landing_url'] = $landing;
        if ( $referrer ) $touch['external_referrer'] = $referrer;
        foreach ( $this->extract_url_query_values( $field_evidence['parameters'] ?? '' ) as $key => $value ) {
            if ( in_array( $key, $this->attribution_query_keys(), true ) && empty( $touch[ $key ] ) ) {
                $value = $this->privacy_safe_attribution_value( $value );
                if ( '' !== $value ) $touch[ $key ] = $value;
            }
        }

        // A tagged referrer URL or separate parameter bundle can contain
        // complete attribution evidence even when no historical landing URL
        // was retained.
        $stored_classification = $this->classify_stored_dimensions( $entry_id );
        if ( ! $landing && ! $referrer && count( $touch ) === 0 ) return $stored_classification;

        $classification = $this->classify_touch( $touch, $first );
        if ( $stored_classification && in_array( $classification['source'], [ 'Unknown', 'Direct' ], true ) && ! in_array( $stored_classification['source'], [ 'Unknown', 'Direct' ], true ) ) {
            return $stored_classification;
        }
        return $classification;
    }

    private function classify_stored_dimensions( $entry_id ) {
        $stored_source = trim( (string) gform_get_meta( $entry_id, 'cli_lead_source' ) );
        if ( '' === $stored_source || 'unknown' === strtolower( $stored_source ) ) return false;

        $stored_channel = trim( (string) gform_get_meta( $entry_id, 'cli_channel' ) );
        $stored_confidence = trim( (string) gform_get_meta( $entry_id, 'cli_attribution_status' ) ) ?: 'Verified';
        if ( ! $stored_channel ) {
            $dimensions = $this->source_channel_from_legacy_label( $stored_source, $stored_confidence );
            $stored_source = $dimensions['source'];
            $stored_channel = $dimensions['channel'];
        }
        $normalized = $this->normalize_customer_attribution( $stored_source, $stored_channel, $stored_confidence );

        return [
            'source' => $normalized['source'],
            'channel' => $normalized['channel'],
            'legacy_source' => gform_get_meta( $entry_id, 'cli_legacy_source' ) ?: gform_get_meta( $entry_id, 'cli_lead_source' ),
            'status' => $normalized['confidence'],
            'rule' => 'Stored Attribution Normalization',
            'priority' => 1,
            'evidence' => [ 'Historical Lead Intelligence source metadata: ' . gform_get_meta( $entry_id, 'cli_lead_source' ) ],
            'customer_evidence' => $this->customer_evidence_message( $normalized['source'], $normalized['channel'], $normalized['confidence'] ),
        ];
    }

    private function recover_entry_field_evidence( $entry, $form ) {
        $result = [ 'referrer' => '', 'parameters' => '', 'landing' => '' ];
        if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['fields'] ) ) return $result;

        foreach ( $form['fields'] as $field ) {
            $labels = [];
            foreach ( [ 'label', 'adminLabel', 'inputName' ] as $property ) {
                if ( isset( $field->$property ) && '' !== trim( (string) $field->$property ) ) $labels[] = strtolower( trim( (string) $field->$property ) );
            }
            $label = implode( ' ', $labels );
            if ( '' === $label ) continue;

            $values = [];
            $field_id = isset( $field->id ) ? (string) $field->id : '';
            if ( '' !== $field_id && isset( $entry[ $field_id ] ) ) $values[] = $entry[ $field_id ];
            if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
                foreach ( $field->inputs as $input ) {
                    $input_id = isset( $input['id'] ) ? (string) $input['id'] : '';
                    if ( '' !== $input_id && isset( $entry[ $input_id ] ) ) $values[] = $entry[ $input_id ];
                }
            }

            foreach ( $values as $value ) {
                if ( is_array( $value ) ) $value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
                $value = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
                if ( '' === $value ) continue;

                $is_referrer_field = false !== strpos( $label, 'referrer' ) || false !== strpos( $label, 'referer' );
                $is_parameter_field = false !== strpos( $label, 'referral parameter' ) || false !== strpos( $label, 'utm parameter' ) || false !== strpos( $label, 'tracking parameter' );
                $is_landing_field = false !== strpos( $label, 'landing page' ) || false !== strpos( $label, 'entry url' );

                if ( $is_referrer_field && preg_match( '#^https?://#i', $value ) ) {
                    $result['referrer'] = esc_url_raw( $value );
                } elseif ( $is_parameter_field && ! $result['parameters'] && preg_match( '/(?:^|[?&])(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid)=/i', $value ) ) {
                    $query = ltrim( $value, "?& \t\n\r\0\x0B" );
                    $result['parameters'] = 'https://saved-attribution.invalid/?' . $query;
                }

                if ( $is_landing_field && ! $result['landing'] && preg_match( '#^https?://#i', $value ) ) {
                    $result['landing'] = esc_url_raw( $value );
                }
            }
        }
        if ( ! $result['referrer'] ) $result['referrer'] = $result['parameters'];
        return $result;
    }

    public function ajax_clean_unknowns() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_manage_plugin() ) wp_send_json_error( [ 'message' => 'Contact the Lead Intelligence Access Owner to change reporting settings.' ], 403 );
        update_option( 'cli_reporting_unknown_policy', 'exclude', false );
        update_option( 'cli_unknown_cleanup_last_run', current_time( 'mysql' ), false );
        $this->invalidate_reporting_cache();
        wp_send_json_success( [ 'message' => 'Blank and Unknown sources will be excluded during report calculation. No entries were changed.', 'entries_updated' => 0 ] );
    }

    public function ajax_restore_cleaned() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_manage_plugin() ) wp_send_json_error( [ 'message' => 'Contact the Lead Intelligence Access Owner to change reporting settings.' ], 403 );
        update_option( 'cli_reporting_unknown_policy', 'include', false );
        $this->invalidate_reporting_cache();
        wp_send_json_success( [ 'message' => 'Blank and Unknown sources will be included during report calculation. No entries were changed.', 'entries_updated' => 0 ] );
    }

    private function campaign_attribution_from_touches( $first, $last, $lead_source = '' ) {
        $first = $this->landing_authoritative_touch( is_array( $first ) ? $first : [] );
        $last = $this->landing_authoritative_touch( is_array( $last ) ? $last : [] );
        $first_attribution = $first ? $this->classify_touch( $first ) : [];
        $last_attribution = $last ? $this->classify_touch( $last ) : [];
        $primary = $this->last_non_direct_attribution( $first_attribution, $last_attribution );
        $ordered_touches = 'first' === ( $primary['touch'] ?? 'last' ) ? [ $first, $last ] : [ $last, $first ];
        $tagged_landings = [];
        foreach ( $ordered_touches as $touch ) {
            $candidate = $touch['landing_url'] ?? '';
            if ( $candidate && $this->url_has_attribution_evidence( $candidate ) ) $tagged_landings[] = $candidate;
        }
        // Campaign details follow the selected business attribution touch. This
        // keeps the original paid ad, ad set, and campaign attached when a later
        // inferred Meta organic return is intentionally weaker than acquisition.
        $landing = $this->first_value( array_merge(
            $tagged_landings,
            array_map( static function( $touch ) { return $touch['landing_url'] ?? ''; }, $ordered_touches )
        ) );
        $referrer = $this->first_value( array_map( static function( $touch ) { return $touch['external_referrer'] ?? ''; }, $ordered_touches ) );
        $direct = [];
        foreach ( $ordered_touches as $touch ) {
            if ( ! is_array( $touch ) ) continue;
            foreach ( [ 'utm_campaign', 'campaign', 'campaign_name', 'utm_id', 'campaign_id', 'campaignid', 'gad_campaignid', 'utm_source', 'utm_medium', 'utm_content', 'utm_term' ] as $key ) {
                if ( ! isset( $direct[ $key ] ) && isset( $touch[ $key ] ) && '' !== trim( (string) $touch[ $key ] ) ) {
                    $direct[ $key ] = sanitize_text_field( (string) $touch[ $key ] );
                }
            }
        }
        return $this->campaign_attribution_from_urls( $landing, $referrer, $lead_source, $direct );
    }

    private function url_has_attribution_evidence( $url ) {
        $values = $this->extract_url_query_values( $url );
        foreach ( $this->attribution_query_keys() as $key ) {
            if ( isset( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) ) return true;
        }
        return false;
    }

    private function campaign_attribution_from_urls( $landing, $referrer, $lead_source = '', $direct = [] ) {
        $ref = $this->extract_url_query_values( $referrer );
        $land = $this->extract_url_query_values( $landing );
        // The actual landing URL is authoritative when redundant saved touch fields conflict.
        $values = array_merge( is_array( $direct ) ? $direct : [], $ref, $land );
        $name = $this->first_value( [ $values['utm_campaign'] ?? '', $values['campaign_name'] ?? '', $values['campaign'] ?? '' ] );
        $id = $this->first_value( [ $values['utm_id'] ?? '', $values['campaign_id'] ?? '', $values['campaignid'] ?? '', $values['gad_campaignid'] ?? '' ] );
        $source = $this->first_value( [ $values['utm_source'] ?? '' ] );
        $medium = $this->first_value( [ $values['utm_medium'] ?? '' ] );
        $content = $this->first_value( [ $values['utm_content'] ?? '' ] );
        $term = $this->first_value( [ $values['utm_term'] ?? '' ] );
        $origin = '';
        foreach ( [ 'utm_campaign', 'campaign_name', 'campaign', 'utm_id', 'campaign_id', 'campaignid', 'gad_campaignid' ] as $key ) {
            if ( isset( $land[ $key ] ) && '' !== trim( (string) $land[ $key ] ) ) { $origin = 'Landing URL'; break; }
            if ( isset( $ref[ $key ] ) && '' !== trim( (string) $ref[ $key ] ) ) $origin = 'Referrer URL';
        }
        if ( ! $origin && ( $name || $id ) ) $origin = 'Saved Tracking Data';
        $label = $name;
        if ( ! $label && $id ) {
            $platform = $this->campaign_platform_label( $lead_source, $source );
            $label = $platform ? $platform . ' Campaign #' . $id : 'Campaign #' . $id;
        }
        return [
            'label' => trim( (string) $label ),
            'name' => trim( (string) $name ),
            'id' => trim( (string) $id ),
            'source' => trim( (string) $source ),
            'medium' => trim( (string) $medium ),
            'content' => trim( (string) $content ),
            'term' => trim( (string) $term ),
            'origin' => $origin,
        ];
    }

    private function campaign_platform_label( $lead_source, $utm_source = '' ) {
        $lead_source = $this->canonical_source( $lead_source );
        if ( 'Google Ads' === $lead_source || preg_match( '/google/i', $utm_source ) ) return 'Google Ads';
        if ( in_array( $lead_source, [ 'Facebook Ads', 'Instagram Ads', 'Meta Ads' ], true ) || preg_match( '/facebook|instagram|meta/i', $utm_source ) ) return 'Meta Ads';
        if ( 'Microsoft Ads' === $lead_source || preg_match( '/bing|microsoft/i', $utm_source ) ) return 'Microsoft Ads';
        if ( preg_match( '/ Ads$/', $lead_source ) ) return $lead_source;
        return '';
    }

    private function extract_url_query_values( $url ) {
        $parts = wp_parse_url( (string) $url );
        $query = [];
        if ( ! empty( $parts['query'] ) ) parse_str( html_entity_decode( $parts['query'], ENT_QUOTES, 'UTF-8' ), $query );
        $clean = [];
        foreach ( $query as $key => $value ) {
            $value = is_array( $value ) ? reset( $value ) : $value;
            $clean[ strtolower( sanitize_key( $key ) ) ] = trim( sanitize_text_field( (string) $value ) );
        }
        return $clean;
    }

    private function recover_saved_campaign( $entry_id, $entry = null, $form = null, $classification = null ) {
        $payload = json_decode( (string) gform_get_meta( $entry_id, 'cli_payload' ), true );
        $first = is_array( $payload ) && ! empty( $payload['first_touch'] ) && is_array( $payload['first_touch'] ) ? $payload['first_touch'] : [];
        $last = is_array( $payload ) && ! empty( $payload['last_touch'] ) && is_array( $payload['last_touch'] ) ? $payload['last_touch'] : [];
        $landing = $this->first_value( [ $last['landing_url'] ?? '', $first['landing_url'] ?? '', gform_get_meta( $entry_id, 'cli_landing_page' ), gform_get_meta( $entry_id, 'cli_conversion_page' ) ] );
        $referrer = $this->first_value( [ $last['external_referrer'] ?? '', $first['external_referrer'] ?? '', gform_get_meta( $entry_id, 'cli_external_referrer' ), gform_get_meta( $entry_id, 'cli_referrer' ) ] );
        if ( ! is_array( $entry ) && class_exists( 'GFAPI' ) ) { $entry = GFAPI::get_entry( $entry_id ); if ( is_wp_error( $entry ) ) $entry = null; }
        if ( ! is_array( $form ) && is_array( $entry ) && class_exists( 'GFAPI' ) ) $form = GFAPI::get_form( (int) rgar( $entry, 'form_id' ) );
        $field_evidence = $this->recover_entry_field_evidence( $entry, $form );
        if ( ! $referrer && ! empty( $field_evidence['referrer'] ) ) $referrer = $field_evidence['referrer'];
        if ( ! $landing && ! empty( $field_evidence['landing'] ) ) $landing = $field_evidence['landing'];
        $direct = [];
        foreach ( [ $first, $last ] as $touch ) if ( is_array( $touch ) ) $direct = array_merge( $direct, $touch );
        $source = is_array( $classification ) ? ( $classification['legacy_source'] ?? ( $classification['source'] ?? '' ) ) : ( gform_get_meta( $entry_id, 'cli_legacy_source' ) ?: gform_get_meta( $entry_id, 'cli_lead_source' ) );
        return $this->campaign_attribution_from_urls( $landing, $referrer, $source, $direct );
    }

    /**
     * Classify a saved touch without changing its display URLs. Historical
     * forms can store click IDs and UTMs in a separate Referral Parameters
     * field, so merge only missing keys into a temporary classification URL.
     */
    private function classify_touch( $touch, $fallback_touch = [] ) {
        $touch = is_array( $touch ) ? $touch : [];
        $fallback_touch = is_array( $fallback_touch ) ? $fallback_touch : [];
        $landing = $this->first_value( [ $touch['landing_url'] ?? '', $fallback_touch['landing_url'] ?? '' ] );
        $referrer = $this->first_value( [ $touch['external_referrer'] ?? '', $fallback_touch['external_referrer'] ?? '' ] );
        $landing_values = $this->extract_url_query_values( $landing );
        $missing_values = [];

        foreach ( $this->attribution_query_keys() as $key ) {
            if ( isset( $landing_values[ $key ] ) && '' !== trim( (string) $landing_values[ $key ] ) ) continue;
            $value = $this->first_value( [ $touch[ $key ] ?? '', $fallback_touch[ $key ] ?? '' ] );
            $value = $this->privacy_safe_attribution_value( $value );
            if ( '' !== $value ) $missing_values[ $key ] = $value;
        }

        $classification_url = $landing;
        if ( $missing_values ) {
            if ( ! $classification_url ) $classification_url = 'https://saved-attribution.invalid/';
            $classification_url .= ( false === strpos( $classification_url, '?' ) ? '?' : '&' ) . http_build_query( $missing_values, '', '&', PHP_QUERY_RFC3986 );
        }
        return $this->classify_url( $classification_url, $referrer );
    }
    private function classify_url( $url, $external_referrer = '' ) {
        $evidence = $this->build_evidence( $url, $external_referrer );
        foreach ( $this->attribution_catalog() as $rule ) {
            $matched = call_user_func( $rule['match'], $evidence );
            if ( $matched ) {
                $matched_source = is_array( $matched ) && isset( $matched['__source'] ) ? $matched['__source'] : $rule['source'];
                $matched_status = is_array( $matched ) && isset( $matched['__status'] ) ? $matched['__status'] : ( $rule['status'] ?? 'Verified' );
                if ( is_array( $matched ) ) {
                    unset( $matched['__source'], $matched['__status'] );
                }
                $dimensions = $this->source_channel_from_legacy_label( $matched_source, $matched_status );
                $normalized = $this->normalize_customer_attribution( $dimensions['source'], $dimensions['channel'], $matched_status );
                return [
                    'source'        => $normalized['source'],
                    'channel'       => $normalized['channel'],
                    'legacy_source' => $matched_source,
                    'status'        => $normalized['confidence'],
                    'rule'          => $rule['name'],
                    'priority'      => $rule['priority'],
                    'evidence'      => is_array( $matched ) ? array_values( array_filter( $matched, 'strlen' ) ) : [ $rule['name'] . ' matched' ],
                    'customer_evidence' => $this->customer_evidence_message( $normalized['source'], $normalized['channel'], $normalized['confidence'] ),
                ];
            }
        }
        return [
            'source' => 'Unknown',
            'channel' => 'Unknown',
            'legacy_source' => 'Unknown',
            'status' => 'Inferred',
            'rule' => 'No Usable Attribution Evidence',
            'priority' => 0,
            'evidence' => [ 'No usable source, referrer, campaign, click ID, or attribution evidence was available' ],
            'customer_evidence' => $this->customer_evidence_message( 'Unknown', 'Unknown', 'Inferred' ),
        ];
    }

    private function build_evidence( $url, $external_referrer = '' ) {
        $landing_query = $this->extract_url_query( $url );
        $ref_query     = $this->extract_url_query( $external_referrer );

        // Referrer-carried campaign parameters are valid evidence. This occurs
        // when a browser or form integration stores the original tagged landing
        // URL as the referrer after the visitor has moved deeper into the site.
        // Current landing-page values win when both URLs contain the same key.
        $clean = array_merge( $ref_query, $landing_query );

        $ref_host = strtolower( (string) wp_parse_url( $external_referrer, PHP_URL_HOST ) );
        $ref_host = preg_replace( '/^www\./', '', $ref_host );
        $ref_is_internal = $this->is_internal_site_url( $external_referrer );

        $source   = $clean['utm_source'] ?? '';
        $medium   = $clean['utm_medium'] ?? '';
        $campaign = $clean['utm_campaign'] ?? '';

        // Delimiter-aware paid detection. This intentionally does not infer paid
        // traffic from campaign names or platform referrers alone.
        $paid = (bool) preg_match( '/(^|[\s_\-.])(cpc|ppc|paid|paid_social|paidsocial|paid-social|ads?|display|retargeting|remarketing|sponsored)([\s_\-.]|$)/i', $medium );

        $query_origins = [];
        foreach ( array_keys( $clean ) as $key ) {
            if ( array_key_exists( $key, $landing_query ) ) $query_origins[ $key ] = 'landing_url';
            elseif ( array_key_exists( $key, $ref_query ) ) $query_origins[ $key ] = 'referrer_url';
        }

        return [
            'url'             => $url,
            'query'           => $clean,
            'query_origins'   => $query_origins,
            'source'          => $source,
            'medium'          => $medium,
            'campaign'        => $campaign,
            'ref_host'        => $ref_host,
            'ref_is_internal' => (bool) $ref_is_internal,
            'paid'            => $paid,
        ];
    }

    private function extract_url_query( $url ) {
        $parts = wp_parse_url( (string) $url );
        $query = [];
        if ( ! empty( $parts['query'] ) ) parse_str( $parts['query'], $query );

        $clean = [];
        foreach ( $query as $key => $value ) {
            $value = is_array( $value ) ? reset( $value ) : $value;
            $clean[ strtolower( sanitize_key( $key ) ) ] = strtolower( trim( sanitize_text_field( (string) $value ) ) );
        }
        return $clean;
    }

    private function attribution_catalog() {
        $has = function( $e, $key ) {
            return isset( $e['query'][ $key ] ) && '' !== trim( (string) $e['query'][ $key ] );
        };
        $source_is = function( $e, $values ) {
            return in_array( $e['source'], $values, true );
        };
        $source_contains = function( $e, $needles ) {
            foreach ( $needles as $needle ) {
                if ( '' !== $needle && false !== strpos( $e['source'], $needle ) ) return true;
            }
            return false;
        };
        $host = function( $e, $pattern ) {
            return $e['ref_host'] && preg_match( $pattern, $e['ref_host'] );
        };
        $paid_evidence = function( $e ) {
            if ( ! $e['paid'] ) return '';
            $origin = $e['query_origins']['utm_medium'] ?? '';
            return 'Paid UTM medium: ' . $e['medium'] . ( 'referrer_url' === $origin ? ' (from referrer URL)' : '' );
        };
        $source_evidence = function( $e, $label ) {
            $origin = $e['query_origins']['utm_source'] ?? '';
            return $label . ': ' . $e['source'] . ( 'referrer_url' === $origin ? ' (from referrer URL)' : '' );
        };
        $meta_campaign_id = function( $e ) {
            foreach ( [ 'utm_id', 'campaign_id', 'campaignid' ] as $key ) {
                $value = trim( (string) ( $e['query'][ $key ] ?? '' ) );
                if ( preg_match( '/^[0-9]{6,}$/', $value ) ) return [ 'key' => $key, 'value' => $value ];
            }
            return false;
        };

        $rules = [
            // Click IDs are the strongest deterministic evidence.
            [ 'priority'=>1000, 'source'=>'Google Ads', 'name'=>'Google Ads Click ID', 'summary'=>['gclid, gbraid, or wbraid'], 'match'=>function($e)use($has){ $ids=[]; foreach(['gclid','gbraid','wbraid'] as $id){ if($has($e,$id)) $ids[]=$id; } return $ids ? ['Google advertising click ID detected: '.implode(', ',$ids)] : false; } ],
            [ 'priority'=>995, 'source'=>'Microsoft Ads', 'name'=>'Microsoft Ads Click ID', 'summary'=>['msclkid'], 'match'=>function($e)use($has){ return $has($e,'msclkid') ? ['Microsoft advertising click ID detected: msclkid'] : false; } ],
            [ 'priority'=>990, 'source'=>'TikTok Ads', 'name'=>'TikTok Ads Click ID', 'summary'=>['ttclid'], 'match'=>function($e)use($has){ return $has($e,'ttclid') ? ['TikTok advertising click ID detected: ttclid'] : false; } ],
            [ 'priority'=>985, 'source'=>'LinkedIn Ads', 'name'=>'LinkedIn Ads Click ID', 'summary'=>['li_fat_id'], 'match'=>function($e)use($has){ return $has($e,'li_fat_id') ? ['LinkedIn advertising click ID detected: li_fat_id'] : false; } ],
            [ 'priority'=>980, 'source'=>'Pinterest Ads', 'name'=>'Pinterest Ads Click ID', 'summary'=>['epik'], 'match'=>function($e)use($has){ return $has($e,'epik') ? ['Pinterest advertising click ID detected: epik'] : false; } ],

            // Meta's dynamic placement parameter is sometimes inserted into
            // utm_source while a custom campaign label is incorrectly placed
            // in utm_medium. The platform-specific placement, fbclid, and a
            // numeric campaign identifier together are deterministic paid
            // evidence; fbclid alone remains intentionally ambiguous.
            [ 'priority'=>978, 'source'=>'Facebook Ads', 'name'=>'Facebook Placement with Meta Campaign Evidence', 'summary'=>['Facebook placement source + fbclid + numeric campaign ID'], 'match'=>function($e)use($has,$source_contains,$meta_campaign_id,$source_evidence){ $campaign_id=$meta_campaign_id($e); return $has($e,'fbclid') && $campaign_id && $source_contains($e,['facebook']) ? [$source_evidence($e,'Facebook placement source'),'Meta advertising click identifier detected: fbclid','Meta campaign identifier detected: '.$campaign_id['key'].'='.$campaign_id['value']] : false; } ],
            [ 'priority'=>977, 'source'=>'Instagram Ads', 'name'=>'Instagram Placement with Meta Campaign Evidence', 'summary'=>['Instagram placement source + fbclid + numeric campaign ID'], 'match'=>function($e)use($has,$source_contains,$meta_campaign_id,$source_evidence){ $campaign_id=$meta_campaign_id($e); return $has($e,'fbclid') && $campaign_id && $source_contains($e,['instagram']) ? [$source_evidence($e,'Instagram placement source'),'Meta advertising click identifier detected: fbclid','Meta campaign identifier detected: '.$campaign_id['key'].'='.$campaign_id['value']] : false; } ],

            // Explicit platform UTMs are evaluated first; a paid referrer is the fallback when the tagged source is absent or generic.
            [ 'priority'=>909, 'source'=>'Facebook Ads', 'name'=>'Facebook Referrer with Paid Evidence', 'summary'=>['Facebook referrer + explicit paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)(facebook|fb)\.com$/') && $e['paid'] ? ['Facebook external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>908, 'source'=>'Instagram Ads', 'name'=>'Instagram Referrer with Paid Evidence', 'summary'=>['Instagram referrer + explicit paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)instagram\.com$/') && $e['paid'] ? ['Instagram external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>907, 'source'=>'LinkedIn Ads', 'name'=>'LinkedIn Referrer with Paid Evidence', 'summary'=>['LinkedIn referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)linkedin\.com$/') && $e['paid'] ? ['LinkedIn external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>906, 'source'=>'TikTok Ads', 'name'=>'TikTok Referrer with Paid Evidence', 'summary'=>['TikTok referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)tiktok\.com$/') && $e['paid'] ? ['TikTok external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>905, 'source'=>'Pinterest Ads', 'name'=>'Pinterest Referrer with Paid Evidence', 'summary'=>['Pinterest referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)pinterest\.[a-z.]+$/') && $e['paid'] ? ['Pinterest external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>904, 'source'=>'Reddit Ads', 'name'=>'Reddit Referrer with Paid Evidence', 'summary'=>['Reddit referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)reddit\.com$/') && $e['paid'] ? ['Reddit external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>903, 'source'=>'X Ads', 'name'=>'X/Twitter Referrer with Paid Evidence', 'summary'=>['X/Twitter referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)(x\.com|twitter\.com|t\.co)$/') && $e['paid'] ? ['X/Twitter external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>902, 'source'=>'Google Ads', 'name'=>'Google Referrer with Paid Evidence', 'summary'=>['Google referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)google\.[a-z.]+$/') && $e['paid'] ? ['Google external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>901, 'source'=>'Microsoft Ads', 'name'=>'Bing Referrer with Paid Evidence', 'summary'=>['Bing referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)bing\.com$/') && $e['paid'] ? ['Bing external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>900, 'source'=>'Yahoo Ads', 'name'=>'Yahoo Referrer with Paid Evidence', 'summary'=>['Yahoo referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)search\.yahoo\.[a-z.]+$/') && $e['paid'] ? ['Yahoo external referrer detected', $paid_evidence($e)] : false; } ],
            [ 'priority'=>899, 'source'=>'DuckDuckGo Ads', 'name'=>'DuckDuckGo Referrer with Paid Evidence', 'summary'=>['DuckDuckGo referrer + paid medium'], 'match'=>function($e)use($host,$paid_evidence){ return $host($e,'/(^|\.)duckduckgo\.com$/') && $e['paid'] ? ['DuckDuckGo external referrer detected', $paid_evidence($e)] : false; } ],

            // Paid UTMs. Platform names remain separate for useful reporting.
            [ 'priority'=>970, 'source'=>'Instagram Ads', 'name'=>'Instagram Paid UTM', 'summary'=>['Instagram source pattern + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence,$source_evidence){ return $e['paid'] && $source_contains($e,['instagram','instagram_reels','instagram-reels','instagram_stories','instagram-stories']) ? [$source_evidence($e,'Instagram UTM source'), $paid_evidence($e)] : false; } ],
            [ 'priority'=>965, 'source'=>'Instagram Ads', 'name'=>'Instagram IG Paid UTM', 'summary'=>['utm_source=ig + paid medium'], 'match'=>function($e)use($source_is,$paid_evidence,$source_evidence){ return $e['paid'] && $source_is($e,['ig','igads','ig_ads']) ? [$source_evidence($e,'Instagram UTM source'), $paid_evidence($e)] : false; } ],
            [ 'priority'=>960, 'source'=>'Facebook Ads', 'name'=>'Facebook Paid UTM', 'summary'=>['Facebook source pattern + paid medium'], 'match'=>function($e)use($source_contains,$source_is,$paid_evidence,$source_evidence){ return $e['paid'] && ($source_contains($e,['facebook']) || $source_is($e,['fb','fbads','fb_ads'])) ? [$source_evidence($e,'Facebook UTM source'), $paid_evidence($e)] : false; } ],
            [ 'priority'=>955, 'source'=>'Facebook Ads', 'name'=>'Meta Paid UTM with Facebook Referrer', 'summary'=>['utm_source=meta + paid medium + Facebook referrer'], 'match'=>function($e)use($source_is,$host,$paid_evidence){ return $e['paid'] && $source_is($e,['meta','metaads','meta_ads']) && $host($e,'/(^|\.)(facebook|fb)\.com$/') ? ['Meta UTM source with Facebook referrer', $paid_evidence($e)] : false; } ],
            [ 'priority'=>954, 'source'=>'Instagram Ads', 'name'=>'Meta Paid UTM with Instagram Referrer', 'summary'=>['utm_source=meta + paid medium + Instagram referrer'], 'match'=>function($e)use($source_is,$host,$paid_evidence){ return $e['paid'] && $source_is($e,['meta','metaads','meta_ads']) && $host($e,'/(^|\.)instagram\.com$/') ? ['Meta UTM source with Instagram referrer', $paid_evidence($e)] : false; } ],
            [ 'priority'=>950, 'source'=>'Google Ads', 'name'=>'Google Paid UTM', 'summary'=>['Google source + paid medium'], 'match'=>function($e)use($source_is,$source_contains,$paid_evidence){ return $e['paid'] && ($source_is($e,['google','googleads','google_ads','adwords']) || $source_contains($e,['google_ads'])) ? ['Google UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>945, 'source'=>'Microsoft Ads', 'name'=>'Microsoft Paid UTM', 'summary'=>['Bing/Microsoft source + paid medium'], 'match'=>function($e)use($source_is,$source_contains,$paid_evidence){ return $e['paid'] && ($source_is($e,['bing','microsoft','microsoftads','microsoft_ads']) || $source_contains($e,['bingads','bing_ads'])) ? ['Microsoft/Bing UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>940, 'source'=>'LinkedIn Ads', 'name'=>'LinkedIn Paid UTM', 'summary'=>['LinkedIn source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['linkedin']) ? ['LinkedIn UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>935, 'source'=>'TikTok Ads', 'name'=>'TikTok Paid UTM', 'summary'=>['TikTok source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['tiktok']) ? ['TikTok UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>930, 'source'=>'Reddit Ads', 'name'=>'Reddit Paid UTM', 'summary'=>['Reddit source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['reddit']) ? ['Reddit UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>925, 'source'=>'Pinterest Ads', 'name'=>'Pinterest Paid UTM', 'summary'=>['Pinterest source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['pinterest']) ? ['Pinterest UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>920, 'source'=>'X Ads', 'name'=>'X/Twitter Paid UTM', 'summary'=>['X/Twitter source + paid medium'], 'match'=>function($e)use($source_is,$source_contains,$paid_evidence){ return $e['paid'] && ($source_is($e,['x']) || $source_contains($e,['twitter'])) ? ['X/Twitter UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>915, 'source'=>'Yahoo Ads', 'name'=>'Yahoo Paid UTM', 'summary'=>['Yahoo source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['yahoo']) ? ['Yahoo UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],
            [ 'priority'=>910, 'source'=>'DuckDuckGo Ads', 'name'=>'DuckDuckGo Paid UTM', 'summary'=>['DuckDuckGo source + paid medium'], 'match'=>function($e)use($source_contains,$paid_evidence){ return $e['paid'] && $source_contains($e,['duckduckgo','duck_duck_go']) ? ['DuckDuckGo UTM source: '.$e['source'], $paid_evidence($e)] : false; } ],

            // Google surfaces are kept distinct from generic organic search.
            [ 'priority'=>880, 'source'=>'Google Business Profile', 'name'=>'Google Business Profile UTM', 'summary'=>['Google Business Profile/GMB UTM source'], 'match'=>function($e)use($source_is,$source_contains){ return ($source_is($e,['gmb','gbp','google_business_profile','googlebusinessprofile']) || $source_contains($e,['google-business-profile','google_business'])) ? ['Google Business Profile UTM source: '.$e['source']] : false; } ],
            [ 'priority'=>875, 'source'=>'Google Maps', 'name'=>'Google Maps Attribution', 'summary'=>['Google Maps UTM source or maps.google referrer'], 'match'=>function($e)use($source_is,$source_contains,$host){ return ($source_is($e,['google_maps','googlemaps','maps']) || $source_contains($e,['google-maps']) || $host($e,'/(^|\.)maps\.google\./')) ? ['Google Maps evidence detected'.($e['source'] ? ': '.$e['source'] : ''), $e['ref_host'] ? 'Referrer: '.$e['ref_host'] : ''] : false; } ],

            // Link-in-bio providers.
            [ 'priority'=>850, 'source'=>'Later Link in Bio', 'name'=>'Later Link in Bio', 'summary'=>['linkin.bio referrer or Later source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)linkin\.bio$/') || $source_contains($e,['later-linkinbio','later_linkinbio','later'])) ? ['Later Link in Bio evidence detected', $e['ref_host'] ? 'Referrer: '.$e['ref_host'] : '', $e['source'] ? 'utm_source='.$e['source'] : ''] : false; } ],
            [ 'priority'=>845, 'source'=>'Linktree', 'name'=>'Linktree Referral', 'summary'=>['linktr.ee referrer or Linktree source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)linktr\.ee$/') || $source_contains($e,['linktree','linktr.ee'])) ? ['Linktree evidence detected'] : false; } ],
            [ 'priority'=>840, 'source'=>'Beacons', 'name'=>'Beacons Link in Bio', 'summary'=>['beacons.ai referrer or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)beacons\.ai$/') || $source_contains($e,['beacons'])) ? ['Beacons evidence detected'] : false; } ],
            [ 'priority'=>835, 'source'=>'Campsite', 'name'=>'Campsite Link in Bio', 'summary'=>['campsite.bio referrer or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)campsite\.bio$/') || $source_contains($e,['campsite'])) ? ['Campsite evidence detected'] : false; } ],
            [ 'priority'=>830, 'source'=>'Lnk.Bio', 'name'=>'Lnk.Bio Referral', 'summary'=>['lnk.bio referrer or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)lnk\.bio$/') || $source_contains($e,['lnk.bio','lnkbio','lnk_bio'])) ? ['Lnk.Bio evidence detected'] : false; } ],

            // Explicit campaign channels.
            [ 'priority'=>810, 'source'=>'Email', 'name'=>'Email Campaign UTM', 'summary'=>['email/newsletter medium'], 'match'=>function($e){ return preg_match('/(^|[\s_\-.])(email|newsletter)([\s_\-.]|$)/i',$e['medium']) ? ['Email/newsletter UTM medium: '.$e['medium']] : false; } ],
            [ 'priority'=>805, 'source'=>'SMS', 'name'=>'SMS Campaign UTM', 'summary'=>['sms/text medium'], 'match'=>function($e){ return preg_match('/(^|[\s_\-.])(sms|text)([\s_\-.]|$)/i',$e['medium']) ? ['SMS/text UTM medium: '.$e['medium']] : false; } ],
            [ 'priority'=>800, 'source'=>'QR Code', 'name'=>'QR Campaign UTM', 'summary'=>['qr medium/source'], 'match'=>function($e){ return (preg_match('/(^|[\s_\-.])qr([\s_\-.]|$)/i',$e['medium']) || preg_match('/(^|[\s_\-.])qr([\s_\-.]|$)/i',$e['source'])) ? ['QR campaign UTM detected'] : false; } ],

            // Ticketing and AI referrals.
            [ 'priority'=>780, 'source'=>'Eventbrite', 'name'=>'Eventbrite Referral', 'summary'=>['eventbrite domain/source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)eventbrite\.(com|co\.uk)$/') || $source_contains($e,['eventbrite'])) ? ['Eventbrite evidence detected'] : false; } ],
            [ 'priority'=>775, 'source'=>'Ticket Tailor', 'name'=>'Ticket Tailor Referral', 'summary'=>['tickettailor domain/source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)tickettailor\.com$/') || $source_contains($e,['tickettailor','ticket_tailor'])) ? ['Ticket Tailor evidence detected'] : false; } ],
            [ 'priority'=>760, 'source'=>'ChatGPT', 'name'=>'ChatGPT Referral', 'summary'=>['chatgpt.com, chat.openai.com, or ChatGPT UTM source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)(chatgpt\.com|chat\.openai\.com)$/') || $source_contains($e,['chatgpt','openai'])) ? ['ChatGPT evidence detected'] : false; } ],
            [ 'priority'=>755, 'source'=>'Perplexity', 'name'=>'Perplexity Referral', 'summary'=>['perplexity.ai or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)perplexity\.ai$/') || $source_contains($e,['perplexity'])) ? ['Perplexity evidence detected'] : false; } ],
            [ 'priority'=>750, 'source'=>'Gemini', 'name'=>'Gemini Referral', 'summary'=>['gemini.google.com, bard.google.com, or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)(gemini|bard)\.google\.com$/') || $source_contains($e,['gemini','google_bard'])) ? ['Gemini evidence detected'] : false; } ],
            [ 'priority'=>745, 'source'=>'Claude', 'name'=>'Claude Referral', 'summary'=>['claude.ai or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)claude\.ai$/') || $source_contains($e,['claude'])) ? ['Claude evidence detected'] : false; } ],
            [ 'priority'=>740, 'source'=>'Copilot', 'name'=>'Copilot Referral', 'summary'=>['copilot.microsoft.com or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)copilot\.microsoft\.com$/') || $source_contains($e,['copilot'])) ? ['Copilot evidence detected'] : false; } ],
            [ 'priority'=>735, 'source'=>'DeepSeek', 'name'=>'DeepSeek Referral', 'summary'=>['deepseek domain or source'], 'match'=>function($e)use($host,$source_contains){ return ($host($e,'/(^|\.)(chat\.)?deepseek\.com$/') || $source_contains($e,['deepseek'])) ? ['DeepSeek evidence detected'] : false; } ],

            // An explicit utm_source is authoritative business-facing evidence.
            // It must be evaluated before browser referrers so a tagged TikTok,
            // Facebook, or custom campaign is never reduced to generic Referral.
            [ 'priority'=>720, 'source'=>'Explicit UTM Source', 'name'=>'Explicit UTM Source', 'summary'=>['Any explicit utm_source not matched by a more specific paid or channel rule'], 'match'=>function($e){
                if ( ! $e['source'] ) return false;
                $raw = strtolower( trim( (string) $e['source'] ) );
                $key = trim( preg_replace( '/[^a-z0-9]+/', '_', $raw ), '_' );
                $known = [
                    'tiktok' => 'TikTok', 'tik_tok' => 'TikTok', 'tt' => 'TikTok',
                    'facebook' => 'Facebook', 'fb' => 'Facebook',
                    'instagram' => 'Instagram', 'ig' => 'Instagram',
                    'meta' => 'Facebook / Instagram',
                    'linkedin' => 'LinkedIn',
                    'reddit' => 'Reddit',
                    'pinterest' => 'Pinterest',
                    'twitter' => 'X', 'x' => 'X',
                    'youtube' => 'YouTube', 'yt' => 'YouTube',
                    'google' => 'Google',
                    'bing' => 'Bing', 'microsoft' => 'Microsoft',
                    'mailchimp' => 'Email', 'klaviyo' => 'Email', 'constant_contact' => 'Email', 'constantcontact' => 'Email', 'activecampaign' => 'Email',
                ];
                $label = $known[ $key ] ?? ucwords( str_replace( [ '_', '-' ], ' ', sanitize_text_field( $raw ) ) );
                if ( '' === trim( $label ) ) return false;
                $origin = $e['query_origins']['utm_source'] ?? '';
                return [
                    '__source' => $label,
                    'Verified by explicit UTM parameter',
                    'utm_source=' . $e['source'] . ( 'referrer_url' === $origin ? ' (recovered from referrer URL)' : '' ),
                ];
            } ],

            // Organic search and social referrals only when paid evidence is absent.
            [ 'priority'=>710, 'source'=>'Organic Google', 'name'=>'Google Organic Referrer', 'summary'=>['Google search referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)google\.[a-z.]+$/') && !$e['paid'] ? ['Google external referrer detected: '.$e['ref_host'],'No paid evidence detected'] : false; } ],
            [ 'priority'=>705, 'source'=>'Organic Bing', 'name'=>'Bing Organic Referrer', 'summary'=>['bing.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)bing\.com$/') && !$e['paid'] ? ['Bing external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>700, 'source'=>'Organic Yahoo', 'name'=>'Yahoo Organic Referrer', 'summary'=>['Yahoo search referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)search\.yahoo\.[a-z.]+$/') && !$e['paid'] ? ['Yahoo search referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>695, 'source'=>'Organic DuckDuckGo', 'name'=>'DuckDuckGo Organic Referrer', 'summary'=>['duckduckgo.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)duckduckgo\.com$/') && !$e['paid'] ? ['DuckDuckGo search referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>680, 'source'=>'Instagram Referral', 'status'=>'Inferred', 'name'=>'Instagram Referral Without Paid Evidence', 'summary'=>['instagram.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)instagram\.com$/') && !$e['paid'] ? ['Instagram external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>675, 'source'=>'Facebook Referral', 'status'=>'Inferred', 'name'=>'Facebook Referral Without Paid Evidence', 'summary'=>['facebook.com/fb.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)(facebook|fb)\.com$/') && !$e['paid'] ? ['Facebook external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>670, 'source'=>'LinkedIn Referral', 'status'=>'Inferred', 'name'=>'LinkedIn Organic Referral', 'summary'=>['linkedin.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)linkedin\.com$/') && !$e['paid'] ? ['LinkedIn external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>665, 'source'=>'TikTok Referral', 'status'=>'Inferred', 'name'=>'TikTok Organic Referral', 'summary'=>['tiktok.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)tiktok\.com$/') && !$e['paid'] ? ['TikTok external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>660, 'source'=>'Reddit Referral', 'status'=>'Inferred', 'name'=>'Reddit Organic Referral', 'summary'=>['reddit.com referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)reddit\.com$/') && !$e['paid'] ? ['Reddit external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>655, 'source'=>'Pinterest Referral', 'status'=>'Inferred', 'name'=>'Pinterest Organic Referral', 'summary'=>['pinterest domain + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)pinterest\.[a-z.]+$/') && !$e['paid'] ? ['Pinterest external referrer detected','No paid evidence detected'] : false; } ],
            [ 'priority'=>650, 'source'=>'X Referral', 'status'=>'Inferred', 'name'=>'X/Twitter Referral', 'summary'=>['x.com, twitter.com, or t.co referrer + no paid evidence'], 'match'=>function($e)use($host){ return $host($e,'/(^|\.)(x\.com|twitter\.com|t\.co)$/') && !$e['paid'] ? ['X/Twitter external referrer detected','No paid evidence detected'] : false; } ],

            [ 'priority'=>620, 'source'=>'Partner Referral', 'name'=>'Tagged Partner Referral', 'summary'=>['referral/affiliate/partner medium + source'], 'match'=>function($e){ return $e['source'] && preg_match('/referral|affiliate|partner/i',$e['medium']) ? ['Tagged referral source: '.$e['source'],'UTM medium: '.$e['medium']] : false; } ],

            // fbclid alone is intentionally not enough to choose Facebook or Instagram.
            [ 'priority'=>560, 'source'=>'Facebook / Instagram Referral', 'status'=>'Inferred', 'name'=>'Ambiguous Facebook or Instagram Click', 'summary'=>['fbclid without platform-specific evidence'], 'match'=>function($e)use($has){ return $has($e,'fbclid') ? ['Facebook/Instagram click identifier detected: fbclid','Specific platform and paid status could not be verified'] : false; } ],
            [ 'priority'=>500, 'source'=>'Referral', 'status'=>'Inferred', 'name'=>'External Website Referral', 'summary'=>['Any other external referrer'], 'match'=>function($e){ return $e['ref_host'] && empty($e['ref_is_internal']) ? [ '__source' => $this->referral_source_from_host( $e['ref_host'] ), 'External referrer: '.$e['ref_host'] ] : false; } ],
            [ 'priority'=>100, 'source'=>'Direct', 'name'=>'No Attribution Evidence', 'summary'=>['A valid visit with no external referrer, UTM, or click ID'], 'match'=>function($e){ return ! empty( $e['url'] ) && (!$e['ref_host'] || !empty($e['ref_is_internal'])) && !$e['source'] && empty($e['query']) ? ['No external referrer, UTM, or click ID detected'] : false; } ],
        ];

        usort( $rules, function( $a, $b ) { return $b['priority'] <=> $a['priority']; } );
        return $rules;
    }

    private function pretty_source_name( $source ) {
        $source = str_replace( [ '-', '_' ], ' ', sanitize_text_field( $source ) );
        return ucwords( $source );
    }

    public function ajax_regression_tests() {
        check_ajax_referer( 'cli_diagnostics', 'nonce' );
        if ( ! $this->current_user_can_access_plugin() ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        $results = [];
        foreach ( $this->regression_cases() as $case ) {
            $actual = $this->classify_url( $case['url'], $case['referrer'] );
            $actual_dimensions = [ 'source' => $actual['source'], 'channel' => $actual['channel'], 'confidence' => $actual['status'] ];
            $results[] = [ 'name'=>$case['name'], 'expected'=>$case['expected'], 'actual'=>$actual_dimensions, 'rule'=>$actual['rule'], 'pass'=>$case['expected']===$actual_dimensions ];
        }
        wp_send_json_success( $results );
    }

    private function regression_cases() {
        return [
            ['name'=>'Google referrer without paid evidence','url'=>'https://example.com/','referrer'=>'https://www.google.com/','expected'=>['source'=>'Google','channel'=>'Organic','confidence'=>'Verified']],
            ['name'=>'Google gclid','url'=>'https://example.com/?gclid=TEST','referrer'=>'','expected'=>['source'=>'Google','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Facebook referrer without paid evidence','url'=>'https://example.com/','referrer'=>'https://facebook.com/','expected'=>['source'=>'Facebook','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'Facebook referrer with ambiguous fbclid','url'=>'https://example.com/?fbclid=TEST','referrer'=>'https://facebook.com/','expected'=>['source'=>'Facebook','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'Facebook paid UTM','url'=>'https://example.com/?utm_source=facebook&utm_medium=paid_social&utm_campaign=spring','referrer'=>'https://facebook.com/','expected'=>['source'=>'Facebook','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Facebook Mobile Feed with Meta campaign evidence','url'=>'https://example.com/?utm_source=Facebook_Mobile_Feed&utm_medium=GWF-Traffic-Reel-Boost&utm_campaign=Partner-Cities-Reel-Boost&utm_id=120212016398870062&fbclid=TEST','referrer'=>'','expected'=>['source'=>'Facebook','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Facebook Mobile Feed without campaign ID stays non-paid','url'=>'https://example.com/?utm_source=Facebook_Mobile_Feed&fbclid=TEST','referrer'=>'','expected'=>['source'=>'Facebook Mobile Feed','channel'=>'Referral','confidence'=>'Inferred']],
            ['name'=>'Instagram referrer without paid evidence','url'=>'https://example.com/','referrer'=>'https://instagram.com/','expected'=>['source'=>'Instagram','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'Instagram referrer with ambiguous fbclid','url'=>'https://example.com/?fbclid=TEST','referrer'=>'https://instagram.com/','expected'=>['source'=>'Instagram','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'Instagram Reels paid UTM','url'=>'https://example.com/?utm_source=Instagram_Reels&utm_medium=paid&utm_campaign=GWF-Traffic','referrer'=>'https://www.instagram.com/','expected'=>['source'=>'Instagram','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Instagram Feed with Meta campaign evidence','url'=>'https://example.com/?utm_source=Instagram_Feed&utm_medium=Brand-Campaign&utm_id=120212016398870063&fbclid=TEST','referrer'=>'','expected'=>['source'=>'Instagram','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Ambiguous fbclid without platform evidence','url'=>'https://example.com/?fbclid=TEST','referrer'=>'','expected'=>['source'=>'Facebook / Instagram','channel'=>'Referral','confidence'=>'Inferred']],
            ['name'=>'Ambiguous Meta UTM without platform evidence','url'=>'https://example.com/?utm_source=meta','referrer'=>'','expected'=>['source'=>'Facebook / Instagram','channel'=>'Referral','confidence'=>'Inferred']],
            ['name'=>'TikTok referrer without paid evidence','url'=>'https://example.com/','referrer'=>'https://tiktok.com/','expected'=>['source'=>'TikTok','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'ChatGPT referrer','url'=>'https://example.com/','referrer'=>'https://chatgpt.com/','expected'=>['source'=>'ChatGPT','channel'=>'AI','confidence'=>'Verified']],
            ['name'=>'Generic external website referrer','url'=>'https://example.com/','referrer'=>'https://partner.example/','expected'=>['source'=>'partner.example','channel'=>'Referral','confidence'=>'Inferred']],
            ['name'=>'No usable evidence','url'=>'','referrer'=>'','expected'=>['source'=>'Unknown','channel'=>'Unknown','confidence'=>'Inferred']],
            ['name'=>'Direct visit','url'=>'https://example.com/','referrer'=>'','expected'=>['source'=>'Direct','channel'=>'Direct','confidence'=>'Verified']],
            ['name'=>'LinkedIn organic referral','url'=>'https://example.com/','referrer'=>'https://linkedin.com/','expected'=>['source'=>'LinkedIn','channel'=>'Organic','confidence'=>'Inferred']],
            ['name'=>'Reddit paid UTM','url'=>'https://example.com/?utm_source=reddit&utm_medium=cpc','referrer'=>'https://reddit.com/','expected'=>['source'=>'Reddit','channel'=>'Paid','confidence'=>'Verified']],
            ['name'=>'Bing organic referral','url'=>'https://example.com/','referrer'=>'https://bing.com/','expected'=>['source'=>'Bing','channel'=>'Organic','confidence'=>'Verified']],
            ['name'=>'Email campaign','url'=>'https://example.com/?utm_source=mailchimp&utm_medium=email','referrer'=>'','expected'=>['source'=>'Email','channel'=>'Email','confidence'=>'Verified']],
        ];
    }

    /**
     * One customer-facing vocabulary for new classifications and historical
     * metadata. Technical legacy labels remain available separately for
     * diagnostics and campaign recovery.
     */
    private function normalize_customer_attribution( $source, $channel = '', $confidence = 'Verified' ) {
        $source = trim( sanitize_text_field( (string) $source ) );
        $channel = trim( sanitize_text_field( (string) $channel ) );
        $confidence = 'Verified' === $confidence ? 'Verified' : 'Inferred';

        if ( preg_match( '/^Organic (.+)$/', $source, $match ) ) {
            $source = $match[1];
            $channel = 'Organic';
        } elseif ( preg_match( '/^(.+) Ads$/', $source, $match ) ) {
            $source = $match[1];
            $channel = 'Paid';
        } elseif ( preg_match( '/^(.+) Referral$/', $source, $match ) && ! $channel ) {
            $source = $match[1];
            $channel = 'Referral';
        }

        $source_aliases = [
            'Microsoft' => 'Bing',
            'Twitter' => 'X',
            'Facebook Referral' => 'Facebook',
            'Instagram Referral' => 'Instagram',
            'Meta' => 'Facebook / Instagram',
        ];
        if ( isset( $source_aliases[ $source ] ) ) $source = $source_aliases[ $source ];

        $channel_aliases = [
            'AI Referral' => 'AI',
            'Unpaid' => 'Organic',
            'Social' => 'Organic',
            'SMS' => 'Referral',
            'Offline' => 'Referral',
        ];
        if ( isset( $channel_aliases[ $channel ] ) ) $channel = $channel_aliases[ $channel ];

        $social_sources = [ 'Facebook', 'Instagram', 'LinkedIn', 'TikTok', 'Pinterest', 'X', 'Reddit' ];
        $search_sources = [ 'Google', 'Bing', 'Yahoo', 'DuckDuckGo' ];
        $ai_sources = [ 'ChatGPT', 'Perplexity', 'Claude', 'Gemini', 'Copilot', 'DeepSeek' ];

        if ( in_array( $source, $social_sources, true ) && 'Paid' !== $channel ) {
            $channel = 'Organic';
            $confidence = 'Inferred';
        } elseif ( in_array( $source, $search_sources, true ) && 'Paid' !== $channel ) {
            $channel = 'Organic';
            $confidence = 'Verified';
        } elseif ( in_array( $source, $ai_sources, true ) ) {
            $channel = 'AI';
            $confidence = 'Verified';
        } elseif ( 'Email' === $source ) {
            $channel = 'Email';
            $confidence = 'Verified';
        } elseif ( 'Direct' === $source ) {
            $channel = 'Direct';
            $confidence = 'Verified';
        } elseif ( 'Unknown' === $source || '' === $source ) {
            $source = 'Unknown';
            $channel = 'Unknown';
            $confidence = 'Inferred';
        } elseif ( ! in_array( $channel, [ 'Paid', 'Organic', 'Direct', 'Email', 'Referral', 'AI' ], true ) ) {
            $channel = 'Referral';
            $confidence = 'Inferred';
        }

        if ( 'Paid' === $channel ) $confidence = 'Verified';
        return [ 'source' => $source, 'channel' => $channel, 'confidence' => $confidence ];
    }

    private function customer_evidence_message( $source, $channel, $confidence ) {
        if ( 'Paid' === $channel ) return 'Verified ' . $source . ' Paid visit based on explicit campaign or advertising identifiers.';
        if ( 'Organic' === $channel && in_array( $source, [ 'Facebook', 'Instagram', 'LinkedIn', 'TikTok', 'Pinterest', 'X', 'Reddit' ], true ) ) {
            return $source . ' referral detected. No paid advertising identifiers were present, so this visit was classified as Organic.';
        }
        if ( 'Organic' === $channel && in_array( $source, [ 'Google', 'Bing', 'Yahoo', 'DuckDuckGo' ], true ) ) {
            return 'Verified ' . $source . ' Organic visit. No paid advertising identifiers were detected.';
        }
        if ( 'AI' === $channel ) return 'Verified ' . $source . ' AI referral.';
        if ( 'Email' === $channel ) return 'Verified Email campaign visit based on explicit campaign evidence.';
        if ( 'Direct' === $channel ) return 'Verified Direct visit with no external referral or campaign identifiers.';
        if ( 'Referral' === $channel ) {
            return ( 'Verified' === $confidence ? 'Verified' : 'Inferred' ) . ' referral visit from ' . $source . '.';
        }
        return 'No usable attribution evidence was available, so the source and channel remain Unknown.';
    }

    private function referral_source_from_host( $host ) {
        $host = strtolower( preg_replace( '/^www\./', '', sanitize_text_field( (string) $host ) ) );
        return $host ?: 'Referral';
    }

    private function customer_attribution_for_entry( $entry_id ) {
        $source = gform_get_meta( $entry_id, 'cli_lead_source' ) ?: 'Unknown';
        $channel = gform_get_meta( $entry_id, 'cli_channel' );
        $confidence = gform_get_meta( $entry_id, 'cli_attribution_status' ) ?: 'Verified';
        if ( ! $channel ) {
            $dimensions = $this->source_channel_from_legacy_label( $source, $confidence );
            $source = $dimensions['source'];
            $channel = $dimensions['channel'];
        }
        return $this->normalize_customer_attribution( $source, $channel, $confidence );
    }

    /**
     * Convert the pre-v4 combined attribution label into separate business
     * dimensions. The legacy label remains available for rule diagnostics and
     * backwards compatibility, while Source and Channel answer two distinct
     * owner questions: which platform/source, and what type of traffic.
     */
    private function source_channel_from_legacy_label( $label, $status = 'Verified' ) {
        $label = trim( sanitize_text_field( (string) $label ) );
        $map = [
            'Google Ads' => [ 'Google', 'Paid' ],
            'Organic Google' => [ 'Google', 'Organic' ],
            'Google Business Profile' => [ 'Google Business Profile', 'Organic' ],
            'Google Maps' => [ 'Google Maps', 'Organic' ],
            'Microsoft Ads' => [ 'Bing', 'Paid' ],
            'Yahoo Ads' => [ 'Yahoo', 'Paid' ],
            'DuckDuckGo Ads' => [ 'DuckDuckGo', 'Paid' ],
            'Organic Bing' => [ 'Bing', 'Organic' ],
            'Organic Yahoo' => [ 'Yahoo', 'Organic' ],
            'Organic DuckDuckGo' => [ 'DuckDuckGo', 'Organic' ],
            'Facebook Ads' => [ 'Facebook', 'Paid' ],
            'Facebook Referral' => [ 'Facebook', 'Organic' ],
            'Instagram Ads' => [ 'Instagram', 'Paid' ],
            'Instagram Referral' => [ 'Instagram', 'Organic' ],
            'Meta Ads' => [ 'Facebook / Instagram', 'Paid' ],
            'Meta Referral' => [ 'Facebook / Instagram', 'Referral' ],
            'Facebook / Instagram Ads' => [ 'Facebook / Instagram', 'Paid' ],
            'Facebook / Instagram Referral' => [ 'Facebook / Instagram', 'Referral' ],
            'LinkedIn Ads' => [ 'LinkedIn', 'Paid' ],
            'LinkedIn Referral' => [ 'LinkedIn', 'Organic' ],
            'TikTok Ads' => [ 'TikTok', 'Paid' ],
            'TikTok Referral' => [ 'TikTok', 'Organic' ],
            'Reddit Ads' => [ 'Reddit', 'Paid' ],
            'Reddit Referral' => [ 'Reddit', 'Organic' ],
            'Pinterest Ads' => [ 'Pinterest', 'Paid' ],
            'Pinterest Referral' => [ 'Pinterest', 'Organic' ],
            'X Ads' => [ 'X', 'Paid' ],
            'X Referral' => [ 'X', 'Organic' ],
            'Email' => [ 'Email', 'Email' ],
            'SMS' => [ 'SMS', 'SMS' ],
            'QR Code' => [ 'QR Code', 'Offline' ],
            'ChatGPT' => [ 'ChatGPT', 'AI' ],
            'Perplexity' => [ 'Perplexity', 'AI' ],
            'Gemini' => [ 'Gemini', 'AI' ],
            'Claude' => [ 'Claude', 'AI' ],
            'Copilot' => [ 'Copilot', 'AI' ],
            'DeepSeek' => [ 'DeepSeek', 'AI' ],
            'Later Link in Bio' => [ 'Later Link in Bio', 'Referral' ],
            'Linktree' => [ 'Linktree', 'Referral' ],
            'Beacons' => [ 'Beacons', 'Referral' ],
            'Campsite' => [ 'Campsite', 'Referral' ],
            'Lnk.Bio' => [ 'Lnk.Bio', 'Referral' ],
            'Eventbrite' => [ 'Eventbrite', 'Referral' ],
            'Ticket Tailor' => [ 'Ticket Tailor', 'Referral' ],
            'Partner Referral' => [ 'Partner', 'Referral' ],
            'Referral' => [ 'Referral', 'Referral' ],
            'Direct' => [ 'Direct', 'Direct' ],
            'Unknown' => [ 'Unknown', 'Unknown' ],
        ];
        if ( isset( $map[ $label ] ) ) return [ 'source' => $map[ $label ][0], 'channel' => $map[ $label ][1] ];
        if ( preg_match( '/ Ads$/', $label ) ) return [ 'source' => preg_replace( '/ Ads$/', '', $label ), 'channel' => 'Paid' ];
        if ( preg_match( '/^Organic (.+)$/', $label, $m ) ) return [ 'source' => $m[1], 'channel' => 'Organic' ];
        if ( preg_match( '/^(.+) Referral$/', $label, $m ) ) return [ 'source' => $m[1], 'channel' => 'Referral' ];
        return [ 'source' => $label ?: 'Unknown', 'channel' => 'Unknown' ];
    }

    private function canonical_source( $source ) {
        $raw = trim( sanitize_text_field( (string) $source ) );
        if ( '' === $raw ) return 'Unknown';
        $key = strtolower( preg_replace( '/[^a-z0-9]+/', '_', $raw ) );
        $key = trim( $key, '_' );
        $aliases = [
            'later_linkinbio' => 'Later Link in Bio',
            'later_link_in_bio' => 'Later Link in Bio',
            'linkin_bio' => 'Later Link in Bio',
            'google_ads' => 'Google Ads',
            'organic_google' => 'Organic Google',
            'organic_bing' => 'Organic Bing',
            'organic_yahoo' => 'Organic Yahoo',
            'organic_duckduckgo' => 'Organic DuckDuckGo',
            'facebook_ads' => 'Facebook Ads',
            'instagram_ads' => 'Instagram Ads',
            'facebook_referral' => 'Facebook Referral',
            'instagram_referral' => 'Instagram Referral',
            'tiktok' => 'TikTok',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn',
            'youtube' => 'YouTube',
            'meta' => 'Facebook / Instagram',
            'meta_ads' => 'Facebook / Instagram Ads',
            'meta_referral' => 'Facebook / Instagram Referral',
            'unknown' => 'Unknown',
            'direct' => 'Direct',
            'referral' => 'Referral',
        ];
        return $aliases[ $key ] ?? $raw;
    }

    private function is_legacy_source( $source ) {
        $raw = trim( (string) $source );
        if ( '' === $raw ) return true;
        $key = strtolower( preg_replace( '/[^a-z0-9]+/', '_', $raw ) );
        $key = trim( $key, '_' );
        return in_array( $key, [ 'unknown', 'meta_ads', 'later_linkinbio', 'later_link_in_bio', 'linkin_bio' ], true );
    }

    private function is_safe_reclassification( $stored, $current, $new, $classification ) {
        $stored = trim( (string) $stored );
        $status = $classification['status'] ?? 'Unclassified';

        // Pure label normalization is always safe.
        if ( $current === $new && $stored !== $new ) return true;

        // Never replace a historical source with Unknown or Direct merely
        // because older tracking did not retain enough evidence.
        if ( in_array( $new, [ 'Unknown', 'Direct' ], true ) ) return false;

        // Business-useful inferred referrals may safely upgrade blank or
        // Unknown records while preserving explicit Inferred confidence.
        if ( 'Inferred' === $status && in_array( $new, [ 'Facebook', 'Instagram', 'Facebook / Instagram', 'LinkedIn', 'TikTok', 'Pinterest', 'X', 'Reddit' ], true ) ) {
            return '' === $stored || 'Unknown' === $current;
        }
        if ( 'Verified' !== $status ) return false;

        // Legacy Meta Ads can only be split when the saved evidence proves the
        // specific Meta platform.
        if ( 'Meta Ads' === $current ) return in_array( $new, [ 'Facebook Ads', 'Instagram Ads' ], true );

        // Blank and Unknown sources may be upgraded by verified saved evidence.
        if ( '' === $stored || 'Unknown' === $current ) return true;

        // All-source mode may update an existing classification when the
        // current catalog reaches another verified, evidence-backed result.
        return $current !== $new;
    }

    private function sanitize_month_key( $value ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $value ) ? $value : '';
    }

    private function month_options_html( $selected ) {
        $timezone = wp_timezone();
        $month = new DateTimeImmutable( 'first day of this month 00:00:00', $timezone );
        $html = '';
        for ( $i = 0; $i < 60; $i++ ) {
            $value = $month->format( 'Y-m' );
            $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( wp_date( 'F Y', $month->getTimestamp(), $timezone ) ) . '</option>';
            $month = $month->modify( '-1 month' );
        }
        return $html;
    }

    private function sanitize_comparison_mode( $value ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, [ 'off', 'previous', 'year_over_year' ], true ) ? $value : '';
    }

    private function comparison_mode_label( $mode ) {
        if ( 'year_over_year' === $mode ) return 'Compared with same period last year';
        if ( 'previous' === $mode ) return 'Compared with previous period';
        return '';
    }

    private function comparison_control_html( $mode, $id, $disabled = false ) {
        $mode = $this->sanitize_comparison_mode( $mode ) ?: 'off';
        $disabled_attribute = $disabled ? ' disabled' : '';
        $html = '<fieldset class="cli-comparison-control" id="cli-comparison-' . esc_attr( sanitize_html_class( $id ) ) . '"><legend>Comparison</legend>';
        foreach ( [ 'off'=>'Off', 'previous'=>'Previous period', 'year_over_year'=>'Same period last year' ] as $value=>$label ) {
            $html .= '<label><input type="radio" name="cli_comparison" value="' . esc_attr( $value ) . '" ' . checked( $mode, $value, false ) . $disabled_attribute . '> ' . esc_html( $label ) . '</label>';
        }
        return $html . '</fieldset>';
    }

    private function comparison_reporting_period( $period, $mode ) {
        $mode = $this->sanitize_comparison_mode( $mode );
        if ( 'previous' === $mode ) return $this->previous_reporting_period( $period );
        if ( 'year_over_year' === $mode ) return $this->same_period_last_year( $period );
        return null;
    }

    private function same_period_last_year( $period ) {
        if ( empty( $period['start_local'] ) || empty( $period['end_local'] ) ) return null;
        $timezone = wp_timezone();
        $start = $this->shift_date_to_previous_year( $period['start_local'] );
        $end = $this->shift_date_to_previous_year( $period['end_local'] );
        return [
            'key' => 'year_over_year',
            'display' => wp_date( 'M j, Y', $start->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M j, Y', $end->getTimestamp(), $timezone ),
            'start_local' => $start,
            'end_local' => $end,
            'start_utc' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'end_utc' => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }

    private function shift_date_to_previous_year( $date ) {
        $target_year = (int) $date->format( 'Y' ) - 1;
        $month = (int) $date->format( 'n' );
        $day = (int) $date->format( 'j' );
        $month_start = $date->setDate( $target_year, $month, 1 );
        $target_day = min( $day, (int) $month_start->format( 't' ) );
        return $date->setDate( $target_year, $month, $target_day );
    }

    private function reporting_page_context( $key ) {
        $allowed = [ 'rolling_7', 'rolling_30', 'this_month', 'last_month', 'last_2_months', 'last_3_months', 'year_to_date', 'all_time', 'custom_months' ];
        $user_id = get_current_user_id();
        $prefix = 'cli_' . sanitize_key( $key ) . '_';
        $saved_range = (string) get_user_meta( $user_id, $prefix . 'range', true );
        if ( ! in_array( $saved_range, $allowed, true ) ) $saved_range = 'rolling_30';
        $range = isset( $_GET['cli_range'] ) ? sanitize_key( wp_unslash( $_GET['cli_range'] ) ) : $saved_range;
        if ( ! in_array( $range, $allowed, true ) ) $range = 'rolling_30';
        $default_month = wp_date( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) );
        $start_month = isset( $_GET['cli_start_month'] ) ? $this->sanitize_month_key( wp_unslash( $_GET['cli_start_month'] ) ) : $this->sanitize_month_key( get_user_meta( $user_id, $prefix . 'start_month', true ) );
        $end_month = isset( $_GET['cli_end_month'] ) ? $this->sanitize_month_key( wp_unslash( $_GET['cli_end_month'] ) ) : $this->sanitize_month_key( get_user_meta( $user_id, $prefix . 'end_month', true ) );
        if ( ! $start_month ) $start_month = $default_month;
        if ( ! $end_month ) $end_month = $start_month;
        if ( $start_month > $end_month ) { $swap = $start_month; $start_month = $end_month; $end_month = $swap; }
        $saved_comparison = $this->sanitize_comparison_mode( get_user_meta( $user_id, $prefix . 'comparison', true ) );
        if ( '' === $saved_comparison ) $saved_comparison = '1' === (string) get_user_meta( $user_id, $prefix . 'compare', true ) ? 'previous' : 'off';
        if ( isset( $_GET['cli_filter_submit'] ) ) {
            $comparison_mode = isset( $_GET['cli_comparison'] ) ? $this->sanitize_comparison_mode( wp_unslash( $_GET['cli_comparison'] ) ) : ( isset( $_GET['cli_compare'] ) ? 'previous' : 'off' );
        } else {
            $comparison_mode = $saved_comparison;
        }
        if ( '' === $comparison_mode ) $comparison_mode = 'off';
        if ( 'all_time' === $range ) $comparison_mode = 'off';
        $compare = 'off' !== $comparison_mode;
        update_user_meta( $user_id, $prefix . 'range', $range );
        update_user_meta( $user_id, $prefix . 'start_month', $start_month );
        update_user_meta( $user_id, $prefix . 'end_month', $end_month );
        update_user_meta( $user_id, $prefix . 'comparison', $comparison_mode );
        update_user_meta( $user_id, $prefix . 'compare', $compare ? '1' : '0' );
        $period = $this->resolve_reporting_period( $range, $start_month, $end_month );
        return [
            'range' => $range,
            'start_month' => $start_month,
            'end_month' => $end_month,
            'compare' => $compare,
            'comparison_mode' => $comparison_mode,
            'comparison_label' => $this->comparison_mode_label( $comparison_mode ),
            'period' => $period,
            'previous' => $this->comparison_reporting_period( $period, $comparison_mode ),
            'dom_id' => sanitize_html_class( $key ),
        ];
    }

    private function render_reporting_period_filter( $page, $context, $extra_fields = '', $show_compare = true ) {
        $period = $context['period'];
        $previous = $context['previous'];
        $id = $context['dom_id'];
        echo '<section class="cli-card cli-reporting-period"><div class="cli-period-heading"><div><span class="cli-period-eyebrow">Reporting Period</span><h2>' . esc_html( $period['label'] ) . '</h2><p>' . esc_html( $period['display'] ) . '</p></div>';
        if ( $previous ) echo '<div class="cli-compare-caption"><strong>' . esc_html( $context['comparison_label'] ) . '</strong><span>' . esc_html( $previous['display'] ) . '</span></div>';
        echo '</div><form method="get" class="cli-range-filter"><input type="hidden" name="page" value="' . esc_attr( $page ) . '"><input type="hidden" name="cli_filter_submit" value="1"><div class="cli-filter-field"><label for="cli-range-' . esc_attr( $id ) . '">Period</label><select name="cli_range" id="cli-range-' . esc_attr( $id ) . '">';
        foreach ( [ 'rolling_7'=>'Last 7 Days', 'rolling_30'=>'Last 30 Days', 'this_month'=>'This Month', 'last_month'=>'Last Month', 'last_2_months'=>'Last 2 Months', 'last_3_months'=>'Last 3 Months', 'year_to_date'=>'Year to Date', 'all_time'=>'All Time', 'custom_months'=>'Choose Month Range' ] as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $context['range'], $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div><div class="cli-custom-months' . ( 'custom_months' === $context['range'] ? ' is-visible' : '' ) . '"><div class="cli-filter-field"><label for="cli-start-' . esc_attr( $id ) . '">From</label><select name="cli_start_month" id="cli-start-' . esc_attr( $id ) . '">' . $this->month_options_html( $context['start_month'] ) . '</select></div><div class="cli-filter-field"><label for="cli-end-' . esc_attr( $id ) . '">Through</label><select name="cli_end_month" id="cli-end-' . esc_attr( $id ) . '">' . $this->month_options_html( $context['end_month'] ) . '</select></div></div>';
        echo $extra_fields;
        if ( $show_compare ) echo $this->comparison_control_html( $context['comparison_mode'], $id, 'all_time' === $context['range'] );
        echo '<button type="submit" class="button button-primary">Apply</button></form></section>';
        echo '<script>(function(){var range=document.getElementById("cli-range-' . esc_js( $id ) . '"),section=range&&range.closest(".cli-reporting-period"),months=section&&section.querySelector(".cli-custom-months"),choices=section&&section.querySelectorAll(".cli-comparison-control input");if(!range)return;function sync(){var custom=range.value==="custom_months",disabled=range.value==="all_time";months.classList.toggle("is-visible",custom);choices.forEach(function(choice){choice.disabled=disabled;if(disabled)choice.checked=choice.value==="off";});}range.addEventListener("change",sync);sync();})();</script>';
    }

    private function resolve_reporting_period( $range, $start_month = '', $end_month = '', $start_date = '', $end_date = '' ) {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable( 'now', $timezone );
        $start = null;
        $end = $now;
        $label = 'Last 30 Days';

        switch ( $range ) {
            case 'this_month':
                $start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                $label = 'This Month';
                break;
            case 'last_month':
                $start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                $end = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
                $label = 'Last Month';
                break;
            case 'last_2_months':
                $start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                $label = 'Last 2 Months';
                break;
            case 'last_3_months':
                $start = $now->modify( 'first day of -2 months' )->setTime( 0, 0, 0 );
                $label = 'Last 3 Months';
                break;
            case 'year_to_date':
                $start = $now->setDate( (int) $now->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 );
                $label = 'Year to Date';
                break;
            case 'all_time':
                $start = null;
                $end = null;
                $label = 'All Time';
                break;
            case 'custom_dates':
                $today_key = $now->format( 'Y-m-d' );
                $start_key = $this->sanitize_reporting_date( $start_date ) ?: $now->modify( '-29 days' )->format( 'Y-m-d' );
                $end_key = $this->sanitize_reporting_date( $end_date ) ?: $today_key;
                if ( $start_key > $today_key ) $start_key = $today_key;
                if ( $end_key > $today_key ) $end_key = $today_key;
                if ( $start_key > $end_key ) { $swap = $start_key; $start_key = $end_key; $end_key = $swap; }
                $start = new DateTimeImmutable( $start_key . ' 00:00:00', $timezone );
                $end = new DateTimeImmutable( $end_key . ' 23:59:59', $timezone );
                $label = 'Custom Date Range';
                break;
            case 'custom_months':
                $start_key = $this->sanitize_month_key( $start_month ) ?: $now->modify( 'first day of last month' )->format( 'Y-m' );
                $end_key = $this->sanitize_month_key( $end_month ) ?: $start_key;
                if ( $start_key > $end_key ) { $swap = $start_key; $start_key = $end_key; $end_key = $swap; }
                $current_key = $now->format( 'Y-m' );
                if ( $end_key > $current_key ) $end_key = $current_key;
                $start = new DateTimeImmutable( $start_key . '-01 00:00:00', $timezone );
                $end_month_date = new DateTimeImmutable( $end_key . '-01 00:00:00', $timezone );
                $end = $end_key === $current_key ? $now : $end_month_date->modify( 'last day of this month' )->setTime( 23, 59, 59 );
                $label = $start_key === $end_key ? wp_date( 'F Y', $start->getTimestamp(), $timezone ) : wp_date( 'M Y', $start->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M Y', $end->getTimestamp(), $timezone );
                break;
            case 'rolling_7':
                $start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
                $label = 'Last 7 Days';
                break;
            case 'rolling_30':
            default:
                $start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
                $label = 'Last 30 Days';
                break;
        }

        $display = $start ? wp_date( 'M j, Y', $start->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M j, Y', $end->getTimestamp(), $timezone ) : 'All recorded leads';
        return [
            'key' => $range,
            'label' => $label,
            'display' => $display,
            'start_local' => $start,
            'end_local' => $end,
            'start_utc' => $start ? $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
            'end_utc' => $end ? $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null,
        ];
    }

    private function previous_reporting_period( $period ) {
        if ( empty( $period['start_local'] ) || empty( $period['end_local'] ) ) return null;
        $timezone = wp_timezone();
        $start = $period['start_local'];
        $end = $period['end_local'];
        $key = $period['key'] ?? '';

        if ( 'last_month' === $key ) {
            $previous_start = $start->modify( '-1 month' )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $previous_end = $previous_start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
        } elseif ( 'this_month' === $key ) {
            $previous_start = $start->modify( '-1 month' );
            $days_elapsed = max( 0, (int) $start->diff( $end )->format( '%a' ) );
            $previous_end = $previous_start->modify( '+' . $days_elapsed . ' days' )->setTime( (int) $end->format( 'H' ), (int) $end->format( 'i' ), (int) $end->format( 's' ) );
            $last_previous_day = $previous_start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
            if ( $previous_end > $last_previous_day ) $previous_end = $last_previous_day;
        } elseif ( in_array( $key, [ 'last_2_months', 'last_3_months', 'custom_months' ], true ) ) {
            $month_count = ( (int) $end->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12 + (int) $end->format( 'n' ) - (int) $start->format( 'n' ) + 1;
            $previous_end = $start->modify( '-1 second' );
            $previous_start = $start->modify( '-' . max( 1, $month_count ) . ' months' )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
        } elseif ( 'year_to_date' === $key ) {
            $previous_start = $start->modify( '-1 year' );
            $previous_end = $end->modify( '-1 year' );
        } else {
            $duration = max( 1, $end->getTimestamp() - $start->getTimestamp() + 1 );
            $previous_end = $start->modify( '-1 second' );
            $previous_start = $previous_end->modify( '-' . ( $duration - 1 ) . ' seconds' );
        }

        return [
            'display' => wp_date( 'M j, Y', $previous_start->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M j, Y', $previous_end->getTimestamp(), $timezone ),
            'start_local' => $previous_start,
            'end_local' => $previous_end,
            'start_utc' => $previous_start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'end_utc' => $previous_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }

    private function get_stats( $days ) {
        if ( $days <= 0 ) return $this->get_stats_for_period( null, null );
        $period = $this->reporting_days_period( $days );
        return $this->get_stats_for_period( $period['start_utc'], $period['end_utc'] );
    }

    private function reporting_days_period( $days ) {
        $timezone = wp_timezone();
        $end = new DateTimeImmutable( 'now', $timezone );
        $start = $end->modify( '-' . max( 0, $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
        return [
            'start_utc' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            'end_utc' => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }

    private function get_stats_for_period( $start_utc = null, $end_utc = null ) {
        $sets = $this->get_stats_for_periods( [ 'requested' => [ 'start_utc' => $start_utc, 'end_utc' => $end_utc ] ] );
        return $sets['requested'];
    }

    /**
     * Aggregate multiple reporting windows in one bounded pass. Each GFAPI
     * page is paired with one bulk metadata query instead of per-entry reads.
     */
    private function get_stats_for_periods( $periods, $filters = [] ) {
        $channel_filter = sanitize_text_field( (string) ( $filters['channel'] ?? '' ) );
        if ( ! in_array( $channel_filter, [ 'Paid', 'Organic', 'Direct', 'Email', 'Referral', 'AI', 'Unknown' ], true ) ) $channel_filter = '';
        $source_filter = substr( sanitize_text_field( (string) ( $filters['source'] ?? '' ) ), 0, 191 );
        $results = [];
        $pending = [];
        foreach ( (array) $periods as $name => $period ) {
            $start_utc = ! empty( $period['start_utc'] ) ? (string) $period['start_utc'] : null;
            $end_utc = ! empty( $period['end_utc'] ) ? (string) $period['end_utc'] : null;
            $cache_key = $this->report_stats_cache_key( $start_utc, $end_utc, $channel_filter, $source_filter );
            if ( isset( $this->report_stats_request_cache[ $cache_key ] ) ) {
                $results[ $name ] = $this->report_stats_request_cache[ $cache_key ];
                continue;
            }
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && isset( $cached['total'] ) ) {
                $this->report_stats_request_cache[ $cache_key ] = $cached;
                $results[ $name ] = $cached;
                continue;
            }
            $pending[ $name ] = [
                'start_utc' => $start_utc,
                'end_utc' => $end_utc,
                'cache_key' => $cache_key,
                'stats' => $this->empty_report_stats(),
            ];
        }

        if ( $pending && class_exists( 'GFAPI' ) ) {
            $query_start = null;
            $query_end = null;
            foreach ( $pending as $period ) {
                if ( null === $period['start_utc'] ) {
                    $query_start = null;
                    break;
                }
                if ( null === $query_start || $period['start_utc'] < $query_start ) $query_start = $period['start_utc'];
            }
            foreach ( $pending as $period ) {
                if ( null === $period['end_utc'] ) {
                    $query_end = null;
                    break;
                }
                if ( null === $query_end || $period['end_utc'] > $query_end ) $query_end = $period['end_utc'];
            }

            $criteria = [ 'status' => 'active' ];
            if ( $query_start ) $criteria['start_date'] = $query_start;
            if ( $query_end ) $criteria['end_date'] = $query_end;
            $campaign_mappings = $this->get_google_campaign_mappings();
            $exclude_unknowns = 'exclude' === get_option( 'cli_reporting_unknown_policy', 'include' );

            foreach ( GFAPI::get_forms( true ) as $form ) {
                $offset = 0;
                do {
                    $entries = GFAPI::get_entries( (int) $form['id'], $criteria, null, [ 'offset' => $offset, 'page_size' => 200 ] );
                    if ( is_wp_error( $entries ) || ! $entries ) break;
                    $entry_ids = array_map( function( $entry ) { return (int) rgar( $entry, 'id' ); }, $entries );
                    $meta_by_entry = $this->load_reporting_meta( $entry_ids );
                    foreach ( $entries as $entry ) {
                        $id = (int) rgar( $entry, 'id' );
                        $meta = $meta_by_entry[ $id ] ?? [];
                        $date = (string) rgar( $entry, 'date_created' );
                        $report_entry = $this->prepare_reporting_entry( $entry, $form, $meta, $campaign_mappings );
                        if ( $exclude_unknowns && 'Unknown' === $report_entry['source'] ) continue;
                        if ( $channel_filter && 0 !== strcasecmp( $channel_filter, $report_entry['channel'] ) ) continue;
                        if ( $source_filter && 0 !== strcasecmp( $source_filter, $report_entry['source'] ) ) continue;
                        foreach ( $pending as &$pending_period ) {
                            if ( $pending_period['start_utc'] && $date < $pending_period['start_utc'] ) continue;
                            if ( $pending_period['end_utc'] && $date > $pending_period['end_utc'] ) continue;
                            $this->add_reporting_entry_to_stats( $pending_period['stats'], $report_entry );
                        }
                        unset( $pending_period );
                    }
                    $offset += count( $entries );
                } while ( count( $entries ) === 200 );
            }
        }

        foreach ( $pending as $name => $period ) {
            $stats = $this->sort_report_stats( $period['stats'] );
            $this->report_stats_request_cache[ $period['cache_key'] ] = $stats;
            set_transient( $period['cache_key'], $stats, $this->report_stats_cache_ttl() );
            $results[ $name ] = $stats;
        }

        $ordered = [];
        foreach ( array_keys( (array) $periods ) as $name ) $ordered[ $name ] = $results[ $name ] ?? $this->empty_report_stats();
        return $ordered;
    }

    private function empty_report_stats() {
        $sources = []; $channels = []; $detected_sources = []; $campaigns = []; $states = []; $paid_sources = []; $paid_states = [];
        $landing_pages = []; $conversion_pages = []; $assisted_pages = []; $viewed_pages = []; $journeys = [];
        $total = 0; $paid_total = 0; $campaign_total = 0; $paid_campaign_total = 0;
        return compact( 'sources', 'channels', 'detected_sources', 'campaigns', 'states', 'paid_sources', 'paid_states', 'landing_pages', 'conversion_pages', 'assisted_pages', 'viewed_pages', 'journeys', 'total', 'paid_total', 'campaign_total', 'paid_campaign_total' );
    }

    private function report_stats_cache_key( $start_utc, $end_utc, $channel = '', $source = '' ) {
        $generation = (string) get_option( 'cli_reporting_cache_generation', '1' );
        return 'cli_rs_' . md5( CLI_REPORT_CACHE_SCHEMA . '|' . CLI_CATALOG_VERSION . '|' . $generation . '|' . (string) $start_utc . '|' . (string) $end_utc . '|' . sanitize_text_field( (string) $channel ) . '|' . sanitize_text_field( (string) $source ) );
    }

    private function report_stats_cache_ttl() {
        $ttl = (int) apply_filters( 'cli_reporting_cache_ttl', 5 * MINUTE_IN_SECONDS );
        return max( 30, min( DAY_IN_SECONDS, $ttl ) );
    }

    public function invalidate_reporting_cache() {
        $generation = max( 1, (int) get_option( 'cli_reporting_cache_generation', 1 ) + 1 );
        update_option( 'cli_reporting_cache_generation', $generation, false );
        $this->report_stats_request_cache = [];
    }

    private function reporting_meta_keys() {
        return [
            'cli_lead_source', 'cli_channel', 'cli_attribution_status',
            'cli_first_source', 'cli_first_channel', 'cli_last_source', 'cli_last_channel',
            'cli_campaign_label', 'cli_campaign', 'cli_campaign_id', 'cli_campaign_source',
            'cli_lead_state', 'cli_landing_page', 'cli_conversion_page', 'cli_payload',
        ];
    }

    private function load_reporting_meta( $entry_ids ) {
        $entry_ids = array_values( array_filter( array_map( 'absint', (array) $entry_ids ) ) );
        $meta = [];
        foreach ( $entry_ids as $entry_id ) $meta[ $entry_id ] = [];
        if ( ! $entry_ids ) return $meta;

        global $wpdb;
        $table = '';
        if ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_entry_meta_table_name' ) ) {
            $table = GFFormsModel::get_entry_meta_table_name();
        } elseif ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_lead_meta_table_name' ) ) {
            $table = GFFormsModel::get_lead_meta_table_name();
        }

        if ( $wpdb && $table && preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            $keys = $this->reporting_meta_keys();
            $id_placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
            $key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql = $wpdb->prepare(
                "SELECT id, entry_id, meta_key, meta_value FROM {$table} WHERE entry_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders}) ORDER BY id ASC",
                array_merge( $entry_ids, $keys )
            );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $entry_id = (int) $row['entry_id'];
                    if ( isset( $meta[ $entry_id ] ) ) $meta[ $entry_id ][ $row['meta_key'] ] = $row['meta_value'];
                }
                return $meta;
            }
        }

        foreach ( $entry_ids as $entry_id ) {
            foreach ( $this->reporting_meta_keys() as $key ) $meta[ $entry_id ][ $key ] = gform_get_meta( $entry_id, $key );
        }
        return $meta;
    }

    private function prepare_reporting_entry( $entry, $form, $meta, $campaign_mappings ) {
        $source = $meta['cli_lead_source'] ?? 'Unknown';
        $channel = $meta['cli_channel'] ?? '';
        $confidence = $meta['cli_attribution_status'] ?? 'Verified';
        if ( ! $channel ) {
            $dimensions = $this->source_channel_from_legacy_label( $source, $confidence );
            $source = $dimensions['source'];
            $channel = $dimensions['channel'];
        }
        $attribution = $this->normalize_customer_attribution( $source, $channel, $confidence );
        $payload = json_decode( (string) ( $meta['cli_payload'] ?? '' ), true );
        $payload = $this->enrich_attribution_from_entry_fields( is_array( $payload ) ? $payload : [], $entry, $form );
        $first_touch = is_array( $payload ) && ! empty( $payload['first_touch'] ) && is_array( $payload['first_touch'] ) ? $payload['first_touch'] : [];
        $last_touch = is_array( $payload ) && ! empty( $payload['last_touch'] ) && is_array( $payload['last_touch'] ) ? $payload['last_touch'] : [];
        $first_current = $first_touch ? $this->classify_touch( $first_touch ) : [];
        $last_current = $last_touch ? $this->classify_touch( $last_touch, $first_touch ) : [];

        // Some historical records retain the normalized First Touch and Last
        // Touch dimensions even when their browser payload is unavailable or
        // incomplete. Use those dimensions only as a reporting fallback so a
        // known paid acquisition is not silently reduced to the later organic
        // return stored in the legacy primary fields.
        if ( empty( $first_current ) || in_array( $first_current['source'] ?? 'Unknown', [ 'Unknown', 'Direct' ], true ) ) {
            $stored_first_source = trim( (string) ( $meta['cli_first_source'] ?? '' ) );
            $stored_first_channel = trim( (string) ( $meta['cli_first_channel'] ?? '' ) );
            if ( $stored_first_source || $stored_first_channel ) {
                $first_current = $this->normalize_customer_attribution( $stored_first_source, $stored_first_channel, $confidence );
            }
        }
        if ( empty( $last_current ) || in_array( $last_current['source'] ?? 'Unknown', [ 'Unknown', 'Direct' ], true ) ) {
            $stored_last_source = trim( (string) ( $meta['cli_last_source'] ?? '' ) );
            $stored_last_channel = trim( (string) ( $meta['cli_last_channel'] ?? '' ) );
            if ( $stored_last_source || $stored_last_channel ) {
                $last_current = $this->normalize_customer_attribution( $stored_last_source, $stored_last_channel, $confidence );
            }
        }

        if ( $first_current || $last_current ) {
            $current = $this->last_non_direct_attribution( $first_current, $last_current );
            if ( ! in_array( $current['source'], [ 'Unknown', 'Direct' ], true ) || in_array( $attribution['source'], [ 'Unknown', 'Direct' ], true ) ) {
                $attribution = $this->normalize_customer_attribution( $current['source'], $current['channel'], $current['confidence'] );
            }
        }
        $detected = $attribution;
        $attribution = $this->reporting_source_attribution( $detected['source'], $detected['channel'], $detected['confidence'] );
        $state = trim( (string) ( $meta['cli_lead_state'] ?? '' ) );
        if ( ! $state ) $state = $this->extract_lead_state( $entry, $form );
        $urls = is_array( $payload ) && ! empty( $payload['session']['visited_urls'] ) && is_array( $payload['session']['visited_urls'] ) ? $payload['session']['visited_urls'] : [];
        $viewed = [];
        foreach ( $urls as $url ) {
            $label = $this->page_report_label( $url );
            if ( $label ) $viewed[] = $label;
        }
        $journey = [];
        foreach ( $viewed as $label ) if ( ! $journey || end( $journey ) !== $label ) $journey[] = $label;
        $landing_url = ( $meta['cli_landing_page'] ?? '' ) ?: ( $first_touch['landing_url'] ?? '' );
        $conversion_url = ( $meta['cli_conversion_page'] ?? '' ) ?: ( $payload['session']['current_url'] ?? '' );
        $campaign = $this->campaign_display_label_from_meta( $meta, $campaign_mappings );
        if ( $first_touch || $last_touch ) {
            $recovered_campaign = $this->campaign_attribution_from_touches( $first_touch, $last_touch, $detected['source'] );
            $recovered_campaign_label = $this->campaign_display_label_from_meta( [
                'cli_campaign_label' => $recovered_campaign['label'] ?? '',
                'cli_campaign' => $recovered_campaign['name'] ?? '',
                'cli_campaign_id' => $recovered_campaign['id'] ?? '',
                'cli_campaign_source' => $recovered_campaign['source'] ?? '',
                'cli_lead_source' => $detected['source'],
            ], $campaign_mappings );
            if ( ! $campaign || ( 'Paid' === $detected['channel'] && $recovered_campaign_label ) ) {
                $campaign = $recovered_campaign_label;
            }
        }
        return [
            'source' => $attribution['source'],
            'channel' => $attribution['channel'],
            'detected_source' => $detected['source'],
            'detected_channel' => $detected['channel'],
            'campaign' => $campaign,
            'state' => $state,
            'landing' => $this->page_report_label( $landing_url ),
            'conversion' => $this->page_report_label( $conversion_url ),
            'viewed' => $viewed,
            'journey' => $journey,
        ];
    }

    private function add_reporting_entry_to_stats( &$stats, $entry ) {
        $source = $entry['source'];
        $channel = $entry['channel'];
        $campaign = $entry['campaign'];
        $state = $entry['state'];
        $stats['detected_sources'][ $entry['detected_source'] ] = ( $stats['detected_sources'][ $entry['detected_source'] ] ?? 0 ) + 1;
        $stats['sources'][ $source ] = ( $stats['sources'][ $source ] ?? 0 ) + 1;
        $stats['channels'][ $channel ] = ( $stats['channels'][ $channel ] ?? 0 ) + 1;
        if ( $campaign ) { $stats['campaigns'][ $campaign ] = ( $stats['campaigns'][ $campaign ] ?? 0 ) + 1; $stats['campaign_total']++; }
        if ( $state ) $stats['states'][ $state ] = ( $stats['states'][ $state ] ?? 0 ) + 1;
        if ( 'Paid' === $channel ) {
            $stats['paid_total']++;
            $stats['paid_sources'][ $source ] = ( $stats['paid_sources'][ $source ] ?? 0 ) + 1;
            if ( $campaign ) $stats['paid_campaign_total']++;
            if ( $state ) $stats['paid_states'][ $state ] = ( $stats['paid_states'][ $state ] ?? 0 ) + 1;
        }
        if ( $entry['landing'] ) $stats['landing_pages'][ $entry['landing'] ] = ( $stats['landing_pages'][ $entry['landing'] ] ?? 0 ) + 1;
        if ( $entry['conversion'] ) $stats['conversion_pages'][ $entry['conversion'] ] = ( $stats['conversion_pages'][ $entry['conversion'] ] ?? 0 ) + 1;
        foreach ( $entry['viewed'] as $label ) $stats['viewed_pages'][ $label ] = ( $stats['viewed_pages'][ $label ] ?? 0 ) + 1;
        $assists = array_values( array_unique( (array) $entry['journey'] ) );
        foreach ( $assists as $label ) {
            if ( $label && $label !== $entry['landing'] && $label !== $entry['conversion'] ) $stats['assisted_pages'][ $label ] = ( $stats['assisted_pages'][ $label ] ?? 0 ) + 1;
        }
        if ( $entry['journey'] ) {
            $journey_key = wp_json_encode( [ 'source' => $source, 'channel' => $channel, 'pages' => array_values( $entry['journey'] ) ] );
            $stats['journeys'][ $journey_key ] = ( $stats['journeys'][ $journey_key ] ?? 0 ) + 1;
        }
        $stats['total']++;
    }

    private function sort_report_stats( $stats ) {
        foreach ( [ 'sources', 'channels', 'detected_sources', 'campaigns', 'states', 'paid_sources', 'paid_states', 'landing_pages', 'conversion_pages', 'assisted_pages', 'viewed_pages', 'journeys' ] as $bucket ) arsort( $stats[ $bucket ] );
        return $stats;
    }

    private function is_paid_source( $source, $channel = '' ) {
        if ( 'Paid' === $channel ) return true;
        $dimensions = $this->source_channel_from_legacy_label( $source );
        return 'Paid' === $dimensions['channel'];
    }

    private function session_duration( $session ) {
        $start = isset( $session['session_started'] ) ? (int) $session['session_started'] : 0;
        return $start ? max( 0, time() - $start ) : 0;
    }

    private function unique_page_count( $urls ) {
        if ( ! is_array( $urls ) ) return 0;
        $unique = [];
        foreach ( $urls as $url ) {
            $clean = $this->clean_display_url( $url );
            if ( $clean ) $unique[ strtolower( untrailingslashit( $clean ) ) ] = true;
        }
        return count( $unique );
    }

    private function build_journey( $urls ) {
        if ( ! is_array( $urls ) || ! $urls ) return '';
        $steps = [];
        foreach ( $urls as $url ) {
            $label = $this->page_title_from_url( $url );
            if ( $label && ( ! $steps || end( $steps ) !== $label ) ) $steps[] = $label;
        }
        if ( count( $steps ) > 10 ) $steps = array_merge( array_slice( $steps, 0, 4 ), [ '…' ], array_slice( $steps, -5 ) );
        return implode( ' > ', $steps );
    }

    private function page_title_from_url( $url ) {
        if ( ! $url ) return '';
        $clean = $this->clean_display_url( $url );
        $post_id = $clean ? url_to_postid( $clean ) : 0;
        if ( $post_id ) return html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $path = trim( $path, '/' );
        if ( '' === $path ) return 'Home';
        $parts = array_values( array_filter( explode( '/', $path ) ) );
        $slug = end( $parts );
        return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    private function page_report_label( $url ) {
        if ( ! $url ) return '';
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $path = '/' . ltrim( $path ?: '/', '/' );
        $title = $this->page_title_from_url( $url );
        return $title ? $title . ' (' . $path . ')' : $path;
    }

    private function extract_lead_state( $entry, $form ) {
        if ( empty( $form['fields'] ) ) return '';
        foreach ( $form['fields'] as $field ) {
            $label = strtolower( trim( (string) $field->label ) );
            $type = (string) $field->type;
            if ( 'address' === $type ) {
                $value = rgar( $entry, (string) $field->id . '.4' );
                if ( $value ) return $this->normalize_state( $value );
            }
            if ( preg_match( '/(^|\\b)(state|province)(\\b|$)/i', $label ) ) {
                $value = rgar( $entry, (string) $field->id );
                if ( $value ) return $this->normalize_state( $value );
            }
        }
        return '';
    }

    private function normalize_state( $value ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        if ( '' === $value ) return '';
        $map = [
            'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming','DC'=>'District of Columbia'
        ];
        $upper = strtoupper( $value );
        if ( isset( $map[$upper] ) ) return $map[$upper];
        foreach ( $map as $name ) if ( 0 === strcasecmp( $value, $name ) ) return $name;
        return ucwords( strtolower( $value ) );
    }

    private function clean_display_url( $url ) {
        if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return $url;
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) return $url;
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : 'https://';
        return $scheme . $parts['host'] . ( $parts['path'] ?? '/' );
    }

    private function page_header( $title, $subtitle ) { echo '<div class="wrap cli-wrap"><div class="cli-header"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $subtitle ) . '</p></div>'; }
    private function page_footer() { echo '</div>'; }
    private function metric( $label, $value ) { echo '<div class="cli-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>'; }
    private function cost_per_lead_metric( $period, $paid_leads ) {
        $cpl = $this->cost_per_lead_calculation( $period, $paid_leads );
        echo '<div class="cli-metric cli-cpl-metric"><span>Average Cost Per Lead Based on Budget</span>';
        if ( $cpl['available'] ) {
            $cost = '$' . number_format_i18n( $cpl['cost_per_lead'], 2 );
            $budget = '$' . number_format_i18n( $cpl['period_budget'], 2 );
            $budget_label = 30 === (int) $cpl['days'] ? 'budget' : 'prorated budget';
            echo '<strong>' . esc_html( $cost ) . '</strong><small>Based on a ' . esc_html( $budget ) . ' ' . esc_html( $budget_label ) . ' for ' . esc_html( $cpl['days'] ) . ' days and ' . esc_html( $cpl['paid_leads'] ) . ' paid ' . ( 1 === (int) $cpl['paid_leads'] ? 'lead' : 'leads' ) . '.</small>';
        } elseif ( 'no_budget' === $cpl['reason'] ) {
            echo '<strong>Not configured</strong><small>Enter a Client 30-Day Budget in Settings.</small>';
        } elseif ( 'no_paid_leads' === $cpl['reason'] ) {
            echo '<strong>Not available</strong><small>No paid leads were recorded for this period.</small>';
        } else {
            echo '<strong>Not available</strong><small>Choose a reporting period with a defined start date.</small>';
        }
        echo '</div>';
    }

    private function percentage_change( $current, $previous ) {
        $current = (float) $current; $previous = (float) $previous;
        if ( 0.0 === $previous ) return $current > 0 ? [ 'label' => 'New', 'class' => 'is-up' ] : [ 'label' => '0%', 'class' => 'is-flat' ];
        $change = ( ( $current - $previous ) / $previous ) * 100;
        $rounded = round( $change, 1 );
        $label = ( $rounded > 0 ? '+' : '' ) . number_format_i18n( $rounded, 1 ) . '%';
        return [ 'label' => $label, 'class' => $rounded > 0 ? 'is-up' : ( $rounded < 0 ? 'is-down' : 'is-flat' ) ];
    }
    private function comparison_metric( $label, $current, $previous, $lower_is_better = false, $comparison_label = 'previous period' ) {
        $trend = $this->percentage_change( $current, $previous );
        $class = $trend['class'];
        if ( $lower_is_better && 'is-flat' !== $class ) $class = 'is-up' === $class ? 'is-down' : 'is-up';
        echo '<div class="cli-metric cli-comparison-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $current ) . '</strong><small class="cli-trend ' . esc_attr( $class ) . '">' . esc_html( $trend['label'] ) . ' (' . esc_html( $previous ) . ' &rarr; ' . esc_html( $current ) . ') vs ' . esc_html( $comparison_label ) . '</small></div>';
    }
    private function top_item( $items ) {
        if ( ! is_array( $items ) || ! $items ) return [ 'name' => 'No data yet', 'count' => 0 ];
        $name = array_key_first( $items );
        return [ 'name' => (string) $name, 'count' => (int) $items[$name] ];
    }
    private function winners_card( $winners ) {
        echo '<section class="cli-card cli-winners"><div class="cli-winners-heading"><div><span class="dashicons dashicons-awards"></span><h2>The Winners</h2></div><p>Top performers from the last 30 days.</p></div><div class="cli-winners-grid">';
        foreach ( $winners as $label => $winner ) {
            echo '<div class="cli-winner"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $winner['name'] ) . '</strong><small>' . esc_html( $winner['count'] ) . ' ' . ( 1 === (int) $winner['count'] ? 'lead' : 'leads' ) . '</small></div>';
        }
        echo '</div></section>';
    }
    private function mvp_card( $items ) {
        echo '<section class="cli-card cli-winners cli-mvp"><div class="cli-winners-heading"><div><span class="dashicons dashicons-chart-area"></span><h2>MVP — Most Valuable Pages</h2></div><p>Top page contributions for the selected period.</p></div><div class="cli-winners-grid">';
        foreach ( $items as $label => $item ) {
            echo '<div class="cli-winner"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $item['name'] ) . '</strong><small>' . esc_html( $item['count'] ) . ' ' . ( 1 === (int) $item['count'] ? 'lead' : 'leads' ) . '</small></div>';
        }
        echo '</div></section>';
    }
    private function journey_paths_card( $journeys ) {
        echo '<section class="cli-card cli-journey-explorer"><div class="cli-winners-heading"><div><span class="dashicons dashicons-randomize"></span><h2>Common Customer Journeys</h2></div><p>The most common paths before a Gravity Forms conversion.</p></div><div class="cli-journey-list">';
        foreach ( (array) $journeys as $encoded => $count ) {
            $journey = json_decode( (string) $encoded, true );
            if ( ! is_array( $journey ) || empty( $journey['pages'] ) ) continue;
            echo '<div class="cli-journey-row"><div class="cli-journey-meta"><strong>' . esc_html( ( $journey['source'] ?? 'Unknown' ) . ' · ' . ( $journey['channel'] ?? 'Unknown' ) ) . '</strong><span>' . esc_html( $count ) . ' ' . ( 1 === (int) $count ? 'person' : 'people' ) . '</span></div><div class="cli-journey-steps">';
            foreach ( array_values( $journey['pages'] ) as $index => $page ) {
                if ( $index ) echo '<span class="cli-journey-arrow" aria-hidden="true">→</span>';
                echo '<span class="cli-journey-step">' . esc_html( $page ) . '</span>';
            }
            echo '</div></div>';
        }
        if ( ! $journeys ) echo '<p>No customer journeys were captured for this period.</p>';
        echo '</div></section>';
    }
    private function status_card( $title, $ok, $text ) { echo '<div class="cli-card cli-status ' . ( $ok ? 'is-ok' : 'is-bad' ) . '"><h2>' . esc_html( $title ) . '</h2><strong>' . ( $ok ? 'Working' : 'Problem' ) . '</strong><p>' . esc_html( $text ) . '</p></div>'; }
    private function gf_missing() { echo '<div class="notice notice-error"><p>Gravity Forms is required for lead reporting.</p></div>'; }
    private function dashboard_items( $items ) {
        $limit = (int) apply_filters( 'cli_dashboard_row_limit', 10 );
        return array_slice( (array) $items, 0, max( 1, min( 50, $limit ) ), true );
    }
    private function dashboard_card_description( $title ) {
        $descriptions = [
            'Paid Leads' => 'Confirmed leads from paid promotions',
            'Lead Sources' => 'What platforms are generating leads',
            'Lead Channels' => 'What kind of medium created the lead',
        ];
        return $descriptions[ $title ] ?? '';
    }
    private function top_lead_sources_card( $sources, $paid_sources, $period = [] ) {
        $sources = array_slice( (array) $sources, 0, 5, true );
        $paid_sources = (array) $paid_sources;
        echo '<section class="cli-card cli-top-lead-sources"><h2>Top 5 Lead Sources</h2><div class="cli-table-scroll"><table class="widefat striped cli-report-table cli-top-lead-sources-table"><thead><tr><th>Lead Source</th><th class="cli-top-number-col">Total Leads</th><th class="cli-top-number-col">Paid Leads</th><th class="cli-top-share-col">Paid Share</th></tr></thead><tbody>';
        if ( ! $sources ) echo '<tr><td colspan="4">No attribution data yet.</td></tr>';
        foreach ( $sources as $source => $total_leads ) {
            $total_leads = (int) $total_leads;
            $paid_leads = (int) ( $paid_sources[ $source ] ?? 0 );
            $paid_share = $total_leads ? round( $paid_leads / $total_leads * 100, 1 ) : 0;

            $url = add_query_arg( [
                'page' => 'calico-lead-intelligence-leads',
                'cli_source' => $source,
                'cli_from' => ! empty( $period['start_local'] ) ? $period['start_local']->format( 'Y-m-d' ) : '',
                'cli_to' => ! empty( $period['end_local'] ) ? $period['end_local']->format( 'Y-m-d' ) : '',
            ], admin_url( 'admin.php' ) );
            echo '<tr><td class="cli-name-cell"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $source ) . '</strong></a></td><td class="cli-top-number-col">' . esc_html( $total_leads ) . '</td><td class="cli-top-number-col">' . esc_html( $paid_leads ) . '</td><td class="cli-top-share-col">' . esc_html( $paid_share ) . '%</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
    private function paid_leads_card( $items, $total, $period = [] ) {
        echo '<div class="cli-card cli-paid-leads"><h2>Paid Leads</h2><p class="cli-card-description">' . esc_html( $this->dashboard_card_description( 'Paid Leads' ) ) . '</p><table class="widefat striped cli-report-table"><thead><tr><th>Source</th><th class="cli-number-col">Leads</th><th class="cli-share-col">Share</th></tr></thead><tbody>';
        if ( ! $items ) echo '<tr><td colspan="3">No paid lead data yet.</td></tr>';
        foreach ( $items as $source => $count ) {
            $share = $total ? round( $count / $total * 100, 1 ) : 0;
            $args = [
                'page' => 'calico-lead-intelligence-leads',
                'cli_source' => $source,
                'cli_channel' => 'Paid',
                'cli_from' => ! empty( $period['start_local'] ) ? $period['start_local']->format( 'Y-m-d' ) : '',
                'cli_to' => ! empty( $period['end_local'] ) ? $period['end_local']->format( 'Y-m-d' ) : '',
            ];
            $url = add_query_arg( $args, admin_url( 'admin.php' ) );
            echo '<tr><td class="cli-name-cell" title="' . esc_attr( $source ) . '"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $source ) . '</strong></a></td><td class="cli-number-col"><strong>' . esc_html( $count ) . '</strong></td><td class="cli-share-col">' . esc_html( $share ) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private function table_card( $title, $items, $total, $type = '', $previous_items = [], $compare = false, $period = [], $comparison_label = 'Compared with previous period' ) {
        $class = 'campaigns' === $type ? ' cli-table-campaigns' : '';
        $description = $this->dashboard_card_description( $title );
        echo '<div class="cli-card' . esc_attr( $class ) . '"><h2>' . esc_html( $title ) . '</h2>';
        if ( $description ) echo '<p class="cli-card-description">' . esc_html( $description ) . '</p>';
        echo '<table class="widefat striped cli-report-table"><thead><tr><th>Name</th><th class="cli-number-col">Leads' . ( $compare ? ' / Change' : '' ) . '</th><th class="cli-share-col">Share</th></tr></thead><tbody>';
        if ( ! $items ) echo '<tr><td colspan="3">No attribution data yet.</td></tr>';
        foreach ( $items as $name => $count ) {
            $share = $total ? round( $count / $total * 100, 1 ) : 0;
            $trend_html = '';
            if ( $compare ) {
                $previous = (int) ( $previous_items[$name] ?? 0 );
                $trend = $this->percentage_change( $count, $previous );
                $trend_html = '<small class="cli-trend ' . esc_attr( $trend['class'] ) . '" title="' . esc_attr( $comparison_label ) . ': ' . esc_attr( $previous ) . ' leads">' . esc_html( $trend['label'] ) . ' (' . esc_html( $previous ) . ' &rarr; ' . esc_html( $count ) . ')</small>';
            }
            $name_html = esc_html( $name );
            if ( in_array( $type, [ 'sources', 'channels' ], true ) ) {
                $args = [
                    'page' => 'calico-lead-intelligence-leads',
                    'cli_from' => ! empty( $period['start_utc'] ) ? substr( (string) $period['start_utc'], 0, 10 ) : '',
                    'cli_to' => ! empty( $period['end_utc'] ) ? substr( (string) $period['end_utc'], 0, 10 ) : '',
                ];
                $args[ 'sources' === $type ? 'cli_source' : 'cli_channel' ] = $name;
                $name_html = '<a href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
            }
            echo '<tr><td class="cli-name-cell" title="' . esc_attr( $name ) . '">' . $name_html . '</td><td class="cli-number-col"><strong>' . esc_html( $count ) . '</strong>' . $trend_html . '</td><td class="cli-share-col">' . esc_html( $share ) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function source_mapping_key( $source ) {
        return strtolower( trim( sanitize_text_field( (string) $source ) ) );
    }

    private function get_source_mappings() {
        $stored = get_option( 'cli_source_mappings', [] );
        if ( ! is_array( $stored ) ) return [];
        $mappings = [];
        foreach ( $stored as $key => $mapping ) {
            if ( ! is_array( $mapping ) || empty( $mapping['source'] ) || empty( $mapping['channel'] ) ) continue;
            $detected = sanitize_text_field( (string) ( $mapping['detected'] ?? $key ) );
            $target_source = sanitize_text_field( (string) $mapping['source'] );
            if ( 'Meta' === $detected ) $detected = 'Facebook / Instagram';
            if ( 'Meta' === $target_source ) $target_source = 'Facebook / Instagram';
            $normalized_key = $this->source_mapping_key( $detected );
            if ( ! $normalized_key ) continue;
            $mappings[ $normalized_key ] = [
                'detected' => $detected,
                'source' => $target_source,
                'channel' => sanitize_text_field( (string) $mapping['channel'] ),
            ];
        }
        return $mappings;
    }

    private function reporting_source_attribution( $source, $channel, $confidence = 'Verified' ) {
        $normalized = $this->normalize_customer_attribution( $source, $channel, $confidence );
        $mappings = $this->get_source_mappings();
        $key = $this->source_mapping_key( $normalized['source'] );
        if ( ! isset( $mappings[ $key ] ) ) return $normalized;
        // A reporting mapping is an explicit client decision. Do not pass it
        // back through automatic attribution rules. Explicit paid evidence is
        // never weakened to Unknown by a display-only mapping.
        $mapped_channel = sanitize_text_field( $mappings[ $key ]['channel'] );
        if ( 'Paid' === $normalized['channel'] && 'Unknown' === $mapped_channel ) $mapped_channel = 'Paid';
        return [
            'source' => sanitize_text_field( $mappings[ $key ]['source'] ),
            'channel' => $mapped_channel,
            'confidence' => $normalized['confidence'],
        ];
    }

    /**
     * Use the latest meaningful acquisition touch for reporting. A later
     * Direct/Unknown visit remains visible in Last Touch details but does not
     * erase a known paid, organic, referral, email, or AI source.
     */
    private function last_non_direct_attribution( $first, $last ) {
        $first = is_array( $first ) ? $first : [];
        $last = is_array( $last ) ? $last : [];
        $first_attribution = $this->normalize_customer_attribution(
            $first['source'] ?? 'Unknown',
            $first['channel'] ?? 'Unknown',
            $first['status'] ?? ( $first['confidence'] ?? 'Verified' )
        );
        $last_attribution = $this->normalize_customer_attribution(
            $last['source'] ?? 'Unknown',
            $last['channel'] ?? 'Unknown',
            $last['status'] ?? ( $last['confidence'] ?? 'Verified' )
        );
        $non_direct = static function( $attribution ) {
            return ! in_array( $attribution['source'], [ 'Direct', 'Unknown' ], true )
                && ! in_array( $attribution['channel'], [ 'Direct', 'Unknown' ], true );
        };

        // A combined fbclid fallback is weaker than platform-specific
        // evidence, even when the ambiguous touch occurred later.
        if (
            'Facebook / Instagram' === $last_attribution['source'] &&
            $non_direct( $first_attribution ) &&
            'Facebook / Instagram' !== $first_attribution['source']
        ) {
            $first_attribution['touch'] = 'first';
            return $first_attribution;
        }

        // A carried social referrer on an untagged conversion page is weaker
        // than a verified paid acquisition from the same platform family.
        // Facebook, Instagram, and the combined Meta fallback are one family
        // for this narrow precedence rule; their reporting labels stay distinct.
        $same_source_family = static function( $first_source, $last_source ) {
            if ( $first_source === $last_source ) return true;
            $meta_sources = [ 'Facebook', 'Instagram', 'Facebook / Instagram' ];
            return in_array( $first_source, $meta_sources, true ) && in_array( $last_source, $meta_sources, true );
        };
        if (
            'Paid' === $first_attribution['channel'] &&
            'Verified' === $first_attribution['confidence'] &&
            'Paid' !== $last_attribution['channel'] &&
            'Inferred' === $last_attribution['confidence'] &&
            $same_source_family( $first_attribution['source'], $last_attribution['source'] )
        ) {
            $first_attribution['touch'] = 'first';
            return $first_attribution;
        }

        if ( $non_direct( $last_attribution ) ) {
            $last_attribution['touch'] = 'last';
            return $last_attribution;
        }
        if ( $non_direct( $first_attribution ) ) {
            $first_attribution['touch'] = 'first';
            return $first_attribution;
        }
        if ( 'Direct' === $last_attribution['source'] || 'Direct' === $last_attribution['channel'] ) {
            $last_attribution['touch'] = 'last';
            return $last_attribution;
        }
        $first_attribution['touch'] = 'first';
        return $first_attribution;
    }

    private function reporting_mapping_targets( $detected_sources = [] ) {
        $targets = [
            'Google|Organic', 'Google|Paid', 'Bing|Organic', 'Bing|Paid', 'Yahoo|Organic', 'Yahoo|Paid',
            'DuckDuckGo|Organic', 'DuckDuckGo|Paid', 'Facebook|Organic', 'Facebook|Paid',
            'Instagram|Organic', 'Instagram|Paid', 'LinkedIn|Organic', 'LinkedIn|Paid',
            'TikTok|Organic', 'TikTok|Paid', 'Pinterest|Organic', 'Pinterest|Paid',
            'X|Organic', 'X|Paid', 'Reddit|Organic', 'Reddit|Paid',
            'Facebook / Instagram|Referral', 'Facebook / Instagram|Paid',
            'Direct|Direct', 'Email|Email', 'Referral|Referral', 'ChatGPT|AI', 'Perplexity|AI',
            'Claude|AI', 'Gemini|AI', 'Copilot|AI', 'DeepSeek|AI', 'SMS|Referral', 'QR Code|Referral',
        ];
        $options = [];
        foreach ( $targets as $target ) {
            list( $source, $channel ) = explode( '|', $target, 2 );
            $options[ $this->encode_source_mapping_target( $source, $channel ) ] = $source . ' — ' . $channel;
        }
        foreach ( (array) $detected_sources as $detected_source ) {
            $detected_source = sanitize_text_field( (string) $detected_source );
            if ( ! $detected_source ) continue;
            $dimensions = $this->source_channel_from_legacy_label( $detected_source );
            $target = $this->encode_source_mapping_target( $detected_source, $dimensions['channel'] );
            if ( ! isset( $options[ $target ] ) ) $options[ $target ] = $detected_source . ' — ' . $dimensions['channel'];
        }
        natcasesort( $options );
        return $options;
    }

    /**
     * Use a URL-safe opaque value in the admin form. Some hosting security
     * layers alter pipe-delimited POST values, which previously made a valid
     * mapping look empty after Save and Recalculate.
     */
    private function encode_source_mapping_target( $source, $channel ) {
        $payload = wp_json_encode( [
            'source' => sanitize_text_field( (string) $source ),
            'channel' => sanitize_text_field( (string) $channel ),
        ] );
        return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
    }

    private function decode_source_mapping_target( $token ) {
        $token = sanitize_text_field( (string) $token );
        if ( '' === $token ) return null;

        // Accept the 4.5.0 value format for a form opened before an update.
        if ( false !== strpos( $token, '|' ) ) {
            list( $source, $channel ) = explode( '|', $token, 2 );
            $data = [ 'source' => $source, 'channel' => $channel ];
        } else {
            if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $token ) ) return null;
            $padding = strlen( $token ) % 4;
            if ( $padding ) $token .= str_repeat( '=', 4 - $padding );
            $json = base64_decode( strtr( $token, '-_', '+/' ), true );
            $data = $json ? json_decode( $json, true ) : null;
        }

        if ( ! is_array( $data ) ) return null;
        $source = sanitize_text_field( (string) ( $data['source'] ?? '' ) );
        $channel = sanitize_text_field( (string) ( $data['channel'] ?? '' ) );
        $allowed_channels = [ 'Paid', 'Organic', 'Direct', 'Email', 'Referral', 'AI', 'Unknown' ];
        if ( '' === $source || ! in_array( $channel, $allowed_channels, true ) ) return null;
        return [ 'source' => $source, 'channel' => $channel ];
    }

    private function get_google_campaign_mappings() {
        $mappings = get_option( 'cli_google_campaign_mappings', [] );
        return is_array( $mappings ) ? $mappings : [];
    }

    private function campaign_display_label( $entry_id ) {
        $meta = [
            'cli_campaign_label' => gform_get_meta( $entry_id, 'cli_campaign_label' ),
            'cli_campaign' => gform_get_meta( $entry_id, 'cli_campaign' ),
            'cli_campaign_id' => gform_get_meta( $entry_id, 'cli_campaign_id' ),
            'cli_campaign_source' => gform_get_meta( $entry_id, 'cli_campaign_source' ),
            'cli_lead_source' => gform_get_meta( $entry_id, 'cli_lead_source' ),
        ];
        return $this->campaign_display_label_from_meta( $meta, $this->get_google_campaign_mappings() );
    }

    private function campaign_display_label_from_meta( $meta, $mappings ) {
        $stored = trim( (string) ( ( $meta['cli_campaign_label'] ?? '' ) ?: ( $meta['cli_campaign'] ?? '' ) ) );
        $campaign_id = trim( (string) ( $meta['cli_campaign_id'] ?? '' ) );
        if ( '' === $campaign_id ) return $stored;
        // An exact client mapping is authoritative for display, even when an
        // older entry omitted its Google campaign-source metadata. The raw ID
        // remains visible and all stored Gravity Forms values stay unchanged.
        if ( isset( $mappings[ $campaign_id ] ) && '' !== trim( (string) $mappings[ $campaign_id ] ) ) {
            return trim( sanitize_text_field( (string) $mappings[ $campaign_id ] ) ) . ' — Google Ads #' . $campaign_id;
        }
        $source = trim( (string) ( $meta['cli_campaign_source'] ?? '' ) );
        $lead_source = trim( (string) ( $meta['cli_lead_source'] ?? '' ) );
        $is_google = false !== stripos( $source, 'google' ) || false !== stripos( $lead_source, 'google' ) || 0 === stripos( $stored, 'Google Ads Campaign #' ) || 0 === stripos( $stored, 'Campaign #' );
        if ( ! $is_google ) return $stored;
        return $stored ?: 'Google Ads Campaign #' . $campaign_id;
    }


    public static function activate() {
        $instance = self::instance();
        $instance->schedule_next_report();
    }

    public static function deactivate() { wp_clear_scheduled_hook( 'cli_scheduled_report_event' ); }

    private function sanitize_recursive( $value ) {
        if ( is_array( $value ) ) { $out = []; foreach ( $value as $k => $v ) $out[ sanitize_key( (string) $k ) ] = $this->sanitize_recursive( $v ); return $out; }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) return $value;
        return sanitize_text_field( (string) $value );
    }
    private function first_value( $values ) { foreach ( $values as $v ) if ( '' !== (string) $v ) return $v; return ''; }
    private function format_time( $seconds ) { $minutes = floor( $seconds / 60 ); return $minutes ? $minutes . 'm ' . ( $seconds % 60 ) . 's' : $seconds . 's'; }
}

register_activation_hook( __FILE__, [ 'Calico_Lead_Intelligence', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Calico_Lead_Intelligence', 'deactivate' ] );
Calico_Lead_Intelligence::instance();
