<?php
/*
 * Plugin Name: My Polylang FSE Translation
 * Description: Automates string registration for Polylang block content, including synced patterns with overrides.
 * Version: 1.4
 * Author: Aron & Grok
 * Depends: polylang/polylang.php
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'my_polylang_fse_init');
function my_polylang_fse_init() {
    define('DEBUG_LEVEL', 'ALL');
    // Rest of your existing code here, starting from log_debug function
    // ...
}