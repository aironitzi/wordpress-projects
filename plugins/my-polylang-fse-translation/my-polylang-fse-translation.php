<?php
/*
Plugin Name: My Polylang FSE Translation
Description: Automates string registration for Polylang block content.
Version: 1.0
Author: Aron & Grok
Depends: polylang/polylang.php
*/


// Debug configuration
define('DEBUG_LEVEL', 'ALL'); // Options: 'ERROR', 'WARNING', 'INFO', 'ALL'

// Prevent redundant initialization
if (defined('POLYLANG_BLOCK_SCRIPT_INITIALIZED')) {
    return;
}
define('POLYLANG_BLOCK_SCRIPT_INITIALIZED', true);
log_debug('Polylang block script initialized', 'INFO', ['file' => __FILE__]);

// Function to log debug messages based on level
function log_debug($message, $level = 'INFO', $context = []) {
    $allowed_levels = ['ERROR', 'WARNING', 'INFO'];
    if (DEBUG_LEVEL !== 'ALL' && !in_array($level, $allowed_levels)) {
        return;
    }
    if (DEBUG_LEVEL !== 'ALL' && $level !== DEBUG_LEVEL && $level !== 'ERROR') {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $context_str = !empty($context) ? json_encode($context) : '';
    $log_message = "[$timestamp] [$level] $message $context_str";
    error_log($log_message);
}


// Ensure Polylang is active
if (!function_exists('pll_register_string')) {
    log_debug('Polylang not active, exiting script', 'ERROR');
    return;
}

// Register translation group
if (function_exists('pll_register_string')) {
    pll_register_string('site_footer_content', 'Site Footer Content', 'Theme', true);
    log_debug('Registered Site Footer Content group with Polylang', 'INFO');
}

// Function to extract translatable strings from block content
function extract_translatable_strings($content) {
    log_debug('Starting string extraction', 'INFO', ['content_length' => strlen($content)]);
    $strings = [];
    $blocks = parse_blocks($content);
    
    if (empty($blocks)) {
        log_debug('No blocks parsed from content', 'WARNING', ['content' => substr($content, 0, 100)]);
        return $strings;
    }
    
    foreach ($blocks as $block) {
        if (!empty($block['innerContent'])) {
            foreach ($block['innerContent'] as $inner_content) {
                if (is_string($inner_content) && !empty(trim($inner_content))) {
                    $cleaned = trim(strip_tags($inner_content));
                    if ($cleaned) {
                        $strings[] = $cleaned;
                        log_debug('Extracted string from inner content', 'INFO', ['string' => $cleaned]);
                    }
                }
            }
        }
        if (!empty($block['attrs']['text'])) {
            $cleaned = trim(strip_tags($block['attrs']['text']));
            if ($cleaned) {
                $strings[] = $cleaned;
                log_debug('Extracted string from text attribute', 'INFO', ['string' => $cleaned]);
            }
        } elseif (!empty($block['attrs']['content'])) {
            $cleaned = trim(strip_tags($block['attrs']['content']));
            if ($cleaned) {
                $strings[] = $cleaned;
                log_debug('Extracted string from content attribute', 'INFO', ['string' => $cleaned]);
            }
        }
        if (!empty($block['innerBlocks'])) {
            $inner_strings = extract_translatable_strings(serialize_blocks($block['innerBlocks']));
            $strings = array_merge($strings, $inner_strings);
            log_debug('Processed inner blocks', 'INFO', ['inner_block_count' => count($block['innerBlocks'])]);
        }
    }
    
    $strings = array_unique(array_filter($strings));
    if (empty($strings)) {
        log_debug('No translatable strings found', 'WARNING');
    } else {
        log_debug('Extracted translatable strings', 'INFO', ['string_count' => count($strings)]);
    }
    return $strings;
}

// Function to clean up old strings
function unregister_old_strings($post_id, $post) {
    global $wpdb;
    log_debug('Unregistering old strings', 'INFO', ['post_id' => $post_id, 'post_type' => $post->post_type]);
    
    $prefix = ($post->post_type === 'wp_block') ? 'pattern_' : 'template_part_';
    $string_prefix = $prefix . $post_id . '_';
    
    $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
    if (empty($languages)) {
        log_debug('No Polylang languages configured', 'WARNING', ['post_id' => $post_id]);
        return;
    }
    log_debug('Found Polylang languages', 'INFO', ['languages' => $languages]);
    
    $unregistered_count = 0;
    foreach ($languages as $lang) {
        $option_name = 'polylang_mo_' . $lang;
        $mo_data = get_option($option_name, []);
        if (!is_array($mo_data)) {
            log_debug('Invalid Polylang MO data for language', 'WARNING', ['post_id' => $post_id, 'language' => $lang]);
            continue;
        }
        
        $updated = false;
        foreach ($mo_data as $key => $translation) {
            if (isset($translation['name']) && strpos($translation['name'], $string_prefix) === 0) {
                unset($mo_data[$key]);
                $updated = true;
                $unregistered_count++;
                log_debug('Unregistered old string', 'INFO', ['string_name' => $translation['name'], 'language' => $lang]);
            }
        }
        
        if ($updated) {
            update_option($option_name, array_values($mo_data));
            log_debug('Updated Polylang MO data for language', 'INFO', ['language' => $lang, 'post_id' => $post_id]);
        }
    }
    
    log_debug('Unregistered old strings completed', 'INFO', ['post_id' => $post_id, 'count' => $unregistered_count]);
}

// Function to register strings with Polylang
function register_block_strings($post_id, $post, $update) {
    log_debug('Processing post for string registration', 'INFO', ['post_id' => $post_id, 'post_type' => $post->post_type, 'update' => $update]);
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        log_debug('Skipping autosave', 'WARNING', ['post_id' => $post_id]);
        return;
    }
    if (wp_is_post_revision($post_id)) {
        log_debug('Skipping revision', 'WARNING', ['post_id' => $post_id]);
        return;
    }

    $is_block = $post->post_type === 'wp_block';
    $is_template_part = $post->post_type === 'wp_template_part';
    
    if (!$is_block && !$is_template_part) {
        log_debug('Post type not supported', 'WARNING', ['post_id' => $post_id, 'post_type' => $post->post_type]);
        return;
    }

    if ($is_block && !has_term('my-patterns', 'category', $post_id)) {
        log_debug('Post not in my-patterns category', 'WARNING', ['post_id' => $post_id]);
        return;
    }

    if ($is_template_part && !in_array($post->post_name, ['header', 'footer'])) {
        log_debug('Template part not header or footer', 'WARNING', ['post_id' => $post_id, 'post_name' => $post->post_name]);
        return;
    }

    if ($post->post_author != get_current_user_id()) {
        log_debug('Post not modified by current user', 'WARNING', ['post_id' => $post_id, 'author' => $post->post_author, 'current_user' => get_current_user_id()]);
        return;
    }

    $content = $post->post_content;
    if (empty($content)) {
        log_debug('Post content empty', 'WARNING', ['post_id' => $post_id]);
        return;
    }

    $cache_key = 'block_strings_' . $post_id;
    $prev_content_hash = get_transient($cache_key . '_hash');
    $current_content_hash = md5($content);
    $strings = false;
    
    if ($prev_content_hash !== $current_content_hash) {
        log_debug('Content changed, invalidating cache', 'INFO', ['post_id' => $post_id]);
        $strings = extract_translatable_strings($content);
        set_transient($cache_key, $strings, DAY_IN_SECONDS);
        set_transient($cache_key . '_hash', $current_content_hash, DAY_IN_SECONDS);
        log_debug('Cached extracted strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key]);
    } else {
        $strings = get_transient($cache_key);
        log_debug('Using cached strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key]);
    }

    if ($strings === false) {
        $strings = extract_translatable_strings($content);
        set_transient($cache_key, $strings, DAY_IN_SECONDS);
        set_transient($cache_key . '_hash', $current_content_hash, DAY_IN_SECONDS);
        log_debug('No cache found, extracted and cached strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key]);
    }

    unregister_old_strings($post_id, $post);

    $group = $is_block ? 'Pattern: ' . $post->post_title : ($post->post_name === 'header' ? 'Site Header Content' : 'Site Footer Content');
    $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
    if (empty($languages)) {
        log_debug('No Polylang languages configured for registration', 'ERROR', ['post_id' => $post_id]);
        return;
    }
    
    $registered_count = 0;
    foreach ($strings as $string) {
        $string_name = ($is_block ? 'pattern_' : 'template_part_') . $post_id . '_' . md5($string);
        try {
            pll_register_string($string_name, $string, $group, true);
            $registered_count++;
            log_debug('Registered string with Polylang', 'INFO', ['string_name' => $string_name, 'string' => $string, 'group' => $group]);
            
            // Manually store in Polylang format
            foreach ($languages as $lang) {
                $option_name = 'polylang_mo_' . $lang;
                $mo_data = get_option($option_name, []);
                $mo_data[] = [
                    'msgid' => $string,
                    'msgstr' => '',
                    'name' => $string_name,
                    'context' => $group,
                ];
                update_option($option_name, $mo_data);
                log_debug('Manually stored string in Polylang storage', 'INFO', ['string_name' => $string_name, 'language' => $lang]);
            }
        } catch (Exception $e) {
            log_debug('Failed to register string', 'ERROR', ['string_name' => $string_name, 'error' => $e->getMessage()]);
        }
    }
    
    // Force Polylang cache refresh
    if (class_exists('PLL_Cache')) {
        $cache = new PLL_Cache();
        $cache->clean();
        log_debug('Cleared Polylang cache', 'INFO', ['post_id' => $post_id]);
    }
    
    // Debug Polylang strings from wp_options
    $all_strings = [];
    foreach ($languages as $lang) {
        $option_name = 'polylang_mo_' . $lang;
        $mo_data = get_option($option_name, []);
        foreach ($mo_data as $entry) {
            if (isset($entry['context']) && $entry['context'] === $group) {
                $all_strings[] = ['name' => $entry['name'], 'string' => $entry['msgid']];
            }
        }
    }
    log_debug('Polylang strings from wp_options', 'INFO', ['string_count' => count($all_strings), 'strings' => $all_strings]);
    
    log_debug('String registration completed', 'INFO', ['post_id' => $post_id, 'registered_count' => $registered_count]);
}

// Hook into a later action
add_action('wp_after_insert_post', function ($post_id, $post, $update) {
    if ($post->post_type === 'wp_block' || $post->post_type === 'wp_template_part') {
        register_block_strings($post_id, $post, $update);
    }
}, 20, 3);
add_action('wp_after_insert_post', function ($post_id, $post) {
    if ($post->post_type === 'wp_block' || $post->post_type === 'wp_template_part') {
        unregister_old_strings($post_id, $post);
    }
}, 19, 2);

// Check for file-based template parts
add_action('save_post_wp_template_part', function ($post_id, $post, $update) {
    $child_theme = get_stylesheet_directory() . "/parts/{$post->post_name}.html";
    $parent_theme = get_template_directory() . "/parts/{$post->post_name}.html";
    $template_part_file = file_exists($child_theme) ? $child_theme : (file_exists($parent_theme) ? $parent_theme : false);
    
    if ($template_part_file) {
        log_debug('Found file-based template part', 'INFO', ['post_id' => $post_id, 'file' => $template_part_file]);
        $content = file_get_contents($template_part_file);
        $cache_key = 'block_strings_' . $post_id;
        $strings = extract_translatable_strings($content);
        if (empty($strings)) {
            log_debug('No strings extracted from file-based template part', 'WARNING', ['content' => substr($content, 0, 100)]);
        }
        set_transient($cache_key, $strings, DAY_IN_SECONDS);
        set_transient($cache_key . '_hash', md5($content), DAY_IN_SECONDS);
        log_debug('Extracted strings from file-based template part', 'INFO', ['post_id' => $post_id, 'string_count' => count($strings)]);
    }
}, 10, 3);

// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('register-block-strings', function () {
        log_debug('Starting WP-CLI register-block-strings command', 'INFO');
        $post_types = ['wp_block', 'wp_template_part'];
        $total_processed = 0;
        
        foreach ($post_types as $post_type) {
            $args = [
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_author' => get_current_user_id(),
            ];
            if ($post_type == 'wp_block') {
                $args['tax_query'] = [
                    [
                        'taxonomy' => 'category',
                        'field' => 'slug',
                        'terms' => 'my-patterns',
                    ],
                ];
            }
            if ($post_type === 'wp_template_part') {
                $args['post_name__in'] = ['header', 'footer'];
            }
            
            $posts = get_posts($args);
            log_debug('Fetched posts for processing', 'INFO', ['post_type' => $post_type, 'post_count' => count($posts)]);
            
            foreach ($posts as $post) {
                register_block_strings($post->ID, $post, true);
                $total_processed++;
            }
        }
        
        WP_CLI::success("Successfully registered for $total_processed posts.");
        log_debug('WP-CLI command completed', 'INFO', ['total_processed' => $total_processed]);
    });

    WP_CLI::add_command('inspect-polylang-strings', function () {
        log_debug('Starting WP-CLI inspect-polylang-strings command', 'INFO');
        $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
        if (empty($languages)) {
            log_debug('Error: No Polylang languages configured', 'ERROR');
            WP_CLI::error('No Polylang languages configured.');
            return;
        }
        
        foreach ($languages as $lang) {
            $option_name = 'polylang_mo_' . $lang;
            $mo_data = get_option($option_name, []);
            if (!is_array($mo_data)) {
                log_debug('Invalid Polylang MO data', 'WARNING', ['language' => $lang]);
                continue;
            }
            
            $strings = [];
            foreach ($mo_data as $translation) {
                if (isset($translation['name']) && (strpos($translation['name'], 'pattern_') === 0 || strpos($translation['name'], 'template_part_') === 0)) {
                    $strings[] = $translation;
                }
            }
            
            log_debug('Found Polylang strings', 'INFO', ['language' => $lang, 'string_count' => count($strings)]);
            WP_CLI::log("Language: $lang, Strings: " . count($strings));
            foreach ($strings as $string) {
                $str = isset($string['msgid']) ? $string['msgid'] : (isset($string['string']) ? $string['string'] : '');
                WP_CLI::log("  Name: {$string['name']}, String: {$str}, Group: " . (isset($string['context']) ? $string['context'] : ''));
            }
        }
        
        WP_CLI::success('Polylang strings inspection completed.');
    });
}