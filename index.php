<?php
/**
 * PrintOnNow blog kürasyon aracı — sadece yerel kullanım.
 * Çalıştırma: php -S 127.0.0.1:8790 index.php
 * Tarayıcı: http://127.0.0.1:8790/  (config.php + Ollama gerekli)
 */
declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/config.php';

function cfg_defaults(array $cfg): array {
    $d = $cfg['defaults'] ?? [];
    if (!is_array($d)) {
        $d = [];
    }
    $per = (int) ($d['per_page'] ?? 10);
    $per = min(10, max(1, $per));
    return [
        'wp_post_type' => preg_match('/^[a-z0-9_-]+$/i', (string) ($d['wp_post_type'] ?? ''))
            ? (string) $d['wp_post_type']
            : 'post',
        'wp_category_id' => max(0, (int) ($d['wp_category_id'] ?? 0)),
        'per_page' => $per,
        'blog_category_ids' => (string) ($d['blog_category_ids'] ?? ''),
        'post_tag_ids' => (string) ($d['post_tag_ids'] ?? ''),
        'language' => (string) ($d['language'] ?? 'tr'),
    ];
}

/** Tekil blog şablonu: _first_desc, the_content, _post_images, sidebar _blog_tips + _final_desc */
function sanitize_blog_html(string $html): string {
    $allowed = '<p><br><strong><b><em><i><a><span><ul><ol><li><h2><h3><h4>';
    return strip_tags($html, $allowed);
}

/**
 * Şablondaki grid için: post_link ve image_url her zaman dolu kalsın (kaynakla hizala).
 *
 * @param list<array{post_link:string,title:string,desc:string,image_url:string}> $sources
 * @param list<mixed> $aiItems
 * @return list<array{post_link:string,title:string,desc:string,image_url:string}>
 */
function merge_items_with_sources(array $sources, array $aiItems): array {
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

function load_config(): array {
    if (!is_readable(CONFIG_FILE)) {
        http_response_code(500);
        exit('config.php bulunamadı. config.example.php dosyasını config.php olarak kopyalayın.');
    }
    /** @var array $cfg */
    $cfg = require CONFIG_FILE;
    if (empty($cfg['admin_password_hash'])) {
        http_response_code(500);
        exit('config.php içinde admin_password_hash ayarlayın.');
    }
    return $cfg;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function check_allowed_ip(array $cfg): void {
    $allowed = $cfg['allowed_ips'] ?? [];
    if ($allowed === [] || $allowed === null) {
        return;
    }
    $ip = client_ip();
    if (!in_array($ip, $allowed, true)) {
        http_response_code(403);
        exit('Bu IP adresine izin yok.');
    }
}

function require_basic_auth(array $cfg): void {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === '' || !password_verify($pass, $cfg['admin_password_hash']) || $user !== $cfg['admin_user']) {
        header('WWW-Authenticate: Basic realm="PrintOnNow Blog Curator"');
        http_response_code(401);
        exit('Giriş gerekli.');
    }
}

function http_json(string $method, string $url, ?array $headers = null, ?string $body = null): array {
    $ch = curl_init($url);
    $h = $headers ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => null];
    }
    $decoded = json_decode($raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $err, 'data' => $decoded, 'raw' => $raw];
}

function wp_fetch_posts(array $cfg, string $postType, int $categoryId, int $perPage): array {
    $base = rtrim($cfg['wp_site_url'], '/');
    $url = $base . '/wp-json/wp/v2/' . rawurlencode($postType)
        . '?per_page=' . $perPage
        . '&orderby=date&order=desc'
        . '&_embed=1';
    if ($postType === 'post' && $categoryId > 0) {
        $url .= '&categories=' . $categoryId;
    }
    $r = http_json('GET', $url, ['Accept: application/json']);
    if (!$r['ok'] || !is_array($r['data'])) {
        return ['error' => 'WP REST hatası: HTTP ' . $r['code'] . ' — ' . substr((string) ($r['raw'] ?? ''), 0, 500)];
    }
    $items = [];
    foreach ($r['data'] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $link = $p['link'] ?? '';
        $title = isset($p['title']['rendered']) ? wp_strip_html((string) $p['title']['rendered']) : '';
        $excerpt = isset($p['excerpt']['rendered']) ? wp_strip_html((string) $p['excerpt']['rendered']) : '';
        $img = '';
        if (!empty($p['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $img = (string) $p['_embedded']['wp:featuredmedia'][0]['source_url'];
        }
        if ($img === '' && !empty($p['content']['rendered'])) {
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $p['content']['rendered'], $m)) {
                $img = $m[1];
            }
        }
        $items[] = [
            'post_link' => $link,
            'title' => $title,
            'desc' => $excerpt,
            'image_url' => $img,
        ];
    }
    return ['items' => $items];
}

function wp_strip_html(string $s): string {
    return html_entity_decode(trim(strip_tags($s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * @return list<string>
 */
function ollama_list_models(array $cfg): array {
    $url = rtrim($cfg['ollama_base'], '/') . '/api/tags';
    $r = http_json('GET', $url, ['Accept: application/json']);
    if (!$r['ok'] || !is_array($r['data']) || empty($r['data']['models']) || !is_array($r['data']['models'])) {
        return [];
    }
    $names = [];
    foreach ($r['data']['models'] as $row) {
        if (!empty($row['name']) && is_string($row['name'])) {
            $names[] = $row['name'];
        }
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function ollama_strip_thinking_wrappers(string $text): string {
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

function ollama_chat(array $cfg, string $system, string $user, ?string $modelOverride = null): string {
    $model = ($modelOverride !== null && $modelOverride !== '') ? $modelOverride : (string) ($cfg['ollama_model'] ?? 'llama3.2');
    $url = rtrim($cfg['ollama_base'], '/') . '/api/chat';
    $payload = json_encode([
        'model' => $model,
        'stream' => false,
        'format' => 'json',
        'options' => [
            'num_predict' => 8192,
        ],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ], JSON_UNESCAPED_UNICODE);
    $r = http_json('POST', $url, ['Content-Type: application/json'], $payload);
    if (!$r['ok'] || !is_array($r['data'])) {
        return '';
    }
    return (string) ($r['data']['message']['content'] ?? '');
}

function extract_json_object(string $text): ?array {
    $text = ollama_strip_thinking_wrappers(trim($text));
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

function wp_create_blog_post(array $cfg, array $payload): array {
    $user = $cfg['wp_username'] ?? '';
    $pass = $cfg['wp_app_password'] ?? '';
    if ($user === '' || $pass === '') {
        return ['ok' => false, 'message' => 'WordPress kullanıcı / uygulama şifresi config.php içinde boş.'];
    }
    $base = rtrim($cfg['wp_site_url'], '/');
    $url = $base . '/wp-json/wp/v2/blog_post';
    $auth = base64_encode($user . ':' . $pass);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $r = http_json('POST', $url, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth,
    ], $body);
    if ($r['ok'] && is_array($r['data']) && !empty($r['data']['id'])) {
        return ['ok' => true, 'id' => (int) $r['data']['id'], 'link' => (string) ($r['data']['link'] ?? '')];
    }
    $msg = is_array($r['data']) ? json_encode($r['data'], JSON_UNESCAPED_UNICODE) : (string) ($r['raw'] ?? '');
    return ['ok' => false, 'message' => 'HTTP ' . $r['code'] . ' — ' . $msg];
}

// --- Router (CLI server) ---
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && $path !== '/index.php' && is_file(__DIR__ . $path)) {
        return false;
    }
}

$cfg = load_config();
check_allowed_ip($cfg);
require_basic_auth($cfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: text/html; charset=utf-8');
    $postType = preg_match('/^[a-z0-9_-]+$/i', (string) ($_POST['wp_post_type'] ?? '')) ? (string) $_POST['wp_post_type'] : 'post';
    $categoryId = max(0, (int) ($_POST['wp_category_id'] ?? 0));
    $perPage = min(10, max(1, (int) ($_POST['per_page'] ?? 10)));
    $blogCatTerms = trim((string) ($_POST['blog_category_ids'] ?? ''));
    $postTagTerms = trim((string) ($_POST['post_tag_ids'] ?? ''));
    $lang = (string) ($_POST['language'] ?? 'tr');
    $ollamaModel = trim((string) ($_POST['ollama_model'] ?? ''));
    if ($ollamaModel === '') {
        $ollamaModel = (string) ($cfg['ollama_model'] ?? 'llama3.2');
    }

    $fetch = wp_fetch_posts($cfg, $postType, $categoryId, $perPage);
    if (!empty($fetch['error'])) {
        echo '<!DOCTYPE html><meta charset="utf-8"><pre>' . htmlspecialchars($fetch['error'], ENT_QUOTES, 'UTF-8') . '</pre><p><a href="/">Geri</a></p>';
        exit;
    }
    $sourceItems = $fetch['items'];
    if ($sourceItems === []) {
        echo '<!DOCTYPE html><meta charset="utf-8"><p>Seçilen kritere uygun yazı bulunamadı.</p><p><a href="/">Geri</a></p>';
        exit;
    }

    $system = <<<SYS
Sen PrintOnNow sitesi için blog yazısı üreten bir editörsün. Çıktı, WordPress’teki TEKİL BLOG ŞABLONU ile uyumlu olmalı.

Şablon sırası (buna göre yaz):
1) Üstte büyük görsel: featured_image_url (meta _featured_image_url)
2) Giriş metni: first_desc (meta _first_desc) — şablonda wpautop ile basılıyor; 1–3 kısa paragraf, sadece <p>, <strong>, <em>
3) Ana gövde ortası: content — WordPress editör (the_content); first_desc ile tekrar etme; 1–2 cümle köprü metin, örn. aşağıdaki tasarımları sırayla inceleyin
4) Numaralı görsel grid: items[] → meta _post_images. Her kutu: numara + başlık (title) + kısa açıklama (desc) + “View” linki (post_link) + görsel (image_url). post_link ve image_url değerlerini kaynak JSON’dakiyle BİREBİR aynı tut (kopyala-yapıştır). Sadece title ve desc’yi sen yaz.
5) Sağ sütun: tips[] → meta _blog_tips (Useful Tips listesi), en fazla 5 madde, active: true
6) Kapanış: final_desc (meta _final_desc), wpautop; 1–2 paragraf <p>

API json modunda: tek parça geçerli JSON nesnesi; tüm stringlerde çift tırnak ve kaçış kurallarına uy.
Şema:
{
  "title": "H1 başlık",
  "excerpt": "kısa özet",
  "first_desc": "<p>...</p>",
  "content": "<p>...</p>",
  "items": [ { "post_link": "...", "title": "...", "desc": "...", "image_url": "..." } ],
  "tips": [ { "active": true, "text": "..." } ],
  "final_desc": "<p>...</p>",
  "featured_image_url": "URL"
}
items içinde post_link ve image_url kaynak JSON ile aynı olmalı.
Dil: {$lang}. SEO için doğal anahtar kelimeler. Markdown veya düşünme etiketi yok.
SYS;

    $userPayload = "Kaynak öğeler (JSON):\n" . json_encode($sourceItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $rawAi = ollama_chat($cfg, $system, $userPayload, $ollamaModel);
    $parsed = extract_json_object($rawAi);

    if ($parsed === null) {
        echo '<!DOCTYPE html><meta charset="utf-8"><h1>Model çıktısı JSON değil</h1><pre>' . htmlspecialchars($rawAi, ENT_QUOTES, 'UTF-8') . '</pre><p><a href="/">Geri</a></p>';
        exit;
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

    $itemsOut = merge_items_with_sources($sourceItems, $aiItems);

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

    $title = (string) ($parsed['title'] ?? 'Kürasyon');
    $excerpt = (string) ($parsed['excerpt'] ?? '');
    $firstDesc = sanitize_blog_html((string) ($parsed['first_desc'] ?? ''));
    $finalDesc = sanitize_blog_html((string) ($parsed['final_desc'] ?? ''));
    $contentFromAi = trim((string) ($parsed['content'] ?? ''));
    $contentHtml = $contentFromAi !== ''
        ? sanitize_blog_html($contentFromAi)
        : '<p>Aşağıda seçilen tasarımları sırayla inceleyebilirsiniz.</p>';

    $feat = trim((string) ($parsed['featured_image_url'] ?? ''));
    if ($feat === '' && $itemsOut !== []) {
        $feat = $itemsOut[0]['image_url'] ?? '';
    }

    if (trim(strip_tags($finalDesc)) === '') {
        $finalDesc = (stripos($lang, 'en') === 0)
            ? '<p>Explore more designs in a similar style below.</p>'
            : '<p>Aşağıda benzer temada ürünlere göz atabilirsiniz.</p>';
    }

    $meta = [
        '_first_desc' => $firstDesc,
        '_post_images' => $itemsOut,
        '_blog_tips' => $tipsOut,
        '_final_desc' => $finalDesc,
        '_featured_image_url' => $feat,
    ];

    $restBody = [
        'title' => $title,
        'status' => 'draft',
        'excerpt' => $excerpt,
        'content' => $contentHtml,
        'meta' => $meta,
    ];

    if ($blogCatTerms !== '') {
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $blogCatTerms)));
        if ($ids !== []) {
            $restBody['blog_category'] = $ids;
        }
    }

    if ($postTagTerms !== '') {
        $tagIds = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $postTagTerms))));
        if ($tagIds !== []) {
            $restBody['tags'] = $tagIds;
        }
    }

    $create = wp_create_blog_post($cfg, $restBody);

    $exportPayload = [
        'title' => $title,
        'excerpt' => $excerpt,
        'content' => $contentHtml,
        'meta' => $meta,
    ];
    if (!empty($restBody['blog_category'])) {
        $exportPayload['blog_category'] = $restBody['blog_category'];
    }
    if (!empty($restBody['tags'])) {
        $exportPayload['tags'] = $restBody['tags'];
    }
    $exportJson = json_encode($exportPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Sonuç</title></head><body>';
    echo '<h1>Oluşturuldu</h1>';
    if ($create['ok']) {
        echo '<p>Taslak blog_post ID: <strong>' . (int) $create['id'] . '</strong></p>';
        if (!empty($create['link'])) {
            echo '<p><a href="' . htmlspecialchars($create['link'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Düzenle / önizle</a></p>';
        }
    } else {
        echo '<p><strong>WordPress’e gönderilemedi:</strong> ' . htmlspecialchars($create['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p>MU eklentiyi yüklediğinizden ve uygulama şifresinin <code>blog_post</code> oluşturma yetkisi olduğundan emin olun. Aşağıdaki JSON’u manuel yapıştırmak için yedek olarak kullanın.</p>';
    }
    echo '<h2>Dışa aktarım (JSON)</h2><textarea style="width:100%;height:320px;font-family:monospace">' . htmlspecialchars($exportJson, ENT_QUOTES, 'UTF-8') . '</textarea>';
    echo '<p><a href="/">Yeni çalıştır</a></p></body></html>';
    exit;
}

// GET form
header('Content-Type: text/html; charset=utf-8');
$currentModel = (string) ($cfg['ollama_model'] ?? 'llama3.2');
$ollamaModels = ollama_list_models($cfg);
if ($currentModel !== '' && !in_array($currentModel, $ollamaModels, true)) {
    array_unshift($ollamaModels, $currentModel);
}
$model = htmlspecialchars($currentModel, ENT_QUOTES, 'UTF-8');
$site = htmlspecialchars((string) ($cfg['wp_site_url'] ?? ''), ENT_QUOTES, 'UTF-8');
$def = cfg_defaults($cfg);
$dPostType = htmlspecialchars($def['wp_post_type'], ENT_QUOTES, 'UTF-8');
$dCatId = (string) $def['wp_category_id'];
$dPer = (string) $def['per_page'];
$dBlogCats = htmlspecialchars($def['blog_category_ids'], ENT_QUOTES, 'UTF-8');
$dPostTags = htmlspecialchars($def['post_tag_ids'], ENT_QUOTES, 'UTF-8');
$dLang = htmlspecialchars($def['language'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PrintOnNow Blog Kürasyon</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input, select, button { width: 100%; box-sizing: border-box; padding: 0.5rem; margin-top: 0.25rem; }
        button { margin-top: 1.25rem; cursor: pointer; background: #1d4ed8; color: #fff; border: 0; border-radius: 6px; }
        .hint { font-size: 0.85rem; color: #444; margin-top: 0.2rem; }
        code { background: #f3f4f6; padding: 0.1rem 0.3rem; }
    </style>
</head>
<body>
    <h1>Blog kürasyon (Ollama)</h1>
    <p>Site: <code><?php echo $site; ?></code></p>
    <form method="post" action="">
        <input type="hidden" name="action" value="generate">
        <label>Ollama modeli</label>
        <?php if ($ollamaModels !== []) : ?>
            <select id="ollama_model_select" style="width:100%;box-sizing:border-box;padding:0.5rem;margin-top:0.25rem;">
                <option value="">— Listeden seç —</option>
                <?php foreach ($ollamaModels as $mname) : ?>
                    <option value="<?php echo htmlspecialchars($mname, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $mname === $currentModel ? ' selected' : ''; ?>><?php echo htmlspecialchars($mname, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Liste <code><?php echo htmlspecialchars(rtrim((string) ($cfg['ollama_base'] ?? ''), '/'), ENT_QUOTES, 'UTF-8'); ?>/api/tags</code> adresinden yüklenir.</p>
        <?php else : ?>
            <p class="hint">Model listesi alınamadı (Ollama çalışmıyor veya <code>config.php</code> → <code>ollama_base</code> yanlış). Aşağıya <code>ollama list</code> ile tam adı yazın.</p>
        <?php endif; ?>
        <input type="text" name="ollama_model" id="ollama_model" value="<?php echo $model; ?>" required autocomplete="off" style="width:100%;box-sizing:border-box;padding:0.5rem;margin-top:0.25rem;">
        <p class="hint">Dropdown seçince metin kutusu dolar; gerekirse elle düzenlersin.</p>
        <label>Kaynak: WordPress yazı tipi (REST)</label>
        <input type="text" name="wp_post_type" value="<?php echo $dPostType; ?>" pattern="[a-zA-Z0-9_-]+" required>
        <p class="hint">Ürünleriniz başka CPT ise slug’u yazın (ör. <code>product</code>). Varsayılan: <code>config.php</code> → <code>defaults.wp_post_type</code>.</p>
        <label>Kaynak: WordPress kategori ID (<code>post</code> için)</label>
        <input type="number" name="wp_category_id" value="<?php echo htmlspecialchars($dCatId, ENT_QUOTES, 'UTF-8'); ?>" min="0">
        <p class="hint">Hangi kategorideki yazılar çekilecek. Yazılar → Kategoriler → düzenle (URL’de <code>tag_ID</code>). 0 = kategori filtresi yok.</p>
        <label>Kaç yazı birleştirilecek</label>
        <input type="number" name="per_page" value="<?php echo htmlspecialchars($dPer, ENT_QUOTES, 'UTF-8'); ?>" min="1" max="10">
        <label>Hedef: Blog Categories terim ID’leri (<code>blog_category</code>)</label>
        <input type="text" name="blog_category_ids" value="<?php echo $dBlogCats; ?>" placeholder="örn: 3 veya 3,7">
        <p class="hint">Şablondaki “Blog Category” breadcrumb. <code>defaults.blog_category_ids</code>.</p>
        <label>Hedef: Etiket ID’leri (<code>post_tag</code>)</label>
        <input type="text" name="post_tag_ids" value="<?php echo $dPostTags; ?>" placeholder="örn: 12,15">
        <p class="hint">Şablondaki <code>get_the_tags()</code> (en fazla 3 gösteriyorsan ilk 3 ID yeter). Boş bırakılabilir.</p>
        <label>Dil / üslup</label>
        <input type="text" name="language" value="<?php echo $dLang; ?>">
        <button type="submit">Çek → Ollama ile üret → Taslak oluştur</button>
    </form>
    <p class="hint">Bu sayfa yalnızca sizin bilgisayarınızda <code>127.0.0.1</code> üzerinde çalışmalı; <code>config.php</code> içinde şifre ve IP kısıtı kullanın.</p>
    <script>
    (function () {
        var s = document.getElementById('ollama_model_select');
        var i = document.getElementById('ollama_model');
        if (s && i) s.addEventListener('change', function () { if (this.value) i.value = this.value; });
    })();
    </script>
</body>
</html>
