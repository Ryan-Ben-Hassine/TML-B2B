<?php
// backend/api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use absolute paths for requires
if (!file_exists(__DIR__ . '/config.php')) {
    header("Content-Type: application/json");
    echo json_encode(['status' => 'error', 'message' => 'System not setup. Missing config.php in ' . __DIR__]);
    exit;
}

require __DIR__ . '/config.php';

if (!function_exists('cors_allow_origin')) {
    function cors_allow_origin() {
        $allowed = getenv('CORS_ALLOW_ORIGINS');
        if (!$allowed) {
            return '*';
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $list = array_filter(array_map('trim', explode(',', $allowed)));
        if (in_array('*', $list, true)) return '*';
        if ($origin && in_array($origin, $list, true)) {
            return $origin;
        }
        return 'null';
    }
}

$corsOrigin = cors_allow_origin();
header("Access-Control-Allow-Origin: $corsOrigin");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Access-Key, X-Admin-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header("Cache-Control: no-store");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function origin_allowed($origin) {
    $allowed = getenv('CORS_ALLOW_ORIGINS');
    if (!$allowed) return true;
    $list = array_filter(array_map('trim', explode(',', $allowed)));
    if (in_array('*', $list, true)) return true;
    return in_array($origin, $list, true);
}

$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($reqOrigin && !origin_allowed($reqOrigin)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Origin not allowed']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

function ensure_settings_columns($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN, 0);
    $needed = [
        'admin_slug' => "ALTER TABLE settings ADD COLUMN admin_slug VARCHAR(100) DEFAULT 'manage'",
        'collect_email' => "ALTER TABLE settings ADD COLUMN collect_email TINYINT(1) DEFAULT 0",
        'home_section_title' => "ALTER TABLE settings ADD COLUMN home_section_title VARCHAR(255) DEFAULT 'Nos produits'",
        'home_cta_label' => "ALTER TABLE settings ADD COLUMN home_cta_label VARCHAR(255) DEFAULT 'Découvrir la collection'",
        'home_search_placeholder' => "ALTER TABLE settings ADD COLUMN home_search_placeholder VARCHAR(255) DEFAULT 'Rechercher des produits...'",
        'home_empty_label' => "ALTER TABLE settings ADD COLUMN home_empty_label VARCHAR(255) DEFAULT 'Aucun produit trouvé.'",
        'home_all_label' => "ALTER TABLE settings ADD COLUMN home_all_label VARCHAR(50) DEFAULT 'Tous'",
        'home_sort_featured_label' => "ALTER TABLE settings ADD COLUMN home_sort_featured_label VARCHAR(100) DEFAULT 'En vedette'",
        'home_sort_low_label' => "ALTER TABLE settings ADD COLUMN home_sort_low_label VARCHAR(100) DEFAULT 'Prix : croissant'",
        'home_sort_high_label' => "ALTER TABLE settings ADD COLUMN home_sort_high_label VARCHAR(100) DEFAULT 'Prix : décroissant'",
        'home_view_details_label' => "ALTER TABLE settings ADD COLUMN home_view_details_label VARCHAR(100) DEFAULT 'Voir détails'",
        'feature1_title' => "ALTER TABLE settings ADD COLUMN feature1_title VARCHAR(255) DEFAULT 'Impression 3D experte'",
        'feature1_subtitle' => "ALTER TABLE settings ADD COLUMN feature1_subtitle VARCHAR(255) DEFAULT 'Des modèles précis avec des matériaux premium'",
        'feature2_title' => "ALTER TABLE settings ADD COLUMN feature2_title VARCHAR(255) DEFAULT 'Livraison rapide'",
        'feature2_subtitle' => "ALTER TABLE settings ADD COLUMN feature2_subtitle VARCHAR(255) DEFAULT 'Expédition fiable partout en Tunisie'",
        'feature3_title' => "ALTER TABLE settings ADD COLUMN feature3_title VARCHAR(255) DEFAULT 'Paiement sécurisé'",
        'feature3_subtitle' => "ALTER TABLE settings ADD COLUMN feature3_subtitle VARCHAR(255) DEFAULT 'Commande manuelle ou synchronisation instantanée TML'"
    ];
    foreach ($needed as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
        }
    }
}

ensure_settings_columns($pdo);

function ensure_products_columns($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0);
    $needed = [
        'tml_product_id' => "ALTER TABLE products ADD COLUMN tml_product_id INT DEFAULT 0",
        'is_tml_product' => "ALTER TABLE products ADD COLUMN is_tml_product TINYINT(1) DEFAULT 0"
    ];
    foreach ($needed as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
        }
    }

    // Backfill old TML imports (they had empty technical fields).
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('tml_product_id', $cols, true)) {
        $pdo->exec("UPDATE products SET tml_product_id = model_id, is_tml_product = 1 WHERE tml_product_id = 0 AND model_id > 0 AND (material IS NULL OR material = '') AND (quality IS NULL OR quality = '') AND (head_name IS NULL OR head_name = '')");
    }
}

ensure_products_columns($pdo);

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);

function log_debug($msg) {
    if (getenv('DEBUG_LOG') === '0') return;
    file_put_contents(__DIR__ . '/debug.log', "[" . date('H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// ABSOLUTE LOGGING
log_debug("ACTION: $action");

function tml_create_order($apiKey, $payload) {
    $ch = curl_init("https://tml.tn/api/v1/orders");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $respData = json_decode($resp, true);
    $orderId = $respData['data']['order_id'] ?? null;
    return [
        'ok' => !empty($orderId),
        'order_id' => $orderId,
        'raw' => $respData,
        'raw_text' => $resp,
        'http_code' => $httpCode,
        'error' => $err
    ];
}

function get_location_address($pdo, $cityId, $zoneId) {
    $stmt = $pdo->prepare("SELECT city_name, zone_name FROM locations WHERE city_id = ? AND zone_id = ? LIMIT 1");
    $stmt->execute([$cityId, $zoneId]);
    $row = $stmt->fetch();
    if ($row) {
        return trim($row['city_name'] . ' - ' . $row['zone_name']);
    }
    return '';
}

function get_or_create_category_id($pdo, $name) {
    if (!$name) return null;
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $ins = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
    $ins->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function ensure_admin_tables($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(128) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

ensure_admin_tables($pdo);

function env_int($name, $default) {
    $val = getenv($name);
    if ($val === false || $val === '') return $default;
    return (int)$val;
}

function client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limit($key, $limit, $windowSeconds) {
    if ($limit <= 0 || $windowSeconds <= 0) return ['ok' => true];
    $file = sys_get_temp_dir() . '/printshop_rl.json';
    $fh = fopen($file, 'c+');
    if (!$fh) return ['ok' => true];
    flock($fh, LOCK_EX);
    $json = stream_get_contents($fh);
    $data = $json ? json_decode($json, true) : [];
    if (!is_array($data)) $data = [];
    $now = time();
    $arr = $data[$key] ?? [];
    $arr = array_values(array_filter($arr, function($t) use ($now, $windowSeconds) {
        return $t > ($now - $windowSeconds);
    }));
    if (count($arr) >= $limit) {
        $retry = $windowSeconds - ($now - min($arr));
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return ['ok' => false, 'retry_after' => max(1, (int)$retry)];
    }
    $arr[] = $now;
    $data[$key] = $arr;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return ['ok' => true];
}

function enforce_https() {
    if (getenv('ENFORCE_HTTPS') !== '1') return;
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $https = $_SERVER['HTTPS'] ?? '';
    $isHttps = ($proto === 'https') || ($https && $https !== 'off');
    if (!$isHttps) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'HTTPS required']);
        exit;
    }
}

enforce_https();

function get_admin_token() {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $token = trim($m[1]);
        }
    }
    return $token;
}

function ensure_admin($pdo) {
    $token = get_admin_token();
    if (!$token) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM admin_sessions WHERE token = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        return false;
    }
    return true;
}

$adminActions = [
    'save_settings',
    'add_product',
    'update_product',
    'delete_product',
    'add_category',
    'update_category',
    'delete_category',
    'add_hero_slide',
    'delete_hero_slide',
    'get_orders',
    'update_order_status',
    'approve_order',
    'sync_locations',
    'import_tml_products',
    'get_debug_log',
    'clear_debug_log',
    'upload_image'
];

if (in_array($action, $adminActions, true)) {
    if (!ensure_admin($pdo)) {
        exit;
    }
}

switch ($action) {
    case 'get_settings':
        $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
        $settings = $stmt->fetch();
        if ($settings && (isset($_GET['admin']) && $_GET['admin'] === 'true')) {
            if (!ensure_admin($pdo)) exit;
        } else if ($settings) {
            unset($settings['tml_api_key']);
            unset($settings['mobile_access_key']);
        }
        echo json_encode(['status' => 'success', 'data' => $settings]);
        break;

    case 'save_settings':
        $s = $data['settings'];
        $adminSlug = trim($s['admin_slug'] ?? 'manage');
        $adminSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $adminSlug);
        if ($adminSlug === '') $adminSlug = 'manage';
        $stmt = $pdo->prepare("UPDATE settings SET site_name = ?, hero_title = ?, hero_subtitle = ?, primary_color = ?, tml_api_key = ?, thanks_message = ?, logo_url = ?, favicon_url = ?, currency = ?, delivery_fee = ?, mobile_access_key = ?, order_flow = ?, admin_slug = ?, collect_email = ?, home_section_title = ?, home_cta_label = ?, home_search_placeholder = ?, home_empty_label = ?, home_all_label = ?, home_sort_featured_label = ?, home_sort_low_label = ?, home_sort_high_label = ?, home_view_details_label = ?, feature1_title = ?, feature1_subtitle = ?, feature2_title = ?, feature2_subtitle = ?, feature3_title = ?, feature3_subtitle = ? WHERE id = 1");
        $stmt->execute([
            $s['site_name'] ?? '3D Print Shop', 
            $s['hero_title'] ?? '', 
            $s['hero_subtitle'] ?? '', 
            $s['primary_color'] ?? '#007BFF', 
            trim($s['tml_api_key'] ?? ''), 
            $s['thanks_message'] ?? '', 
            $s['logo_url'] ?? '', 
            $s['favicon_url'] ?? '', 
            $s['currency'] ?? 'TND', 
            $s['delivery_fee'] ?? 0.00, 
            $s['mobile_access_key'] ?? null,
            trim($s['order_flow'] ?? 'auto'),
            $adminSlug,
            !empty($s['collect_email']) ? 1 : 0,
            $s['home_section_title'] ?? 'Nos produits',
            $s['home_cta_label'] ?? 'Découvrir la collection',
            $s['home_search_placeholder'] ?? 'Rechercher des produits...',
            $s['home_empty_label'] ?? 'Aucun produit trouvé.',
            $s['home_all_label'] ?? 'Tous',
            $s['home_sort_featured_label'] ?? 'En vedette',
            $s['home_sort_low_label'] ?? 'Prix : croissant',
            $s['home_sort_high_label'] ?? 'Prix : décroissant',
            $s['home_view_details_label'] ?? 'Voir détails',
            $s['feature1_title'] ?? 'Impression 3D experte',
            $s['feature1_subtitle'] ?? 'Des modèles précis avec des matériaux premium',
            $s['feature2_title'] ?? 'Livraison rapide',
            $s['feature2_subtitle'] ?? 'Expédition fiable partout en Tunisie',
            $s['feature3_title'] ?? 'Paiement sécurisé',
            $s['feature3_subtitle'] ?? 'Commande manuelle ou synchronisation instantanée TML'
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully']);
        break;

    case 'get_products':
        $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'add_product':
        $stmt = $pdo->prepare("INSERT INTO products (title, description, image_url, price, model_id, tml_product_id, is_tml_product, material, quality, head_name, category_id, colors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($data['title'] ?? ''),
            $data['description'] ?? '',
            $data['image_url'] ?? '',
            $data['price'] ?? 0,
            (int)($data['model_id'] ?? 0),
            (int)($data['tml_product_id'] ?? 0),
            (int)($data['is_tml_product'] ?? 0),
            $data['material'] ?? 'PLA',
            $data['quality'] ?? 'Standard',
            $data['head_name'] ?? 'Revo 0.4 Basic',
            $data['category_id'] ?? null,
            $data['colors'] ?? null
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Product added']);
        break;

    case 'update_product':
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid product id']);
            break;
        }
        $stmt = $pdo->prepare("UPDATE products SET title = ?, description = ?, image_url = ?, price = ?, model_id = ?, material = ?, quality = ?, head_name = ?, category_id = ?, colors = ? WHERE id = ?");
        $stmt->execute([
            trim($data['title'] ?? ''),
            $data['description'] ?? '',
            $data['image_url'] ?? '',
            $data['price'] ?? 0,
            (int)($data['model_id'] ?? 0),
            $data['material'] ?? 'PLA',
            $data['quality'] ?? 'Standard',
            $data['head_name'] ?? 'Revo 0.4 Basic',
            $data['category_id'] ?? null,
            $data['colors'] ?? null,
            $id
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Product updated']);
        break;

    case 'delete_product':
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([(int)($data['id'] ?? 0)]);
        echo json_encode(['status' => 'success', 'message' => 'Product deleted']);
        break;

    case 'import_tml_products':
        $sStmt = $pdo->query("SELECT tml_api_key, delivery_fee FROM settings LIMIT 1");
        $settings = $sStmt->fetch();
        $apiKey = trim($settings['tml_api_key'] ?? '');
        $deliveryFee = (float)($settings['delivery_fee'] ?? 0);
        if (!$apiKey) {
            echo json_encode(['status' => 'error', 'message' => 'Missing TML API key']);
            break;
        }

        $ch = curl_init("https://tml.tn/api/v1/products");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);
        if (!isset($json['data']) || !is_array($json['data'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid TML response', 'http_code' => $httpCode, 'raw' => $json]);
            break;
        }

        $inserted = 0;
        $updated = 0;
        foreach ($json['data'] as $p) {
            $tmlId = (int)($p['id'] ?? 0);
            if ($tmlId <= 0) continue;
            $title = $p['name_fr'] ?? $p['name'] ?? 'Produit TML';
            $price = $p['price'] ?? 0;
            $image = $p['image'] ?? '';
            if (!empty($image) && strpos($image, 'http') !== 0) {
                $image = 'https://tml.tn' . $image;
            }
            $catName = $p['category_name_fr'] ?? $p['category_name'] ?? null;
            $categoryId = get_or_create_category_id($pdo, $catName);

            $check = $pdo->prepare("SELECT id, material, quality, head_name FROM products WHERE tml_product_id = ? LIMIT 1");
            $check->execute([$tmlId]);
            $row = $check->fetch();

            if (!$row) {
                $legacy = $pdo->prepare("SELECT id, material, quality, head_name FROM products WHERE model_id = ? LIMIT 1");
                $legacy->execute([$tmlId]);
                $legacyRow = $legacy->fetch();
                if ($legacyRow) {
                    $isLikelyTml = ($legacyRow['material'] === '' || $legacyRow['material'] === null)
                        && ($legacyRow['quality'] === '' || $legacyRow['quality'] === null)
                        && ($legacyRow['head_name'] === '' || $legacyRow['head_name'] === null);
                    if ($isLikelyTml) {
                        $row = $legacyRow;
                    }
                }
            }

            if ($row) {
                $u = $pdo->prepare("UPDATE products SET title = ?, image_url = ?, price = ?, category_id = ?, tml_product_id = ?, is_tml_product = 1 WHERE id = ?");
                $u->execute([$title, $image, $price, $categoryId, $tmlId, $row['id']]);
                $updated++;
            } else {
                $i = $pdo->prepare("INSERT INTO products (title, description, image_url, price, model_id, tml_product_id, is_tml_product, material, quality, head_name, category_id, colors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $i->execute([
                    $title,
                    '',
                    $image,
                    $price,
                    0,
                    $tmlId,
                    1,
                    '',
                    '',
                    '',
                    $categoryId,
                    null
                ]);
                $inserted++;
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Import terminé', 'inserted' => $inserted, 'updated' => $updated]);
        break;

    case 'get_categories':
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'add_category':
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Category name required']);
            break;
        }
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        echo json_encode(['status' => 'success', 'message' => 'Category added']);
        break;

    case 'update_category':
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        if ($id <= 0 || $name === '') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid category data']);
            break;
        }
        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        echo json_encode(['status' => 'success', 'message' => 'Category updated']);
        break;

    case 'delete_category':
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([(int)($data['id'] ?? 0)]);
        echo json_encode(['status' => 'success', 'message' => 'Category deleted']);
        break;

    case 'get_hero_slides':
        $stmt = $pdo->query("SELECT * FROM hero_slides ORDER BY id DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'add_hero_slide':
        $stmt = $pdo->prepare("INSERT INTO hero_slides (image_url, title, subtitle, target_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['image_url'] ?? '',
            $data['title'] ?? '',
            $data['subtitle'] ?? '',
            $data['target_url'] ?? ''
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Hero slide added']);
        break;

    case 'delete_hero_slide':
        $stmt = $pdo->prepare("DELETE FROM hero_slides WHERE id = ?");
        $stmt->execute([(int)($data['id'] ?? 0)]);
        echo json_encode(['status' => 'success', 'message' => 'Hero slide deleted']);
        break;

    case 'checkout':
        $ip = client_ip();
        $rl = rate_limit(
            "checkout:$ip",
            env_int('RATE_LIMIT_CHECKOUT', 10),
            env_int('RATE_LIMIT_CHECKOUT_WINDOW', 300)
        );
        if (!$rl['ok']) {
            header('Retry-After: ' . $rl['retry_after']);
            echo json_encode(['status' => 'error', 'message' => 'Too many requests. Try later.']);
            break;
        }
        // Get Settings
        $setStmt = $pdo->query("SELECT tml_api_key, order_flow, delivery_fee FROM settings LIMIT 1");
        $settings = $setStmt->fetch();
        $apiKey = trim($settings['tml_api_key'] ?? '');
        $flow = trim($settings['order_flow'] ?? 'auto');
        $deliveryFee = (float)($settings['delivery_fee'] ?? 0);

        $productId = (int)($data['product_id'] ?? 0);
        $cityId = (int)($data['city_id'] ?? 0);
        $zoneId = (int)($data['zone_id'] ?? 0);

        $address = trim($data['address'] ?? '');
        if ($address === '') {
            $address = get_location_address($pdo, $cityId, $zoneId);
        }

        // Save local order
        $stmt = $pdo->prepare("INSERT INTO orders (product_id, name, email, phone, address, city_id, zone_id, selected_color, total_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $productId, 
            $data['name'] ?? 'Unknown', 
            $data['email'] ?? '', 
            $data['phone'] ?? '', 
            $address, 
            $cityId, 
            $zoneId, 
            $data['selected_color'] ?? null, 
            $data['total_price'] ?? 0,
            'Pending'
        ]);
        $orderId = (int)$pdo->lastInsertId();
        log_debug("ORDER $orderId inserted locally (flow=$flow)");

        if ($flow === 'auto' && $apiKey) {
            $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $pStmt->execute([$productId]);
            $prod = $pStmt->fetch();

            if ($prod) {
                $tmlProductId = (int)($prod['tml_product_id'] ?? 0);
                if ($tmlProductId <= 0 && !empty($prod['is_tml_product'])) {
                    $tmlProductId = (int)($prod['model_id'] ?? 0);
                }
                $tmlPayload = [
                    'material' => $prod['material'] ?: 'PLA',
                    'quality' => $prod['quality'] ?: 'Standard',
                    'head_name' => $prod['head_name'] ?: 'Revo 0.4 Basic',
                    'city_id' => $cityId,
                    'zone_id' => $zoneId,
                    'address' => $address,
                    'phone' => $data['phone'] ?? '',
                    'name' => $data['name'] ?? 'Customer',
                    'email' => $data['email'] ?? '',
                    'delivery_fee' => $deliveryFee
                ];
                if ($tmlProductId > 0) {
                    $tmlPayload['product_id'] = $tmlProductId;
                } else {
                    $tmlPayload['model_id'] = (int)$prod['model_id'];
                }

                log_debug("ORDER $orderId TML PAYLOAD: " . json_encode($tmlPayload));
                $tmlResp = tml_create_order($apiKey, $tmlPayload);
                
                // LOG RAW TML RESPONSE
                log_debug("ORDER $orderId TML RESP: " . ($tmlResp['raw_text'] ?? ''));

                if ($tmlResp['ok']) {
                    $uStmt = $pdo->prepare("UPDATE orders SET tml_order_id = ?, status = 'Processing' WHERE id = ?");
                    $uStmt->execute([$tmlResp['order_id'], $orderId]);
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Synced with TML',
                        'order_id' => $orderId,
                        'tml_order_id' => $tmlResp['order_id']
                    ]);
                    exit;
                } else {
                    echo json_encode([
                        'status' => 'success',
                        'warning' => 'TML sync failed',
                        'tml_error' => $tmlResp['raw'],
                        'http_code' => $tmlResp['http_code'],
                        'order_id' => $orderId
                    ]);
                    exit;
                }
            }
        }
        echo json_encode(['status' => 'success', 'message' => 'Order placed locally', 'order_id' => $orderId]);
        break;

    case 'approve_order':
        $stmt = $pdo->prepare("SELECT orders.*, products.model_id, products.tml_product_id, products.is_tml_product, products.material, products.quality, products.head_name FROM orders JOIN products ON orders.product_id = products.id WHERE orders.id = ? LIMIT 1");
        $stmt->execute([$data['id']]);
        $order = $stmt->fetch();
        $sStmt = $pdo->query("SELECT tml_api_key FROM settings LIMIT 1");
        $apiKey = trim($sStmt->fetchColumn());

        if (!$order) {
            echo json_encode(['status' => 'error', 'message' => 'Order not found']);
            break;
        }

        if (!empty($order['tml_order_id'])) {
            echo json_encode(['status' => 'success', 'message' => 'Order already synced', 'tml_order_id' => $order['tml_order_id']]);
            break;
        }

        if ($apiKey) {
            $tmlProductId = (int)($order['tml_product_id'] ?? 0);
            if ($tmlProductId <= 0 && !empty($order['is_tml_product'])) {
                $tmlProductId = (int)($order['model_id'] ?? 0);
            }
            $tmlPayload = [
                'material' => $order['material'] ?: 'PLA',
                'quality' => $order['quality'] ?: 'Standard',
                'head_name' => $order['head_name'] ?: 'Revo 0.4 Basic',
                'city_id' => (int)$order['city_id'],
                'zone_id' => (int)$order['zone_id'],
                'address' => $order['address'],
                'phone' => $order['phone'],
                'name' => $order['name'],
                'email' => $order['email'],
                'delivery_fee' => $deliveryFee
            ];
            if ($tmlProductId > 0) {
                $tmlPayload['product_id'] = $tmlProductId;
            } else {
                $tmlPayload['model_id'] = (int)$order['model_id'];
            }
            log_debug("APPROVE {$data['id']} TML PAYLOAD: " . json_encode($tmlPayload));
            $tmlResp = tml_create_order($apiKey, $tmlPayload);
            log_debug("APPROVE {$data['id']} TML RESP: " . ($tmlResp['raw_text'] ?? ''));
            if ($tmlResp['ok']) {
                $uStmt = $pdo->prepare("UPDATE orders SET tml_order_id = ?, status = 'Processing' WHERE id = ?");
                $uStmt->execute([$tmlResp['order_id'], $data['id']]);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Approved and synced',
                    'order_id' => (int)$data['id'],
                    'tml_order_id' => $tmlResp['order_id']
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Sync failed',
                    'order_id' => (int)$data['id'],
                    'raw' => $tmlResp['raw'],
                    'http_code' => $tmlResp['http_code']
                ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Missing TML API key in settings']);
        }
        break;

    case 'get_locations':
        $stmt = $pdo->query("SELECT * FROM locations ORDER BY city_name, zone_name ASC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'sync_locations':
        $sStmt = $pdo->query("SELECT tml_api_key FROM settings LIMIT 1");
        $apiKey = trim($sStmt->fetchColumn());
        if (!$apiKey) {
            echo json_encode(['status' => 'error', 'message' => 'Missing TML API key']);
            break;
        }
        $ch = curl_init("https://tml.tn/api/v1/locations");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);
        if (!isset($json['locations'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid TML response', 'http_code' => $httpCode, 'raw' => $json]);
            break;
        }

        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM locations");
        $insert = $pdo->prepare("INSERT INTO locations (city_id, city_name, zone_id, zone_name) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($json['locations'] as $loc) {
            if (isset($loc['districts'])) {
                foreach ($loc['districts'] as $zone) {
                    $insert->execute([$loc['id'], $loc['name'], $zone['id'], $zone['name']]);
                    $count++;
                }
            }
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Locations synced', 'count' => $count]);
        break;

    case 'login':
        $ip = client_ip();
        $rl = rate_limit(
            "login:$ip",
            env_int('RATE_LIMIT_LOGIN', 5),
            env_int('RATE_LIMIT_LOGIN_WINDOW', 600)
        );
        if (!$rl['ok']) {
            header('Retry-After: ' . $rl['retry_after']);
            echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Try later.']);
            break;
        }
        $user = $data['username'] ?? '';
        $pass = $data['password'] ?? '';
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $row = $stmt->fetch();
        if ($row && password_verify($pass, $row['password'])) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("DELETE FROM admin_sessions WHERE expires_at <= NOW()")->execute();
            $ttl = env_int('ADMIN_TOKEN_TTL_HOURS', 12);
            $ins = $pdo->prepare("INSERT INTO admin_sessions (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $ttl HOUR))");
            $ins->execute([(int)$row['id'], $token]);
            echo json_encode(['status' => 'success', 'token' => $token, 'expires_in_hours' => $ttl]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
        }
        break;

    case 'get_orders':
        $stmt = $pdo->query("SELECT orders.*, products.title as product_title FROM orders LEFT JOIN products ON orders.product_id = products.id ORDER BY orders.id DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'get_debug_log':
        $logFile = __DIR__ . '/debug.log';
        if (!file_exists($logFile)) {
            echo json_encode(['status' => 'success', 'data' => '']);
            break;
        }
        $log = file_get_contents($logFile);
        $max = 20000;
        if (strlen($log) > $max) {
            $log = substr($log, -$max);
        }
        echo json_encode(['status' => 'success', 'data' => $log]);
        break;

    case 'clear_debug_log':
        file_put_contents(__DIR__ . '/debug.log', '');
        echo json_encode(['status' => 'success', 'message' => 'Debug log cleared']);
        break;

    case 'update_order_status':
        $id = (int)($data['id'] ?? 0);
        $status = $data['status'] ?? 'Pending';
        $allowed = ['Pending', 'Processing', 'Completed', 'Cancelled'];
        if (!in_array($status, $allowed, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
            break;
        }
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo json_encode(['status' => 'success', 'message' => 'Order updated']);
        break;

    case 'upload_image':
        $ip = client_ip();
        $rl = rate_limit(
            "upload:$ip",
            env_int('RATE_LIMIT_UPLOAD', 20),
            env_int('RATE_LIMIT_UPLOAD_WINDOW', 600)
        );
        if (!$rl['ok']) {
            header('Retry-After: ' . $rl['retry_after']);
            echo json_encode(['status' => 'error', 'message' => 'Too many uploads. Try later.']);
            break;
        }
        if (!isset($_FILES['image'])) {
            echo json_encode(['status' => 'error', 'message' => 'No image uploaded']);
            break;
        }
        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
            break;
        }
        $safeName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            echo json_encode(['status' => 'error', 'message' => 'Upload failed']);
            break;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $url = $scheme . '://' . $host . $basePath . '/uploads/' . $safeName;
        echo json_encode(['status' => 'success', 'url' => $url]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action: ' . $action]);
}
