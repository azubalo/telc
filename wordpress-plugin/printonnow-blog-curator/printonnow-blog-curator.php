<?php
/**
 * Plugin Name: PrintOnNow Blog Curator (Ollama)
 * Description: Builds blog_post drafts from a post category using Ollama; matches custom meta (first_desc, image grid, tips, etc.).
 * Version: 1.1.1
 * Author: PrintOnNow
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Install under wp-content/plugins/printonnow-blog-curator/. Remote WordPress needs a reachable Ollama URL (tunnel from your PC).
 * Settings → PrintOnNow Ollama. Tools → Blog Curator or Blog Posts → Generate drafts (Ollama).
 */
if (!defined('ABSPATH')) {
    exit;
}

define('PNBC_VERSION', '1.1.1');
define('PNBC_OPTION', 'pnbc_settings');

function pnbc_defaults(): array {
    return [
        'ollama_url' => 'http://127.0.0.1:11434',
        'ollama_model' => 'llama3.2',
        'timeout' => 600,
        'sslverify' => true,
    ];
}

function pnbc_snip_raw(string $raw, int $max = 400): string {
    $raw = preg_replace('/\s+/u', ' ', $raw);
    if (function_exists('mb_substr')) {
        return mb_substr($raw, 0, $max, 'UTF-8');
    }
    return substr($raw, 0, $max);
}

/**
 * Calls Ollama /api/chat. Tries JSON format first, then plain (older Ollama).
 *
 * @return array{ok:bool, content:string, detail:string}
 */
function pnbc_ollama_chat_result(string $system, string $user): array {
    $s = pnbc_get_settings();
    $url = rtrim($s['ollama_url'], '/') . '/api/chat';
    $timeout = max(30, (int) $s['timeout']);
    $sslverify = array_key_exists('sslverify', $s) ? (bool) $s['sslverify'] : true;

    $base_args = [
        'headers' => array_merge(pnbc_ollama_request_headers(), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]),
        'timeout' => $timeout,
        'sslverify' => $sslverify,
    ];

    $last_fail = '';

    foreach ([true, false] as $use_json_format) {
        $payload = [
            'model' => $s['ollama_model'],
            'stream' => false,
            'options' => [
                'num_predict' => 8192,
            ],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($use_json_format) {
            $payload['format'] = 'json';
        }

        $res = wp_remote_post($url, array_merge($base_args, [
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]));

        if (is_wp_error($res)) {
            return [
                'ok' => false,
                'content' => '',
                'detail' => 'Connection error: ' . $res->get_error_message()
                    . ' — On shared hosting, http://127.0.0.1 is the SERVER, not your PC. Use a tunnel URL (ngrok, Cloudflare Tunnel) to the PC where Ollama runs, and paste that URL in Settings.',
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $snip = pnbc_snip_raw($raw, 500);

        if ($code < 200 || $code >= 300) {
            $last_fail = 'HTTP ' . $code . ' — ' . $snip;
            if ($code === 403) {
                $last_fail .= ' — If this is ngrok or a tunnel: update plugin to 1.1.1+ (browser-like User-Agent). If it persists, try ngrok paid/static domain or a Cloudflare named tunnel; some hosts block free tunnel domains.';
            }
            if ($use_json_format && ($code === 400 || $code === 404)) {
                continue;
            }
            return ['ok' => false, 'content' => '', 'detail' => $last_fail];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'content' => '', 'detail' => 'Ollama body is not JSON: ' . $snip];
        }

        if (!empty($data['error'])) {
            $em = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
            $last_fail = 'Ollama error: ' . $em;
            if ($use_json_format) {
                continue;
            }
            return ['ok' => false, 'content' => '', 'detail' => $last_fail];
        }

        $content = (string) ($data['message']['content'] ?? '');
        if ($content === '' && isset($data['response'])) {
            $content = (string) $data['response'];
        }

        if ($content === '' && $use_json_format) {
            $last_fail = 'Empty reply with JSON format. Retrying without format… (raw: ' . $snip . ')';
            continue;
        }

        if ($content === '') {
            return ['ok' => false, 'content' => '', 'detail' => 'Empty model output. Raw: ' . $snip];
        }

        return ['ok' => true, 'content' => $content, 'detail' => ''];
    }

    return [
        'ok' => false,
        'content' => '',
        'detail' => $last_fail ?: 'All Ollama attempts failed. Check URL, model name, and that Ollama is running.',
    ];
}

/**
 * Create or resolve post_tag terms and assign to blog_post.
 *
 * @param list<string> $names
 */
function pnbc_apply_ai_post_tags(int $post_id, array $names): void {
    $term_ids = [];
    foreach ($names as $name) {
        $name = sanitize_text_field((string) $name);
        if ($name === '' || strlen($name) > 100) {
            continue;
        }
        $slug = sanitize_title($name);
        $ex = term_exists($name, 'post_tag');
        if (is_array($ex) && !empty($ex['term_id'])) {
            $term_ids[] = (int) $ex['term_id'];
            continue;
        }
        if (is_int($ex) || (is_numeric($ex) && (int) $ex > 0)) {
            $term_ids[] = (int) $ex;
            continue;
        }
        $exs = term_exists($slug, 'post_tag');
        if (is_array($exs) && !empty($exs['term_id'])) {
            $term_ids[] = (int) $exs['term_id'];
            continue;
        }
        $ins = wp_insert_term($name, 'post_tag', ['slug' => $slug]);
        if (!is_wp_error($ins) && !empty($ins['term_id'])) {
            $term_ids[] = (int) $ins['term_id'];
        }
    }
    $term_ids = array_values(array_unique(array_filter($term_ids)));
    if ($term_ids !== []) {
        wp_set_object_terms($post_id, $term_ids, 'post_tag', false);
    }
}

/**
 * Second-pass SEO / category fit check (JSON from Ollama).
 *
 * @param array<string,mixed> $draft Flattened draft for review
 * @return array{approved:bool, score:int, matches_blog_category:bool, seo_ok:bool, notes:string}
 */
function pnbc_seo_validate_draft(array $draft, string $source_cat_name, string $blog_cat_name, string $lang): array {
    $fail = [
        'approved' => true,
        'score' => 0,
        'matches_blog_category' => true,
        'seo_ok' => true,
        'notes' => 'Validation skipped (Ollama error).',
    ];

    $system = <<<SYS
You are a strict SEO and editorial reviewer for PrintOnNow (print-on-demand / design products).
The draft is a curated blog post: intro, product grid with custom blurbs, tips, closing.
Source products came from WordPress category: "{$source_cat_name}".
The post will be filed under blog category: "{$blog_cat_name}".
Target language for visible copy: "{$lang}".

Return ONE JSON object only:
{
  "approved": true or false,
  "score": 1-10,
  "matches_blog_category": true or false,
  "seo_ok": true or false,
  "notes": "1-3 short sentences: what works or what to fix"
}
Approve if: title and excerpt are compelling and keyword-reasonable, content fits the blog category theme, not spammy, appropriate for shoppers.
Reject if: obvious mismatch with blog category, empty/generic SEO, or misleading claims.
SYS;

    $user = "Draft summary (JSON):\n" . wp_json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $chat = pnbc_ollama_chat_result($system, $user);
    if (!$chat['ok'] || $chat['content'] === '') {
        $fail['notes'] = 'Validator call failed: ' . ($chat['detail'] ?? '');
        return $fail;
    }

    $parsed = pnbc_extract_json_object($chat['content']);
    if (!is_array($parsed)) {
        $fail['notes'] = 'Validator returned non-JSON.';
        return $fail;
    }

    return [
        'approved' => !empty($parsed['approved']),
        'score' => max(0, min(10, (int) ($parsed['score'] ?? 0))),
        'matches_blog_category' => !empty($parsed['matches_blog_category']),
        'seo_ok' => !empty($parsed['seo_ok']),
        'notes' => sanitize_text_field((string) ($parsed['notes'] ?? '')),
    ];
}

function pnbc_get_settings(): array {
    $o = get_option(PNBC_OPTION, []);
    return array_merge(pnbc_defaults(), is_array($o) ? $o : []);
}

/**
 * Headers for Ollama HTTP calls. Default WordPress User-Agent is often blocked by tunnel edges (403).
 *
 * @return array<string, string>
 */
function pnbc_ollama_request_headers(): array {
    $h = [
        'Accept' => 'application/json',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 PrintOnNowBlogCurator',
        'ngrok-skip-browser-warning' => '69420',
    ];
    return apply_filters('pnbc_ollama_request_headers', $h);
}

/**
 * Ollama /api/tags — yüklü model adları (ollama list ile aynı isimler).
 *
 * @return list<string>
 */
function pnbc_fetch_ollama_model_names(string $base_url, int $timeout = 12): array {
    $base_url = trim($base_url);
    if ($base_url === '') {
        return [];
    }
    $s = pnbc_get_settings();
    $sslverify = array_key_exists('sslverify', $s) ? (bool) $s['sslverify'] : true;
    $url = rtrim($base_url, '/') . '/api/tags';
    $res = wp_remote_get($url, [
        'timeout' => $timeout,
        'sslverify' => $sslverify,
        'headers' => pnbc_ollama_request_headers(),
    ]);
    if (is_wp_error($res)) {
        return [];
    }
    $code = wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        return [];
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data) || empty($data['models']) || !is_array($data['models'])) {
        return [];
    }
    $names = [];
    foreach ($data['models'] as $row) {
        if (!empty($row['name']) && is_string($row['name'])) {
            $names[] = $row['name'];
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function pnbc_sanitize_blog_html(string $html): string {
    $allowed = '<p><br><strong><b><em><i><a><span><ul><ol><li><h2><h3><h4>';
    return strip_tags($html, $allowed);
}

/**
 * Qwen / bazı modeller düşünme etiketleri veya fazla metin ekler.
 */
function pnbc_strip_thinking_wrappers(string $text): string {
    $text = (string) $text;
    $t_open = '<' . 'think' . '>';
    $t_close = '<' . '/' . 'think' . '>';
    $rr_open = '<' . 'redacted_reasoning' . '>';
    $rr_close = '<' . '/' . 'redacted_reasoning' . '>';
    $prev = '';
    while ($prev !== $text) {
        $prev = $text;
        $text = preg_replace(
            '#' . preg_quote($t_open, '#') . '[\s\S]*?' . preg_quote($t_close, '#') . '#ius',
            '',
            $text
        );
        $text = preg_replace(
            '#' . preg_quote($rr_open, '#') . '[\s\S]*?' . preg_quote($rr_close, '#') . '#ius',
            '',
            $text
        );
        $text = preg_replace('#\A[\s\p{Zs}]*```(?:json)?\s*#iu', '', $text);
        $text = preg_replace('#\s*```\s*\z#u', '', $text);
    }
    return trim($text);
}

function pnbc_extract_json_object(string $text): ?array {
    $text = pnbc_strip_thinking_wrappers(trim($text));
    if ($text === '') {
        return null;
    }
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
        $text = trim($m[1]);
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $slice = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param list<array{post_link:string,title:string,desc:string,image_url:string}> $sources
 * @param list<mixed> $aiItems
 * @return list<array{post_link:string,title:string,desc:string,image_url:string}>
 */
function pnbc_merge_items_with_sources(array $sources, array $aiItems): array {
    $out = [];
    $n = min(count($sources), 10);
    for ($i = 0; $i < $n; $i++) {
        $src = $sources[$i];
        $ai = (isset($aiItems[$i]) && is_array($aiItems[$i])) ? $aiItems[$i] : [];
        $link = trim((string) ($ai['post_link'] ?? ''));
        if ($link === '') {
            $link = (string) ($src['post_link'] ?? '');
        }
        $img = trim((string) ($ai['image_url'] ?? ''));
        if ($img === '') {
            $img = (string) ($src['image_url'] ?? '');
        }
        $title = trim((string) ($ai['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($src['title'] ?? '');
        }
        $desc = trim((string) ($ai['desc'] ?? ''));
        if ($desc === '') {
            $desc = (string) ($src['desc'] ?? '');
        }
        $out[] = [
            'post_link' => $link,
            'title' => $title,
            'desc' => $desc,
            'image_url' => $img,
        ];
    }
    return $out;
}

/**
 * @param list<int> $post_ids
 * @return list<array{post_link:string,title:string,desc:string,image_url:string}>
 */
function pnbc_fetch_source_items(array $post_ids): array {
    $items = [];
    foreach ($post_ids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $p = get_post($pid);
        if (!$p || $p->post_type !== 'post' || $p->post_status !== 'publish') {
            continue;
        }
        $link = get_permalink($p);
        $title = get_the_title($p);
        $excerpt = wp_strip_all_tags(get_the_excerpt($p));
        if ($excerpt === '') {
            $excerpt = wp_trim_words(wp_strip_all_tags($p->post_content), 40, '…');
        }
        $img = get_the_post_thumbnail_url($p, 'full');
        if (!$img && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $p->post_content, $m)) {
            $img = $m[1];
        }
        $items[] = [
            'post_link' => $link ?: '',
            'title' => $title ?: '',
            'desc' => $excerpt,
            'image_url' => $img ?: '',
        ];
    }
    return $items;
}

/**
 * @param list<array{post_link:string,title:string,desc:string,image_url:string}> $sourceItems
 * @return array{ok:bool,error?:string,data?:array}
 */
function pnbc_run_ollama_for_chunk(array $sourceItems, string $lang, int $step_index, int $step_total, string $retry_feedback = ''): array {
    if ($sourceItems === []) {
        return ['ok' => false, 'error' => 'Source list is empty.'];
    }

    $lang_line = (stripos($lang, 'tr') === 0)
        ? 'Write ALL visible copy (title, excerpt, paragraphs, tips) in natural Turkish.'
        : 'Write ALL visible copy (title, excerpt, paragraphs, tips) in natural English.';

    $system = <<<SYS
You are an editor for PrintOnNow. Output must match a WordPress single-blog layout (hero image, intro, body, numbered product grid, tips sidebar, closing).

This is post {$step_index} of {$step_total} in a batch—use a fresh angle, title, and wording; do not repeat earlier posts in the series.

Layout mapping:
1) featured_image_url → top hero image URL
2) first_desc → intro HTML, 1–3 short <p> tags only
3) content → short bridge for the main editor (do not repeat first_desc)
4) items[] → grid: keep post_link and image_url EXACTLY as in the source JSON; you only rewrite title and desc
5) tips[] → up to 5 objects { "active": true, "text": "..." }
6) final_desc → closing, 1–2 <p>
7) post_tags → 4–8 SEO-oriented WordPress tags (short phrases, same language as visible copy; no hashtag)

Return ONE valid JSON object only. Escape quotes inside strings. No markdown fences, no thinking tags.
{$lang_line}

Schema:
{
  "title": "H1",
  "excerpt": "short summary",
  "first_desc": "<p>...</p>",
  "content": "<p>...</p>",
  "items": [ { "post_link": "...", "title": "...", "desc": "...", "image_url": "..." } ],
  "tips": [ { "active": true, "text": "..." } ],
  "final_desc": "<p>...</p>",
  "featured_image_url": "URL",
  "post_tags": [ "example tag", "another keyword" ]
}
SYS;

    $userPayload = "Source items (JSON):\n" . wp_json_encode($sourceItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($retry_feedback !== '') {
        $userPayload .= "\n\n---\nRevise the draft. Reviewer feedback:\n" . $retry_feedback;
    }

    $chat = pnbc_ollama_chat_result($system, $userPayload);
    if (!$chat['ok']) {
        return ['ok' => false, 'error' => $chat['detail']];
    }

    $rawAi = $chat['content'];
    $parsed = pnbc_extract_json_object($rawAi);
    if ($parsed === null) {
        $hint = '';
        if ($rawAi === '') {
            $hint = ' Empty assistant message after HTTP OK.';
        } else {
            $snippet = function_exists('mb_substr') ? mb_substr($rawAi, 0, 400, 'UTF-8') : substr($rawAi, 0, 400);
            $hint = ' Preview: ' . $snippet;
        }
        return ['ok' => false, 'error' => 'Model did not return valid JSON.' . $hint];
    }

    $aiItems = [];
    if (!empty($parsed['items']) && is_array($parsed['items'])) {
        foreach (array_slice($parsed['items'], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aiItems[] = [
                'post_link' => (string) ($row['post_link'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'desc' => (string) ($row['desc'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
            ];
        }
    }

    $itemsOut = pnbc_merge_items_with_sources($sourceItems, $aiItems);

    $tipsOut = [];
    if (!empty($parsed['tips']) && is_array($parsed['tips'])) {
        foreach (array_slice($parsed['tips'], 0, 5) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $text = trim((string) ($t['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $tipsOut[] = [
                'active' => !empty($t['active']),
                'text' => $text,
            ];
        }
    }

    $title = sanitize_text_field((string) ($parsed['title'] ?? 'Curation'));
    $excerpt = sanitize_textarea_field((string) ($parsed['excerpt'] ?? ''));
    $firstDesc = pnbc_sanitize_blog_html((string) ($parsed['first_desc'] ?? ''));
    $finalDesc = pnbc_sanitize_blog_html((string) ($parsed['final_desc'] ?? ''));
    $contentFromAi = trim((string) ($parsed['content'] ?? ''));
    $default_bridge = (stripos($lang, 'tr') === 0)
        ? '<p>Aşağıda seçilen tasarımları sırayla inceleyebilirsiniz.</p>'
        : '<p>Browse the selected designs below in order.</p>';
    $contentHtml = $contentFromAi !== ''
        ? pnbc_sanitize_blog_html($contentFromAi)
        : $default_bridge;

    $feat = esc_url_raw(trim((string) ($parsed['featured_image_url'] ?? '')));
    if ($feat === '' && $itemsOut !== []) {
        $feat = esc_url_raw($itemsOut[0]['image_url'] ?? '');
    }

    if (trim(strip_tags($finalDesc)) === '') {
        $finalDesc = (stripos($lang, 'tr') === 0)
            ? '<p>Aşağıda benzer temada ürünlere göz atabilirsiniz.</p>'
            : '<p>Discover more products in a similar style below.</p>';
    }

    $postTags = [];
    if (!empty($parsed['post_tags']) && is_array($parsed['post_tags'])) {
        foreach ($parsed['post_tags'] as $tg) {
            $tg = sanitize_text_field((string) $tg);
            if ($tg !== '' && strlen($tg) < 90) {
                $postTags[] = $tg;
            }
        }
        $postTags = array_slice(array_values(array_unique($postTags)), 0, 10);
    }

    return [
        'ok' => true,
        'data' => [
            'title' => $title,
            'excerpt' => $excerpt,
            'first_desc' => $firstDesc,
            'final_desc' => $finalDesc,
            'content' => $contentHtml,
            'items' => $itemsOut,
            'tips' => $tipsOut,
            'featured' => $feat,
            'post_tags' => $postTags,
        ],
    ];
}

/**
 * @param array{title:string,excerpt:string,first_desc:string,final_desc:string,content:string,items:array,tips:array,featured:string,post_tags?:array} $d
 * @param array<string,mixed>|null $seo_review
 * @return int|\WP_Error
 */
function pnbc_insert_blog_post(array $d, int $target_blog_cat_id, ?array $seo_review = null) {
    $post_id = wp_insert_post([
        'post_type' => 'blog_post',
        'post_status' => 'draft',
        'post_title' => $d['title'],
        'post_content' => wp_kses_post($d['content']),
        'post_excerpt' => $d['excerpt'],
    ], true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_first_desc', wp_kses_post($d['first_desc']));
    update_post_meta($post_id, '_final_desc', wp_kses_post($d['final_desc']));
    update_post_meta($post_id, '_featured_image_url', esc_url_raw($d['featured']));
    update_post_meta($post_id, '_post_images', $d['items']);
    update_post_meta($post_id, '_blog_tips', $d['tips']);

    if ($target_blog_cat_id > 0) {
        wp_set_object_terms($post_id, [$target_blog_cat_id], 'blog_category', false);
    }

    $tags = isset($d['post_tags']) && is_array($d['post_tags']) ? $d['post_tags'] : [];
    if ($tags !== []) {
        pnbc_apply_ai_post_tags($post_id, $tags);
    }

    if ($seo_review !== null) {
        update_post_meta($post_id, '_pnbc_seo_review', wp_json_encode($seo_review, JSON_UNESCAPED_UNICODE));
    }

    return $post_id;
}

add_action('init', function () {
    register_post_meta('blog_post', '_first_desc', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => static fn () => current_user_can('edit_posts'),
    ]);
    register_post_meta('blog_post', '_final_desc', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => static fn () => current_user_can('edit_posts'),
    ]);
    register_post_meta('blog_post', '_featured_image_url', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => static fn () => current_user_can('edit_posts'),
    ]);
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
        'auth_callback' => static fn () => current_user_can('edit_posts'),
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
        'auth_callback' => static fn () => current_user_can('edit_posts'),
    ]);
    register_post_meta('blog_post', '_pnbc_seo_review', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => static fn () => current_user_can('edit_posts'),
    ]);
}, 20);

add_filter('register_taxonomy_args', static function (array $args, string $taxonomy): array {
    if ($taxonomy === 'blog_category') {
        $args['show_in_rest'] = true;
        $args['rest_base'] = $args['rest_base'] ?? 'blog_category';
    }
    return $args;
}, 10, 2);

add_action('admin_menu', function () {
    add_management_page(
        'Blog Curator (Ollama)',
        'Blog Curator',
        'manage_options',
        'printonnow-blog-curator',
        'pnbc_render_tools_page'
    );

    if (post_type_exists('blog_post')) {
        add_submenu_page(
            'edit.php?post_type=blog_post',
            'Generate drafts (Ollama)',
            'Generate drafts (Ollama)',
            'manage_options',
            'printonnow-blog-curator-blog',
            'pnbc_render_tools_page'
        );
    }

    add_options_page(
        'PrintOnNow Ollama',
        'PrintOnNow Ollama',
        'manage_options',
        'printonnow-blog-curator-settings',
        'pnbc_render_settings_page'
    );
});

function pnbc_render_settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_POST['pnbc_save']) && check_admin_referer('pnbc_save_settings')) {
        update_option(PNBC_OPTION, [
            'ollama_url' => esc_url_raw(trim((string) ($_POST['ollama_url'] ?? ''))),
            'ollama_model' => sanitize_text_field((string) ($_POST['ollama_model'] ?? '')),
            'timeout' => max(30, min(1800, (int) ($_POST['timeout'] ?? 600))),
            'sslverify' => empty($_POST['pnbc_sslverify_off']),
        ]);
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }
    $s = pnbc_get_settings();
    $model_list = pnbc_fetch_ollama_model_names($s['ollama_url'], 12);
    $current_model = (string) $s['ollama_model'];
    if ($current_model !== '' && !in_array($current_model, $model_list, true)) {
        array_unshift($model_list, $current_model);
    }
    $ssl_off = empty($s['sslverify']);
    ?>
    <div class="wrap">
        <h1>PrintOnNow Ollama</h1>
        <p><strong>Important:</strong> This WordPress server must reach Ollama over HTTP(S). On DreamHost (or any remote host), <code>http://127.0.0.1:11434</code> points to <em>the hosting server</em>, not your home PC. Run Ollama on your computer and expose it with <strong>ngrok</strong> or <strong>Cloudflare Tunnel</strong>, then paste that HTTPS URL here.</p>
        <form method="post">
            <?php wp_nonce_field('pnbc_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="ollama_url">Ollama base URL</label></th>
                    <td><input type="url" class="regular-text" id="ollama_url" name="ollama_url" value="<?php echo esc_attr($s['ollama_url']); ?>" required placeholder="https://your-tunnel.example.com"></td>
                </tr>
                <tr>
                    <th><label for="pnbc_model_select">Model</label></th>
                    <td>
                        <?php if ($model_list !== []) : ?>
                            <select id="pnbc_model_select" class="regular-text" style="max-width:100%;margin-bottom:8px;">
                                <option value="">— Pick from list —</option>
                                <?php foreach ($model_list as $mname) : ?>
                                    <option value="<?php echo esc_attr($mname); ?>"<?php selected($current_model, $mname); ?>><?php echo esc_html($mname); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" style="margin-top:0;">Loaded from <code>/api/tags</code> using the URL above (same names as <code>ollama list</code>).</p>
                        <?php else : ?>
                            <p class="description">Could not load models (Ollama unreachable from this server, wrong URL, or firewall). Type the exact name from <code>ollama list</code> below.</p>
                        <?php endif; ?>
                        <label for="ollama_model" class="screen-reader-text">Model name</label>
                        <input type="text" class="regular-text" id="ollama_model" name="ollama_model" value="<?php echo esc_attr($current_model); ?>" required autocomplete="off" placeholder="qwen3:30b-a3b-instruct-2507-q4_K_M">
                        <p class="description">Choosing from the dropdown fills this field; you can edit it.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="timeout">Request timeout (seconds)</label></th>
                    <td><input type="number" id="timeout" name="timeout" value="<?php echo (int) $s['timeout']; ?>" min="60" max="1800"> <span class="description">Default 600s; large models may need 900–1800. Browser waits until the server responds.</span></td>
                </tr>
                <tr>
                    <th>SSL verification</th>
                    <td>
                        <label><input type="checkbox" name="pnbc_sslverify_off" value="1" <?php checked($ssl_off); ?>> Disable SSL certificate verify (only if your tunnel uses a broken/self-signed cert—less secure)</label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save', 'primary', 'pnbc_save'); ?>
        </form>
        <script>
        (function () {
            var sel = document.getElementById('pnbc_model_select');
            var inp = document.getElementById('ollama_model');
            if (sel && inp) {
                sel.addEventListener('change', function () {
                    if (this.value) { inp.value = this.value; }
                });
            }
        })();
        </script>
    </div>
    <?php
}

function pnbc_render_tools_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $ok_cpt = post_type_exists('blog_post');
    $ok_tax = taxonomy_exists('blog_category');

    if (!$ok_cpt || !$ok_tax) {
        echo '<div class="wrap"><h1>Blog Curator</h1>';
        echo '<div class="notice notice-error"><p>Missing <code>blog_post</code> post type or <code>blog_category</code> taxonomy. Load your theme that registers them.</p></div></div>';
        return;
    }

    $nonce = wp_create_nonce('pnbc');
    wp_enqueue_script('jquery');
    ?>
    <div class="wrap">
        <h1>Blog Curator (Ollama)</h1>
        <p>Source: normal <strong>Posts</strong> in one category. Each draft uses a different batch of products; Ollama varies titles and copy.</p>
        <p>
            <strong>Where are drafts?</strong>
            <strong>Blog Posts</strong> → filter <strong>Draft</strong>. Edit and publish when ready.
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=blog_post&post_status=draft')); ?>">Open drafts</a>
        </p>

        <table class="form-table">
            <tr>
                <th>Source: post category</th>
                <td>
                    <?php
                    wp_dropdown_categories([
                        'taxonomy' => 'category',
                        'hide_empty' => false,
                        'name' => 'pnbc_source_cat',
                        'id' => 'pnbc_source_cat',
                        'show_option_none' => '— Select —',
                        'option_none_value' => '0',
                    ]);
                    ?>
                </td>
            </tr>
            <tr>
                <th>Target: Blog Categories</th>
                <td>
                    <?php
                    wp_dropdown_categories([
                        'taxonomy' => 'blog_category',
                        'hide_empty' => false,
                        'name' => 'pnbc_target_blog_cat',
                        'id' => 'pnbc_target_blog_cat',
                        'show_option_none' => '— Select —',
                        'option_none_value' => '0',
                    ]);
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="pnbc_per_blog">Products per blog post</label></th>
                <td><input type="number" id="pnbc_per_blog" min="1" max="10" value="10"></td>
            </tr>
            <tr>
                <th><label for="pnbc_num_posts">How many drafts to create</label></th>
                <td><input type="number" id="pnbc_num_posts" min="1" max="25" value="1"></td>
            </tr>
            <tr>
                <th><label for="pnbc_lang">Language for generated text</label></th>
                <td><input type="text" id="pnbc_lang" class="regular-text" value="en" placeholder="en or tr"></td>
            </tr>
            <tr>
                <th>Tags</th>
                <td>
                    <p class="description">Post tags are chosen by the model for SEO and created or reused automatically (no manual tag IDs).</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="button" class="button button-primary button-large" id="pnbc_start">Create drafts</button>
        </p>
        <div id="pnbc_log" style="margin-top:1em;max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;font-family:monospace;font-size:12px;"></div>
    </div>
    <script>
    (function($) {
        const nonce = <?php echo wp_json_encode($nonce); ?>;
        const ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        function logLine(msg) {
            const el = document.getElementById('pnbc_log');
            el.appendChild(document.createTextNode(msg + '\n'));
            el.scrollTop = el.scrollHeight;
        }

        $('#pnbc_start').on('click', async function() {
            const btn = $(this);
            btn.prop('disabled', true);
            $('#pnbc_log').empty();

            const sourceCat = parseInt($('#pnbc_source_cat').val(), 10) || 0;
            const targetBlogCat = parseInt($('#pnbc_target_blog_cat').val(), 10) || 0;
            const perBlog = Math.min(10, Math.max(1, parseInt($('#pnbc_per_blog').val(), 10) || 10));
            const numPosts = Math.min(25, Math.max(1, parseInt($('#pnbc_num_posts').val(), 10) || 1));
            const lang = ($('#pnbc_lang').val() || 'en').trim();

            if (!sourceCat) { alert('Select a source post category.'); btn.prop('disabled', false); return; }
            if (!targetBlogCat) { alert('Select a target blog category.'); btn.prop('disabled', false); return; }

            const ajaxLong = (payload) => $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                timeout: 0,
                data: payload
            });

            logLine('Preparing batch…');
            let init;
            try {
                init = await ajaxLong({
                    action: 'pnbc_init',
                    nonce: nonce,
                    source_cat: sourceCat,
                    per_blog: perBlog,
                    num_posts: numPosts,
                    target_blog_cat: targetBlogCat,
                    lang: lang
                });
            } catch (e) {
                logLine('ERROR: network or timeout — ' + (e.statusText || e.message || String(e)));
                btn.prop('disabled', false);
                return;
            }

            if (!init.success) {
                logLine('ERROR: ' + (init.data && init.data.message ? init.data.message : 'init failed'));
                btn.prop('disabled', false);
                return;
            }

            const batchKey = init.data.batch_key;
            const total = init.data.total;
            logLine(total + ' draft(s) will be generated.');

            for (let step = 0; step < total; step++) {
                logLine('--- ' + (step + 1) + '/' + total + ' ---');
                let r;
                try {
                    r = await ajaxLong({
                        action: 'pnbc_step',
                        nonce: nonce,
                        batch_key: batchKey,
                        step: step
                    });
                } catch (e) {
                    logLine('ERROR: network or timeout — ' + (e.statusText || e.message || String(e)));
                    break;
                }
                if (!r.success) {
                    logLine('ERROR: ' + (r.data && r.data.message ? r.data.message : 'step failed'));
                    break;
                }
                const seo = (typeof r.data.seo_score === 'number' ? r.data.seo_score : '?') + '/10';
                const ok = r.data.seo_approved ? 'approved' : 'needs review';
                logLine('SEO check: ' + seo + ' — ' + ok + (r.data.seo_notes ? ' — ' + r.data.seo_notes : ''));
                if (r.data.seo_retried) {
                    logLine('(Draft was regenerated once after failed review.)');
                }
                logLine('OK: post #' + r.data.post_id + ' — ' + (r.data.edit_link || ''));
            }

            logLine('Done.');
            btn.prop('disabled', false);
        });
    })(jQuery);
    </script>
    <?php
}

add_action('wp_ajax_pnbc_init', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    check_ajax_referer('pnbc', 'nonce');

    $source_cat = (int) ($_POST['source_cat'] ?? 0);
    $per_blog = (int) ($_POST['per_blog'] ?? 10);
    $num_posts = (int) ($_POST['num_posts'] ?? 1);
    $target_blog_cat = (int) ($_POST['target_blog_cat'] ?? 0);
    $lang = sanitize_text_field((string) ($_POST['lang'] ?? 'en'));

    $per_blog = max(1, min(10, $per_blog));
    $num_posts = max(1, min(25, $num_posts));

    if ($source_cat <= 0 || $target_blog_cat <= 0) {
        wp_send_json_error(['message' => 'Select both categories.']);
    }

    $ids = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'cat' => $source_cat,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);

    $need = $num_posts * $per_blog;
    if (count($ids) < $need) {
        wp_send_json_error([
            'message' => sprintf(
                'Need at least %1$d published posts in that category (%2$d per draft). You have %3$d.',
                $need,
                $per_blog,
                count($ids)
            ),
        ]);
    }

    shuffle($ids);
    $chunks = [];
    for ($i = 0; $i < $num_posts; $i++) {
        $slice = array_slice($ids, $i * $per_blog, $per_blog);
        if (count($slice) < $per_blog) {
            break;
        }
        $chunks[] = $slice;
    }

    if ($chunks === []) {
        wp_send_json_error(['message' => 'Could not build batch.']);
    }

    $source_term = get_term($source_cat, 'category');
    $blog_term = get_term($target_blog_cat, 'blog_category');
    $source_cat_name = ($source_term && !is_wp_error($source_term)) ? (string) $source_term->name : '';
    $blog_cat_name = ($blog_term && !is_wp_error($blog_term)) ? (string) $blog_term->name : '';

    $batch_key = wp_generate_password(16, false, false);
    set_transient(
        'pnbc_batch_' . $batch_key,
        [
            'chunks' => $chunks,
            'target_blog_cat' => $target_blog_cat,
            'lang' => $lang,
            'source_cat_name' => $source_cat_name,
            'blog_cat_name' => $blog_cat_name,
            'total' => count($chunks),
        ],
        HOUR_IN_SECONDS
    );

    wp_send_json_success([
        'batch_key' => $batch_key,
        'total' => count($chunks),
    ]);
});

add_action('wp_ajax_pnbc_step', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    check_ajax_referer('pnbc', 'nonce');

    $batch_key = sanitize_text_field((string) ($_POST['batch_key'] ?? ''));
    $step = (int) ($_POST['step'] ?? 0);

    $data = get_transient('pnbc_batch_' . $batch_key);
    if (!is_array($data) || empty($data['chunks'])) {
        wp_send_json_error(['message' => 'Batch expired or invalid key. Reload the page and try again.']);
    }

    $chunks = $data['chunks'];
    if ($step < 0 || $step >= count($chunks)) {
        wp_send_json_error(['message' => 'Invalid step.']);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $post_ids = $chunks[$step];
    $sources = pnbc_fetch_source_items($post_ids);
    if (count($sources) < count($post_ids)) {
        wp_send_json_error(['message' => 'Some source posts could not be loaded.']);
    }

    $total = (int) ($data['total'] ?? count($chunks));
    $lang = (string) ($data['lang'] ?? 'en');
    $source_cat_name = (string) ($data['source_cat_name'] ?? '');
    $blog_cat_name = (string) ($data['blog_cat_name'] ?? '');

    $retry_feedback = '';
    $final_d = null;
    $final_review = null;
    $seo_retried = false;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $gen = pnbc_run_ollama_for_chunk($sources, $lang, $step + 1, $total, $retry_feedback);
        if (empty($gen['ok']) || empty($gen['data'])) {
            wp_send_json_error(['message' => $gen['error'] ?? 'Ollama error.']);
        }

        $d = $gen['data'];
        $product_titles = [];
        foreach ($d['items'] ?? [] as $it) {
            if (is_array($it) && ($it['title'] ?? '') !== '') {
                $product_titles[] = (string) $it['title'];
            }
        }

        $review_payload = [
            'title' => (string) ($d['title'] ?? ''),
            'excerpt' => (string) ($d['excerpt'] ?? ''),
            'proposed_tags' => isset($d['post_tags']) && is_array($d['post_tags']) ? $d['post_tags'] : [],
            'product_titles' => array_slice($product_titles, 0, 8),
        ];

        $final_review = pnbc_seo_validate_draft($review_payload, $source_cat_name, $blog_cat_name, $lang);
        $final_d = $d;

        if (!empty($final_review['approved']) || $attempt >= 1) {
            break;
        }

        $seo_retried = true;
        $retry_feedback = sprintf(
            'Not approved (score %d). SEO OK: %s; category fit: %s. %s',
            (int) $final_review['score'],
            !empty($final_review['seo_ok']) ? 'yes' : 'no',
            !empty($final_review['matches_blog_category']) ? 'yes' : 'no',
            (string) $final_review['notes']
        );
    }

    $post_id = pnbc_insert_blog_post(
        $final_d,
        (int) ($data['target_blog_cat'] ?? 0),
        is_array($final_review) ? $final_review : null
    );

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => $post_id->get_error_message()]);
    }

    wp_send_json_success([
        'post_id' => $post_id,
        'edit_link' => get_edit_post_link($post_id, 'raw'),
        'seo_approved' => !empty($final_review['approved']),
        'seo_score' => (int) ($final_review['score'] ?? 0),
        'seo_notes' => (string) ($final_review['notes'] ?? ''),
        'seo_retried' => $seo_retried,
    ]);
});
