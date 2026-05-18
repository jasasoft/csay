<?php
/**
 * Smalot/PdfParser autoloader (PSR-0).
 *
 * Smalot's composer.json declares PSR-0 autoloading mapping
 * `Smalot\PdfParser\` to `src/`. Since we don't run Composer in this
 * plugin, we register a small spl_autoload_register that performs the
 * same mapping pointed at our vendored layout.
 *
 * Loaded by AI::extract_pdf_with_smalot() via require_once before any
 * Smalot class is referenced. Idempotent — if the autoloader is
 * already registered, this file is a no-op on the second include.
 *
 * @package CleverSay
 * @since   4.42.30
 */

if (!defined('ABSPATH')) exit;

if (!defined('CLEVERSAY_SMALOT_AUTOLOAD_REGISTERED')) {
    define('CLEVERSAY_SMALOT_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        // Only handle classes in our vendored namespaces. Returning
        // early for anything else lets other autoloaders (WP, Composer
        // in the host, etc.) try.
        if (strncmp($class, 'Smalot\\PdfParser\\', 17) !== 0) {
            return;
        }
        // Translate namespace separators to directory separators and
        // resolve from the includes/lib/ root. The class
        // Smalot\PdfParser\Page lives at:
        //   {plugin}/includes/lib/Smalot/PdfParser/Page.php
        // __DIR__ here is {plugin}/includes/lib/Smalot, so go up one
        // level and append the namespace-as-path.
        $rel  = str_replace('\\', '/', $class);
        $path = dirname(__DIR__) . '/' . $rel . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });
}
