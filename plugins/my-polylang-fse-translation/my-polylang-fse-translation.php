<?php
/*
Plugin Name: My Polylang FSE Translation
Description: Automates string registration for Polylang block content, including synced patterns, template parts, and reusable blocks.
Version: 1.5
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
log_debug('Polylang block script initialized', 'INFO', [
    'file' => __FILE__,
    'hook' => current_action(),
    'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
    'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'unknown',
    'is_ajax' => defined('DOING_AJAX') && DOING_AJAX
]);

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

// Initialize Polylang check
function initialize_polylang_check() {
    if (defined('DOING_AJAX') && DOING_AJAX && (!isset($_REQUEST['action']) || strpos($_REQUEST['action'], 'pll_') !== 0)) {
        log_debug('Skipping initialization during non-Polylang AJAX', 'INFO', ['action' => isset($_REQUEST['action']) ? $_REQUEST['action'] : 'none']);
        return false;
    }

    if (!function_exists('pll_register_string')) {
        log_debug('Polylang not active', 'ERROR', [
            'file' => __FILE__,
            'active_plugins' => get_option('active_plugins', []),
            'polylang_version' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : 'not_defined',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
            'hook' => current_action()
        ]);
        return false;
    }

    log_debug('Polylang detected', 'INFO', [
        'polylang_version' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : 'not_defined',
        'languages' => function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [],
        'hook' => current_action()
    ]);

    pll_register_string('site_header_content', 'Site Header Content', 'Theme', true);
    pll_register_string('site_footer_content', 'Site Footer Content', 'Theme', true);
    log_debug('Registered Site Header and Footer Content groups with Polylang', 'INFO');
    return true;
}

// Try plugins_loaded, fall back to init
add_action('plugins_loaded', function () {
    if (!initialize_polylang_check()) {
        add_action('init', 'initialize_polylang_check', 20);
    }
});

// Function to extract translatable strings from block content
function extract_translatable_strings($content, $context = 'block') {
    log_debug('Starting string extraction', 'INFO', ['content_length' => strlen($content), 'context' => $context]);
    $strings = [];
    $blocks = parse_blocks($content);
    
    if (empty($blocks)) {
        log_debug('No blocks parsed from content', 'WARNING', ['content' => substr($content, 0, 100), 'context' => $context]);
        return $strings;
    }
    
    foreach ($blocks as $block) {
        // Log block details for debugging
        log_debug('Processing block', 'INFO', ['block_type' => $block['blockName'], 'attrs' => $block['attrs'], 'context' => $context]);

        // Handle innerContent
        if (!empty($block['innerContent'])) {
            foreach ($block['innerContent'] as $inner_content) {
                if (is_string($inner_content) && !empty(trim($inner_content))) {
                    $cleaned = trim(strip_tags($inner_content));
                    if ($cleaned) {
                        $strings[] = $cleaned;
                        log_debug('Extracted string from inner content', 'INFO', ['string' => $cleaned, 'context' => $context]);
                    }
                }
            }
        }
        // Handle common text attributes
        $text_attributes = ['text', 'content', 'title', 'description'];
        foreach ($text_attributes as $attr) {
            if (!empty($block['attrs'][$attr])) {
                $cleaned = trim(strip_tags($block['attrs'][$attr]));
                if ($cleaned) {
                    $strings[] = $cleaned;
                    log_debug("Extracted string from $attr attribute", 'INFO', ['string' => $cleaned, 'context' => $context]);
                }
            }
        }
        // Handle overrides in synced patterns
        if (!empty($block['attrs']['metadata']['bindings'])) {
            foreach ($block['attrs']['metadata']['bindings'] as $binding) {
                if (!empty($binding['args']['value'])) {
                    $cleaned = trim(strip_tags($binding['args']['value']));
                    if ($cleaned) {
                        $strings[] = $cleaned;
                        log_debug('Extracted string from pattern override', 'INFO', ['string' => $cleaned, 'context' => $context]);
                    }
                }
            }
        }
        // Process inner blocks
        if (!empty($block['innerBlocks'])) {
            $inner_strings = extract_translatable_strings(serialize_blocks($block['innerBlocks']), $context);
            $strings = array_merge($strings, $inner_strings);
            log_debug('Processed inner blocks', 'INFO', ['inner_block_count' => count($block['innerBlocks']), 'context' => $context]);
        }
    }
    
    $strings = array_unique(array_filter($strings));
    if (empty($strings)) {
        log_debug('No translatable strings found', 'WARNING', ['context' => $context]);
    } else {
        log_debug('Extracted translatable strings', 'INFO', ['string_count' => count($strings), 'strings' => $strings, 'context' => $context]);
    }
    return $strings;
}

// Function to clean up old strings
function unregister_old_strings($post_id, $post, $context = 'block') {
    global $wpdb;
    log_debug('Unregistering old strings', 'INFO', ['post_id' => $post_id, 'post_type' => $post->post_type, 'context' => $context]);
    
    $prefix = ($post->post_type === 'wp_block' || $context === 'pattern_instance') ? 'pattern_' : 'template_part_';
    $string_prefix = $prefix . $post_id . '_';
    
    $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
    if (empty($languages)) {
        log_debug('No Polylang languages configured', 'WARNING', ['post_id' => $post_id, 'context' => $context]);
        return;
    }
    log_debug('Found Polylang languages', 'INFO', ['languages' => $languages, 'context' => $context]);
    
    $unregistered_count = 0;
    foreach ($languages as $lang) {
        $option_name = 'polylang_mo_' . $lang;
        $mo_data = get_option($option_name, []);
        if (!is_array($mo_data)) {
            log_debug('Invalid Polylang MO data for language', 'WARNING', ['post_id' => $post_id, 'language' => $lang, 'context' => $context]);
            continue;
        }
        
        $updated = false;
        foreach ($mo_data as $key => $translation) {
            if (isset($translation['name']) && strpos($translation['name'], $string_prefix) === 0) {
                unset($mo_data[$key]);
                $updated = true;
                $unregistered_count++;
                log_debug('Unregistered old string', 'INFO', ['string_name' => $translation['name'], 'language' => $lang, 'context' => $context]);
            }
        }
        
        if ($updated) {
            update_option($option_name, array_values($mo_data));
            log_debug('Updated Polylang MO data for language', 'INFO', ['language' => $lang, 'post_id' => $post_id, 'context' => $context]);
        }
    }
    
    log_debug('Unregistered old strings completed', 'INFO', ['post_id' => $post_id, 'count' => $unregistered_count, 'context' => $context]);
}

// Function to register strings with Polylang
function register_block_strings($post_id, $post, $update, $context = 'block') {
    log_debug('Processing post for string registration', 'INFO', [
        'post_id' => $post_id,
        'post_type' => $post->post_type,
        'update' => $update,
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
        'post_title' => $post->post_title,
        'content_snippet' => substr($post->post_content, 0, 100),
        'context' => $context
    ]);
    
    if (!function_exists('pll_register_string')) {
        log_debug('Polylang not active during string registration', 'ERROR', [
            'post_id' => $post_id,
            'active_plugins' => get_option('active_plugins', []),
            'polylang_version' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : 'not_defined',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
            'context' => $context
        ]);
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        log_debug('Skipping autosave', 'WARNING', ['post_id' => $post_id, 'context' => $context]);
        return;
    }
    if (wp_is_post_revision($post_id)) {
        log_debug('Skipping revision', 'WARNING', ['post_id' => $post_id, 'context' => $context]);
        return;
    }

    $is_block = $post->post_type === 'wp_block';
    $is_template_part = $post->post_type === 'wp_template_part';
    $is_post_page = in_array($post->post_type, ['post', 'page']);
    
    if (!$is_block && !$is_template_part && !$is_post_page) {
        log_debug('Post type not supported', 'WARNING', ['post_id' => $post_id, 'post_type' => $post->post_type, 'context' => $context]);
        return;
    }

    // Removed my-patterns category restriction for wp_block
    if ($is_template_part && !in_array($post->post_name, ['header', 'footer'])) {
        log_debug('Template part not header or footer', 'WARNING', ['post_id' => $post_id, 'post_name' => $post->post_name, 'context' => $context]);
        return;
    }

    if ($post->post_author != get_current_user_id()) {
        log_debug('Post not modified by current user', 'WARNING', ['post_id' => $post_id, 'author' => $post->post_author, 'current_user' => get_current_user_id(), 'context' => $context]);
        return;
    }

    $content = $post->post_content;
    if (empty($content)) {
        log_debug('Post content empty', 'WARNING', ['post_id' => $post_id, 'context' => $context]);
        return;
    }

    $cache_key = 'block_strings_' . $post_id . '_' . $context;
    $prev_content_hash = get_transient($cache_key . '_hash');
    $current_content_hash = md5($content);
    $strings = false;
    
    if ($prev_content_hash !== $current_content_hash) {
        log_debug('Content changed, invalidating cache', 'INFO', ['post_id' => $post_id, 'context' => $context]);
        $strings = extract_translatable_strings($content, $context);
        set_transient($cache_key, $strings, DAY_IN_SECONDS);
        set_transient($cache_key . '_hash', $current_content_hash, DAY_IN_SECONDS);
        log_debug('Cached extracted strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key, 'string_count' => count($strings), 'context' => $context]);
    } else {
        $strings = get_transient($cache_key);
        log_debug('Using cached strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key, 'string_count' => is_array($strings) ? count($strings) : 0, 'context' => $context]);
    }

    if ($strings === false) {
        $strings = extract_translatable_strings($content, $context);
        set_transient($cache_key, $strings, DAY_IN_SECONDS);
        set_transient($cache_key . '_hash', $current_content_hash, DAY_IN_SECONDS);
        log_debug('No cache found, extracted and cached strings', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key, 'string_count' => count($strings), 'context' => $context]);
    }

    unregister_old_strings($post_id, $post, $context);

    $group = $is_block ? 'Pattern: ' . $post->post_title : ($is_template_part ? ($post->post_name === 'header' ? 'Site Header Content' : 'Site Footer Content') : 'Post: ' . $post->post_title);
    $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
    if (empty($languages)) {
        log_debug('No Polylang languages configured for registration', 'ERROR', ['post_id' => $post_id, 'context' => $context]);
        return;
    }
    
    $registered_count = 0;
    foreach ($strings as $string) {
        $string_name = ($is_block || $context === 'pattern_instance' ? 'pattern_' : ($is_template_part ? 'template_part_' : 'post_')) . $post_id . '_' . md5($string);
        try {
            pll_register_string($string_name, $string, $group, true);
            $registered_count++;
            log_debug('Registered string with Polylang', 'INFO', ['string_name' => $string_name, 'string' => $string, 'group' => $group, 'context' => $context]);
            
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
                log_debug('Manually stored string in Polylang storage', 'INFO', ['string_name' => $string_name, 'language' => $lang, 'context' => $context]);
            }
        } catch (Exception $e) {
            log_debug('Failed to register string', 'ERROR', ['string_name' => $string_name, 'error' => $e->getMessage(), 'context' => $context]);
        }
    }
    
    if (class_exists('PLL_Cache')) {
        $cache = new PLL_Cache();
        $cache->clean();
        log_debug('Cleared Polylang cache', 'INFO', ['post_id' => $post_id, 'context' => $context]);
    } else {
        log_debug('PLL_Cache class not found, skipping cache clear', 'WARNING', ['post_id' => $post_id, 'context' => $context]);
    }
    
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
    log_debug('Polylang strings from wp_options', 'INFO', ['string_count' => count($all_strings), 'strings' => $all_strings, 'context' => $context]);
    
    log_debug('String registration completed', 'INFO', ['post_id' => $post_id, 'registered_count' => $registered_count, 'context' => $context]);
}

// Hook for reusable blocks and template parts
add_action('wp_after_insert_post', function ($post_id, $post, $update) {
    if ($post->post_type === 'wp_block' || $post->post_type === 'wp_template_part') {
        register_block_strings($post_id, $post, $update, 'block');
    }
}, 20, 3);
add_action('wp_after_insert_post', function ($post_id, $post) {
    if ($post->post_type === 'wp_block' || $post->post_type === 'wp_template_part') {
        unregister_old_strings($post_id, $post, 'block');
    }
}, 19, 2);

// Hook for posts/pages with synced patterns
add_action('wp_after_insert_post', function ($post_id, $post, $update) {
    if (in_array($post->post_type, ['post', 'page'])) {
        $content = $post->post_content;
        if (strpos($content, 'wp:block') !== false) {
            log_debug('Detected synced pattern in post/page', 'INFO', ['post_id' => $post_id, 'post_type' => $post->post_type]);
            register_block_strings($post_id, $post, $update, 'pattern_instance');
        }
    }
}, 20, 3);
add_action('wp_after_insert_post', function ($post_id, $post) {
    if (in_array($post->post_type, ['post', 'page'])) {
        if (strpos($post->post_content, 'wp:block') !== false) {
            unregister_old_strings($post_id, $post, 'pattern_instance');
        }
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
        if ($content === false) {
            log_debug('Failed to read file-based template part', 'ERROR', ['post_id' => $post_id, 'file' => $template_part_file]);
            return;
        }
        $cache_key = 'block_strings_' . $post_id . '_template';
        $current_content_hash = md5($content);
        $prev_content_hash = get_transient($cache_key . '_hash');
        
        if ($prev_content_hash !== $current_content_hash) {
            $strings = extract_translatable_strings($content, 'template');
            if (empty($strings)) {
                log_debug('No strings extracted from file-based template part', 'WARNING', ['content' => substr($content, 0, 100)]);
            } else {
                set_transient($cache_key, $strings, DAY_IN_SECONDS);
                set_transient($cache_key . '_hash', $current_content_hash, DAY_IN_SECONDS);
                log_debug('Extracted and cached strings from file-based template part', 'INFO', ['post_id' => $post_id, 'string_count' => count($strings)]);
                
                $group = ($post->post_name === 'header' ? 'Site Header Content' : 'Site Footer Content');
                foreach ($strings as $string) {
                    $string_name = 'template_part_' . $post_id . '_' . md5($string);
                    try {
                        pll_register_string($string_name, $string, $group, true);
                        log_debug('Registered file-based template part string', 'INFO', ['string_name' => $string_name, 'string' => $string, 'group' => $group]);
                    } catch (Exception $e) {
                        log_debug('Failed to register file-based template part string', 'ERROR', ['string_name' => $string_name, 'error' => $e->getMessage()]);
                    }
                }
            }
        } else {
            log_debug('Using cached strings for file-based template part', 'INFO', ['post_id' => $post_id, 'cache_key' => $cache_key]);
        }
    } else {
        log_debug('No file-based template part found', 'INFO', ['post_id' => $post_id, 'post_name' => $post->post_name]);
    }
}, 10, 3);