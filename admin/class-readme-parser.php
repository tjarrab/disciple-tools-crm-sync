<?php
/**
 * README parser for the "View Details" plugin modal.
 *
 * Loads README.md (local file first, GitHub raw URL fallback), splits it into
 * named sections by ## heading, and converts each section's Markdown to the
 * HTML subset accepted by the WordPress plugin-information modal.
 *
 * @package Disciple_Tools_CRM_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses a Markdown README into HTML sections for the "View Details" modal.
 */
class Disciple_Tools_CRM_Sync_Readme_Parser {

    /**
     * Return an associative array of HTML strings keyed by ## section heading.
     *
     * @param string $local_path  Absolute filesystem path to README.md.
     * @param string $remote_url  Raw GitHub URL used as fallback when local file is missing.
     * @return array<string, string> Section heading => HTML string.
     */
    public static function get_sections( string $local_path, string $remote_url ): array {
        $markdown = self::load_content( $local_path, $remote_url );
        if ( '' === $markdown ) {
            return [];
        }
        $raw_sections = self::split_sections( $markdown );
        $html_sections = [];
        foreach ( $raw_sections as $heading => $body ) {
            $html_sections[ $heading ] = self::markdown_to_html( $body );
        }
        return $html_sections;
    }

    /**
     * Load README content: local file first, remote URL as fallback.
     *
     * @param string $local_path  Absolute path to a local README.md file.
     * @param string $remote_url  Raw GitHub URL used when the local file is absent.
     * @return string Raw Markdown content, or empty string on failure.
     */
    private static function load_content( string $local_path, string $remote_url ): string {
        // Prefer the locally installed file over a remote fetch.
        if ( '' !== $local_path && file_exists( $local_path ) && is_readable( $local_path ) ) {
            $content = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- intentional local read.
            if ( false !== $content && '' !== trim( $content ) ) {
                return $content;
            }
        }

        // Fall back to the GitHub raw URL supplied in version-control.json.
        if ( '' === $remote_url ) {
            return '';
        }

        $response = wp_remote_get(
            esc_url_raw( $remote_url ),
            [
                'timeout'    => 10,
                'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return '';
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Split a Markdown document into sections keyed by their ## heading text.
     *
     * Sections above the first ## heading (e.g. the h1 title and banner image)
     * are discarded. Each value includes everything between its ## heading and
     * the start of the next ## heading, with the heading line itself removed.
     *
     * @param string $markdown Full Markdown document.
     * @return array<string, string> Heading text => Markdown body.
     */
    private static function split_sections( string $markdown ): array {
        // Normalise line endings.
        $markdown = str_replace( "\r\n", "\n", $markdown );
        $markdown = str_replace( "\r", "\n", $markdown );

        $sections = [];
        // Match every ## heading (not ### or ####) and capture the rest of the line.
        $pattern = '/^## (.+)$/m';
        $parts   = preg_split( $pattern, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( false === $parts || count( $parts ) < 3 ) {
            return $sections;
        }

        // Index 0 is the preamble before the first ## heading; skip it.
        $parts_count = count( $parts );
        for ( $i = 1; $i + 1 < $parts_count; $i += 2 ) {
            $heading          = trim( $parts[ $i ] );
            $body             = $parts[ $i + 1 ];
            $sections[ $heading ] = $body;
        }

        return $sections;
    }

    /**
     * Convert a Markdown string to the HTML subset supported by the WP modal.
     *
     * Conversion rules are applied in a deliberate order to avoid rule collisions:
     * fenced blocks and inline code are replaced with placeholders first so that
     * subsequent regex rules do not accidentally mangle them.
     *
     * @param string $markdown Markdown text (a single README section body).
     * @return string HTML string.
     */
    private static function markdown_to_html( string $markdown ): string {
        $markdown = trim( $markdown );
        if ( '' === $markdown ) {
            return '';
        }

        // Protect fenced code blocks with placeholders so later passes don't mangle them.
        $code_blocks   = [];
        $block_counter = 0;

        $markdown = preg_replace_callback(
            '/```[a-z]*\n(.*?)```/s',
            function ( array $m ) use ( &$code_blocks, &$block_counter ): string {
                $placeholder = "\x00CODE_BLOCK_{$block_counter}\x00";
                $code_blocks[ $block_counter ] = '<pre><code>' . esc_html( trim( $m[1] ) ) . '</code></pre>';
                ++$block_counter;
                return $placeholder;
            },
            $markdown
        );

        // Protect inline code spans.
        $inline_codes   = [];
        $inline_counter = 0;

        $markdown = preg_replace_callback(
            '/`([^`\n]+)`/',
            function ( array $m ) use ( &$inline_codes, &$inline_counter ): string {
                $placeholder = "\x00INLINE_CODE_{$inline_counter}\x00";
                $inline_codes[ $inline_counter ] = '<code>' . esc_html( $m[1] ) . '</code>';
                ++$inline_counter;
                return $placeholder;
            },
            $markdown
        );

        // Strip images.
        $markdown = preg_replace( '/!\[([^\]]*)\]\([^)]*\)/', '', $markdown );

        // Convert links.
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ( array $m ): string {
                return '<a href="' . esc_url( $m[2] ) . '">' . esc_html( $m[1] ) . '</a>';
            },
            $markdown
        );

        // Headings — h4 before h3 to avoid partial matches.
        $markdown = preg_replace( '/^#### (.+)$/m', '<h4>$1</h4>', $markdown );
        $markdown = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $markdown );

        // Bold and italic.
        $markdown = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $markdown );
        $markdown = preg_replace( '/\*([^*\n]+)\*/', '<em>$1</em>', $markdown );

        // Horizontal rules.
        $markdown = preg_replace( '/^---+$/m', '<hr>', $markdown );

        // Tables.
        $markdown = self::convert_tables( $markdown );

        // Lists.
        $markdown = self::convert_lists( $markdown );

        // Paragraphs — wrap non-empty, non-block lines in <p> tags.
        $markdown = self::convert_paragraphs( $markdown );

        // Restore placeholders.
        foreach ( $code_blocks as $idx => $html ) {
            $markdown = str_replace( "\x00CODE_BLOCK_{$idx}\x00", $html, $markdown );
        }
        foreach ( $inline_codes as $idx => $html ) {
            $markdown = str_replace( "\x00INLINE_CODE_{$idx}\x00", $html, $markdown );
        }

        return trim( $markdown );
    }

    /**
     * Convert Markdown pipe tables to HTML <table> elements.
     *
     * Detects a table block as three or more consecutive lines where at least
     * one contains a | character. The second row (separator: |---|---| etc.)
     * is used as the signal that the first row is a header row.
     *
     * @param string $markdown Markdown text.
     * @return string Markdown with table blocks replaced by HTML.
     */
    private static function convert_tables( string $markdown ): string {
        $lines  = explode( "\n", $markdown );
        $output = [];
        $i      = 0;
        $total  = count( $lines );

        while ( $i < $total ) {
            // Detect table start: a line containing | that is followed by a separator row.
            if (
                str_contains( $lines[ $i ], '|' )
                && isset( $lines[ $i + 1 ] )
                && preg_match( '/^\|?[\s\-|:]+\|/', $lines[ $i + 1 ] )
            ) {
                $table_lines = [];
                while ( $i < $total && str_contains( $lines[ $i ], '|' ) ) {
                    $table_lines[] = $lines[ $i ];
                    ++$i;
                }
                $output[] = self::build_table( $table_lines );
            } else {
                $output[] = $lines[ $i ];
                ++$i;
            }
        }

        return implode( "\n", $output );
    }

    /**
     * Build an HTML table from an array of Markdown pipe-table lines.
     *
     * @param string[] $lines Raw Markdown table lines.
     * @return string HTML table string.
     */
    private static function build_table( array $lines ): string {
        if ( count( $lines ) < 2 ) {
            return implode( "\n", $lines );
        }

        $parse_row = static function ( string $line ): array {
            // Strip leading/trailing pipes and split on |.
            $line  = trim( $line, " \t|" );
            $cells = array_map( 'trim', explode( '|', $line ) );
            return $cells;
        };

        $html       = '<table>';
        $header     = $parse_row( $lines[0] );
        $html      .= '<tr>';
        foreach ( $header as $cell ) {
            $html .= '<th>' . $cell . '</th>';
        }
        $html .= '</tr>';

        // Skip separator row (index 1).
        $lines_count = count( $lines );
        for ( $j = 2; $j < $lines_count; $j++ ) {
            $cells = $parse_row( $lines[ $j ] );
            $html .= '<tr>';
            foreach ( $cells as $cell ) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Convert Markdown list items to HTML <ul>/<ol> elements.
     *
     * Consecutive unordered (- or *) or ordered (1.) list items are grouped
     * and wrapped in the appropriate list element.
     *
     * @param string $markdown Markdown text.
     * @return string Markdown with list blocks replaced by HTML.
     */
    private static function convert_lists( string $markdown ): string {
        $lines  = explode( "\n", $markdown );
        $output = [];
        $i      = 0;
        $total  = count( $lines );

        while ( $i < $total ) {
            // Unordered list item.
            if ( preg_match( '/^(\s*)[-*] (.+)$/', $lines[ $i ], $m ) ) {
                $html = '<ul>';
                while ( $i < $total && preg_match( '/^(\s*)[-*] (.+)$/', $lines[ $i ], $m ) ) {
                    $html .= '<li>' . trim( $m[2] ) . '</li>';
                    ++$i;
                }
                $html    .= '</ul>';
                $output[] = $html;
            // Ordered list item.
            } elseif ( preg_match( '/^\d+\. (.+)$/', $lines[ $i ], $m ) ) {
                $html = '<ol>';
                while ( $i < $total && preg_match( '/^\d+\. (.+)$/', $lines[ $i ], $m ) ) {
                    $html .= '<li>' . trim( $m[1] ) . '</li>';
                    ++$i;
                }
                $html    .= '</ol>';
                $output[] = $html;
            } else {
                $output[] = $lines[ $i ];
                ++$i;
            }
        }

        return implode( "\n", $output );
    }

    /**
     * Wrap loose text lines in <p> tags.
     *
     * Blank-line-separated groups of text that are not already HTML block
     * elements are wrapped in a single <p> element. Lines that begin with
     * a recognised block-level tag (<h3>, <h4>, <ul>, <ol>, <pre>, <table>,
     * <hr>) are left unwrapped.
     *
     * @param string $markdown Markdown text (post-list and post-table conversion).
     * @return string HTML text with paragraphs.
     */
    private static function convert_paragraphs( string $markdown ): string {
        $block_tags = [ '<h3', '<h4', '<ul>', '</ul>', '<ol>', '</ol>', '<li>', '<pre>', '<table>', '</table>', '<tr>', '<hr>' ];

        $paragraphs = preg_split( '/\n{2,}/', $markdown );
        $output     = [];

        foreach ( $paragraphs as $para ) {
            $trimmed = trim( $para );
            if ( '' === $trimmed ) {
                continue;
            }

            // Already a block element — leave as-is.
            $is_block = false;
            foreach ( $block_tags as $tag ) {
                if ( str_starts_with( $trimmed, $tag ) ) {
                    $is_block = true;
                    break;
                }
            }

            // Placeholder lines are also left as-is.
            if ( str_starts_with( $trimmed, "\x00" ) ) {
                $is_block = true;
            }

            if ( $is_block ) {
                $output[] = $trimmed;
            } else {
                // Collapse internal newlines within a paragraph to spaces.
                $text     = preg_replace( '/\n+/', ' ', $trimmed );
                $output[] = '<p>' . $text . '</p>';
            }
        }

        return implode( "\n", $output );
    }
}
