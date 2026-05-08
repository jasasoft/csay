<?php
/**
 * JS Minifier — auto-builds embed.min.js from embed.js.
 * Uses Terser (Node.js) if available, otherwise falls back to PHP-based minification.
 * Runs on activation and whenever embed.js is newer than embed.min.js.
 *
 * @package CleverSay
 */

namespace CleverSay;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Minifier {

    /**
     * Rebuild embed.min.js. Called on activation and version change.
     */
    public static function rebuild_embed_min(): bool {
        $src  = CLEVERSAY_PLUGIN_DIR . 'public/js/embed.js';
        $dest = CLEVERSAY_PLUGIN_DIR . 'public/js/embed.min.js';

        if ( ! file_exists( $src ) ) {
            return false;
        }

        // Try Terser first (produces best results)
        if ( self::try_terser( $src, $dest ) ) {
            return true;
        }

        // Fallback: PHP-based minifier
        return self::php_minify( $src, $dest );
    }

    /**
     * Attempt minification using Terser via exec().
     */
    private static function try_terser( string $src, string $dest ): bool {
        // Many shared hosts disable exec/shell_exec — bail out safely
        if ( ! function_exists( 'exec' ) || ! function_exists( 'shell_exec' ) ) {
            return false;
        }

        // Use global namespace explicitly to avoid CleverSay\shell_exec() error
        $terser_paths = [
            trim( (string) @\shell_exec( 'which terser 2>/dev/null' ) ),
            '/usr/local/bin/terser',
            '/usr/bin/terser',
        ];

        $terser = '';
        foreach ( $terser_paths as $path ) {
            if ( $path && \file_exists( $path ) ) {
                $terser = $path;
                break;
            }
        }

        if ( ! $terser ) {
            return false;
        }

        $src_esc  = \escapeshellarg( $src );
        $dest_esc = \escapeshellarg( $dest );
        $cmd      = "$terser $src_esc --compress drop_console=true,passes=2 --mangle --output $dest_esc 2>/dev/null";

        \exec( $cmd, $output, $exit_code );

        return $exit_code === 0 && \file_exists( $dest ) && \filesize( $dest ) > 100;
    }

    /**
     * Pure-PHP JS minifier fallback.
     * Removes comments and compresses whitespace.
     */
    private static function php_minify( string $src, string $dest ): bool {
        $js     = file_get_contents( $src );
        $result = '';
        $len    = strlen( $js );
        $i      = 0;

        while ( $i < $len ) {
            $c = $js[ $i ];

            // String literals — preserve exactly
            if ( $c === '"' || $c === "'" || $c === '`' ) {
                $quote   = $c;
                $result .= $c;
                $i++;
                while ( $i < $len ) {
                    $ch      = $js[ $i ];
                    $result .= $ch;
                    if ( $ch === '\\' ) {
                        $i++;
                        if ( $i < $len ) { $result .= $js[ $i ]; $i++; }
                        continue;
                    }
                    if ( $ch === $quote ) { $i++; break; }
                    $i++;
                }
                continue;
            }

            // Comments
            if ( $c === '/' && $i + 1 < $len ) {
                $next = $js[ $i + 1 ];
                if ( $next === '/' ) {
                    // Single-line — strip
                    $i += 2;
                    while ( $i < $len && $js[ $i ] !== "\n" ) { $i++; }
                    if ( $i < $len ) { $result .= "\n"; $i++; }
                    continue;
                }
                if ( $next === '*' ) {
                    // Multi-line — strip (keep license comments /*! */)
                    $is_license = ( $i + 2 < $len && $js[ $i + 2 ] === '!' );
                    $i         += 2;
                    $comment    = '/*';
                    while ( $i < $len ) {
                        $ch      = $js[ $i ];
                        $comment .= $ch;
                        $i++;
                        if ( $ch === '*' && $i < $len && $js[ $i ] === '/' ) {
                            $comment .= '/';
                            $i++;
                            break;
                        }
                    }
                    if ( $is_license ) { $result .= $comment; }
                    continue;
                }
            }

            // Whitespace compression
            if ( $c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" ) {
                $prev      = strlen( $result ) > 0 ? $result[ strlen( $result ) - 1 ] : '';
                $j         = $i + 1;
                while ( $j < $len && in_array( $js[ $j ], [ ' ', "\t", "\r", "\n" ], true ) ) { $j++; }
                $next_char = $j < $len ? $js[ $j ] : '';

                $no_space_after  = [ '(', '[', '{', '!', '~', ',', ';', ':', '?' ];
                $no_space_before = [ ')', ']', '}', ',', ';', ':', '.', '?' ];

                if ( $prev !== '' && $next_char !== ''
                    && ! in_array( $prev, $no_space_after, true )
                    && ! in_array( $next_char, $no_space_before, true ) ) {
                    $result .= ' ';
                }
                $i = $j;
                continue;
            }

            $result .= $c;
            $i++;
        }

        $version = defined( 'CLEVERSAY_VERSION' ) ? CLEVERSAY_VERSION : '';
        $banner  = "/*! CleverSay embed.js v{$version} | (c) " . gmdate( 'Y' ) . " */\n";

        return (bool) file_put_contents( $dest, $banner . trim( $result ) );
    }
}
