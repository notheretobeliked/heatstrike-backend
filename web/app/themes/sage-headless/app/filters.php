<?php

/**
 * Theme filters.
 */

namespace App;

/**
 * Add "… Continued" to the excerpt.
 *
 * @return string
 */
add_filter('excerpt_more', function () {
    return sprintf(' &hellip; <a href="%s">%s</a>', get_permalink(), __('Continued', 'sage'));
});

/**
 * Send webhook notification when content is published or saved.
 *
 * @param string $new_status New post status
 * @param string $old_status Old post status
 * @param \WP_Post $post The post object
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    // Only run on staging and production environments
    $environment = env('WP_ENV');
    if (!in_array($environment, ['staging', 'production'])) {
        return;
    }

    // Only fire for specific post types
    $allowed_post_types = ['post', 'page'];
    if (!in_array($post->post_type, $allowed_post_types)) {
        return;
    }

    // Avoid triggering webhook by REST API (called by Gutenberg) to prevent duplicates
    $rest = defined('REST_REQUEST') && REST_REQUEST;
    
    // Only trigger when transitioning from or to publish state, and not via REST
    if (!$rest && ($new_status === 'publish' || $old_status === 'publish')) {
        // Get the webhook URL from environment
        $webhook_url = env('VERCEL_WEBHOOK');
        if (!$webhook_url) {
            return;
        }

        // Send the webhook notification
        wp_remote_post($webhook_url, [
            'timeout' => 5,
            'blocking' => false, // Non-blocking so it doesn't slow down the save
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'event' => 'content_updated',
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'post_status' => $new_status,
                'post_modified' => $post->post_modified,
            ]),
        ]);
    }
}, 10, 3);


/**
 * Enable Application Passwords in development (without HTTPS requirement)
 */
add_filter('wp_is_application_passwords_available', function ($available) {
    // Force enable in development environment
    if (env('WP_ENV') === 'development') {
        return true;
    }
    return $available;
});

/**
 * Suppress EXIF read errors during REST API media uploads.
 * Some images have corrupted/non-standard EXIF data that causes exif_read_data() to emit warnings.
 * Acorn's HandleExceptions converts these warnings to ErrorExceptions, causing 500 errors.
 * This sets up a custom error handler to suppress exif_read_data warnings during media uploads.
 */
add_action('rest_api_init', function () {
    // Only apply to media endpoint
    add_filter('rest_pre_dispatch', function ($result, $server, $request) {
        $route = $request->get_route();

        // Only intercept media uploads
        if ($route === '/wp/v2/media' && $request->get_method() === 'POST') {
            // Set custom error handler that suppresses EXIF warnings
            set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                // Suppress exif_read_data warnings
                if (strpos($errstr, 'exif_read_data') !== false) {
                    return true; // Suppress the error
                }
                // Let other errors through to normal handler
                return false;
            }, E_WARNING | E_NOTICE);

            // Restore error handler after request completes
            add_filter('rest_post_dispatch', function ($response) {
                restore_error_handler();
                return $response;
            }, 10, 1);
        }

        return $result;
    }, 10, 3);
});
