<?php
/**
 * Plugin Name: PrintOnNow Blog Post REST Meta (geçici)
 * Description: blog_post özel alanlarını REST API ile yazılabilir yapar. wp-content/mu-plugins/ içine kopyalayın. İş bitince silebilirsiniz.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('register_taxonomy_args', static function (array $args, string $taxonomy): array {
    if ($taxonomy === 'blog_category') {
        $args['show_in_rest'] = true;
        $args['rest_base'] = $args['rest_base'] ?? 'blog_category';
    }
    return $args;
}, 10, 2);

add_action('init', function () {
    $string_rest = [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => function () {
            return current_user_can('edit_posts');
        },
    ];

    register_post_meta('blog_post', '_first_desc', $string_rest);
    register_post_meta('blog_post', '_final_desc', $string_rest);
    register_post_meta('blog_post', '_featured_image_url', $string_rest);

    register_post_meta('blog_post', '_post_images', [
        'type' => 'array',
        'single' => true,
        'show_in_rest' => [
            'schema' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_link' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'desc' => ['type' => 'string'],
                        'image_url' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'auth_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);

    register_post_meta('blog_post', '_blog_tips', [
        'type' => 'array',
        'single' => true,
        'show_in_rest' => [
            'schema' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'active' => ['type' => 'boolean'],
                        'text' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'auth_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);
});
