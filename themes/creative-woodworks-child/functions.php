<?php
// Enqueue parent and child theme styles
add_action('wp_enqueue_scripts', 'creative_woodworks_child_enqueue_styles');
function creative_woodworks_child_enqueue_styles() {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('child-style', get_stylesheet_uri(), array('parent-style'));
}
