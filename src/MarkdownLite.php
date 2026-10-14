<?php
/**
 * MarkdownLite — a small, dependency-free Markdown-to-HTML renderer.
 *
 * Supported: ATX headings, bold/italic/strike (both ** and __ styles),
 * inline code, fenced code blocks (with language class), links, images,
 * unordered/ordered lists (single level), blockquotes, tables with
 * alignment, horizontal rules, paragraphs.
 *
 * Security: the raw input is fully HTML-escaped before any Markdown
 * processing, so injected <script> tags render as inert text. Link/image
 * URLs are limited to safe schemes (http, https, mailto, //, /, data:image/*).
 *
 * Usage:
 *   require 'src/MarkdownLite.php';
 *   echo MarkdownLite::toHtml( file_get_contents( 'README.md' ) );
 *
 * MIT licensed.
 */

class MarkdownLite
{
    /** @var array Placeholder slots for code (block + inline). */
    private $slots = array();

    /**
     * Convert Markdown to HTML.
     *
     * @param string $markdown
     * @return string
     */
    public static function toHtml( $markdown ) {
        $md = new self();
        return $md->render( $markdown );
    }

    /**
     * Store an HTML fragment and return its placeholder token.
     *
     * @param string $html
     * @return string
     */
    private function stash( $html ) {
        $this->slots[] = $html;
        return "\x00MDL" . ( count( $this->slots ) - 1 ) . "\x00";
    }

    /**
     * Is this line a placeholder for a stashed fragment?
     *
     * @param string $trim
     * @return bool
     */
    private function isSlot( $trim ) {
        return (bool) preg_match( '/^\x00MDL\d+\x00$/', $trim );
    }

    /**
     * @param string $trim
     * @return string|null
     */
    private function slotHtml( $trim ) {
        if ( preg_match( '/^\x00MDL(\d+)\x00$/', $trim, $m ) ) {
            $i = (int) $m[1];
            return isset( $this->slots[ $i ] ) ? $this->slots[ $i ] : '';
        }
        return null;
    }

    /**
     * Block-level render.
     *
     * @param string $markdown
     * @return string
     */
    public function render( $markdown ) {
        $markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );

        // 1. Escape everything first: raw HTML becomes inert text.
        $text = htmlspecialchars( $markdown, ENT_QUOTES, 'UTF-8' );

        // 2. Pull out fenced code blocks so their contents are never parsed.
        $text = preg_replace_callback(
            '/```(\w*)[ \t]*\n(.*?)\n```[ \t]*(?=\n|$)/s',
            function ( $m ) {
                $lang = $m[1] !== '' ? ' class="language-' . $m[1] . '"' : '';
                return $this->stash( '<pre><code' . $lang . '>' . trim( $m[2], "\n" ) . "</code></pre>" );
            },
            $text
        );

        $lines = explode( "\n", $text );
        $html  = '';
        $i     = 0;
        $n     = count( $lines );

        while ( $i < $n ) {
            $line = $lines[ $i ];
            $trim = trim( $line );

            if ( $trim === '' ) {
                $i++;
                continue;
            }

            // Stashed code block.
            if ( $this->isSlot( $trim ) ) {
                $html .= $this->slotHtml( $trim ) . "\n";
                $i++;
                continue;
            }

            // ATX heading.
            if ( preg_match( '/^(#{1,6})[ \t]+(.*?)[ \t]*$/', $trim, $m ) ) {
                $level = strlen( $m[1] );
                $html .= '<h' . $level . '>' . $this->inline( $m[2] ) . '</h' . $level . ">\n";
                $i++;
                continue;
            }

            // Horizontal rule.
            if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})[ \t]*$/', $trim ) ) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }

            // Blockquote (recursive — contents are parsed as Markdown).
            if ( strpos( $trim, '&gt;' ) === 0 ) {
                $quoted = array();
                while ( $i < $n && strpos( trim( $lines[ $i ] ), '&gt;' ) === 0 ) {
                    $quoted[] = preg_replace( '/^&gt;[ \t]?/', '', trim( $lines[ $i ] ) );
                    $i++;
                }
                // Quoted lines are already HTML-escaped; decode once so the
                // recursive render() escapes them exactly once.
                $inner = html_entity_decode( implode( "\n", $quoted ), ENT_QUOTES, 'UTF-8' );
                $html .= "<blockquote>\n" . $this->render( $inner ) . "</blockquote>\n";
                continue;
            }

            // Table (header row + separator row).
            if ( strpos( $trim, '|' ) !== false
                && $i + 1 < $n
                && preg_match( '/^\|?[ \t:|\-]+[ \t]*\|?$/', trim( $lines[ $i + 1 ] ) )
                && strpos( trim( $lines[ $i + 1 ] ), '-' ) !== false
            ) {
                $html .= $this->table( $trim, trim( $lines[ $i + 1 ] ) );
                $i += 2;
                // Body rows: consecutive lines containing a pipe.
                while ( $i < $n && strpos( trim( $lines[ $i ] ), '|' ) !== false && trim( $lines[ $i ] ) !== '' ) {
                    $html .= $this->tableRow( trim( $lines[ $i ] ), false );
                    $i++;
                }
                $html .= "</tbody>\n</table>\n";
                continue;
            }

            // Unordered list.
            if ( preg_match( '/^([-*+])[ \t]+(.*)$/', $trim, $m ) ) {
                $html .= "<ul>\n";
                while ( $i < $n && preg_match( '/^([-*+])[ \t]+(.*)$/', trim( $lines[ $i ] ), $m2 ) ) {
                    $html .= '<li>' . $this->inline( $m2[2] ) . "</li>\n";
                    $i++;
                }
                $html .= "</ul>\n";
                continue;
            }

            // Ordered list.
            if ( preg_match( '/^\d+\.[ \t]+(.*)$/', $trim, $m ) ) {
                $html .= "<ol>\n";
                while ( $i < $n && preg_match( '/^\d+\.[ \t]+(.*)$/', trim( $lines[ $i ] ), $m2 ) ) {
                    $html .= '<li>' . $this->inline( $m2[2] ) . "</li>\n";
                    $i++;
                }
                $html .= "</ol>\n";
                continue;
            }

            // Paragraph: gather consecutive plain lines.
            $para = array();
            while ( $i < $n && trim( $lines[ $i ] ) !== '' && $this->isPlainLine( trim( $lines[ $i ] ), $i < $n - 1 ? trim( $lines[ $i + 1 ] ) : '' ) ) {
                $para[] = trim( $lines[ $i ] );
                $i++;
            }
            if ( $para ) {
                $html .= '<p>' . $this->inline( implode( ' ', $para ) ) . "</p>\n";
                continue;
            }
            $i++; // safety: never stall
        }

        return $html;
    }

    /**
     * A line is "plain" if it starts no other block construct.
     *
     * @param string $trim
     * @param string $nextTrim
     * @return bool
     */
    private function isPlainLine( $trim, $nextTrim ) {
        if ( $this->isSlot( $trim ) ) {
            return false;
        }
        if ( preg_match( '/^#{1,6}[ \t]+/', $trim )
            || preg_match( '/^(-{3,}|\*{3,}|_{3,})[ \t]*$/', $trim )
            || strpos( $trim, '&gt;' ) === 0
            || preg_match( '/^([-*+])[ \t]+/', $trim )
            || preg_match( '/^\d+\.[ \t]+/', $trim )
        ) {
            return false;
        }
        // Table start needs the separator-row lookahead.
        if ( strpos( $trim, '|' ) !== false
            && preg_match( '/^\|?[ \t:|\-]+[ \t]*\|?$/', $nextTrim )
            && strpos( $nextTrim, '-' ) !== false
        ) {
            return false;
        }
        return true;
    }

    /**
     * Split a table line into cells.
     *
     * @param string $line
     * @return array
     */
    private function tableCells( $line ) {
        $cells = explode( '|', trim( $line, " \t|" ) );
        return array_map( 'trim', $cells );
    }

    /**
     * Open a table: header row + alignment from the separator row.
     *
     * @param string $header
     * @param string $sep
     * @return string
     */
    private function table( $header, $sep ) {
        $cells  = $this->tableCells( $header );
        $aligns = array();
        foreach ( $this->tableCells( $sep ) as $c ) {
            $c = trim( $c );
            if ( strlen( $c ) > 1 && $c[0] === ':' && substr( $c, -1 ) === ':' ) {
                $aligns[] = ' style="text-align:center"';
            } elseif ( strlen( $c ) > 1 && substr( $c, -1 ) === ':' ) {
                $aligns[] = ' style="text-align:right"';
            } else {
                $aligns[] = '';
            }
        }
        $out = "<table>\n<thead>\n<tr>";
        foreach ( $cells as $k => $cell ) {
            $out .= '<th' . ( isset( $aligns[ $k ] ) ? $aligns[ $k ] : '' ) . '>' . $this->inline( $cell ) . '</th>';
        }
        return $out . "</tr>\n</thead>\n<tbody>\n";
    }

    /**
     * One table body row.
     *
     * @param string $line
     * @param bool   $header (unused, kept for symmetry)
     * @return string
     */
    private function tableRow( $line, $header = false ) {
        $out = '<tr>';
        foreach ( $this->tableCells( $line ) as $cell ) {
            $out .= '<td>' . $this->inline( $cell ) . '</td>';
        }
        return $out . "</tr>\n";
    }

    /**
     * Inline-level formatting on already-escaped text.
     *
     * @param string $text
     * @return string
     */
    private function inline( $text ) {
        // Inline code first — its contents must not be re-parsed.
        $text = preg_replace_callback(
            '/`([^`\n]+)`/',
            function ( $m ) {
                return $this->stash( '<code>' . $m[1] . '</code>' );
            },
            $text
        );

        // Images.
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^\s)]+)(?:[ \t]+"([^"]*)")?\)/',
            function ( $m ) {
                $src = $this->safeUrl( $m[2], true );
                if ( $src === null ) {
                    return $m[1]; // unsafe scheme: render alt text only
                }
                $title = isset( $m[3] ) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '';
                return '<img src="' . $src . '" alt="' . $m[1] . '"' . $title . '>';
            },
            $text
        );

        // Links.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\s)]+)(?:[ \t]+"([^"]*)")?\)/',
            function ( $m ) {
                $href = $this->safeUrl( $m[2], false );
                if ( $href === null ) {
                    return $m[1]; // unsafe scheme: render link text only
                }
                $title = isset( $m[3] ) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '';
                return '<a href="' . $href . '"' . $title . '>' . $m[1] . '</a>';
            },
            $text
        );

        // Bold (** and __).
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $text );
        // Strikethrough.
        $text = preg_replace( '/~~([^~]+)~~/', '<del>$1</del>', $text );
        // Italic (* and _).
        $text = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $text );
        $text = preg_replace( '/_([^_\s][^_]*?)_/', '<em>$1</em>', $text );

        // Restore inline-code slots (blockquote recursion shares the stash).
        $text = preg_replace_callback(
            '/\x00MDL(\d+)\x00/',
            function ( $m ) {
                $i = (int) $m[1];
                return isset( $this->slots[ $i ] ) ? $this->slots[ $i ] : '';
            },
            $text
        );

        return $text;
    }

    /**
     * Allow-list a URL for href/src. Returns the (already-escaped) URL or null.
     *
     * @param string $url   Escaped URL text.
     * @param bool   $image Whether data:image/* is acceptable.
     * @return string|null
     */
    private function safeUrl( $url, $image ) {
        // Decode the escaping just enough to inspect the scheme.
        $raw = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
        if ( preg_match( '/^(https?:|mailto:|\/\/|\/|#)/i', $raw ) ) {
            return $url;
        }
        if ( $image && preg_match( '/^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/i', $raw ) ) {
            return $url;
        }
        return null;
    }
}
