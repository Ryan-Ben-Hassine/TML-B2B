<?php
// backend/setup.php
// Avoid function redeclare when setup.php is routed via router.php (which also declares cors_allow_origin).
if (!function_exists('cors_allow_origin_setup')) {
    function cors_allow_origin_setup() {
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
$cors_fn = function_exists('cors_allow_origin') ? 'cors_allow_origin' : 'cors_allow_origin_setup';

header("Access-Control-Allow-Origin: " . $cors_fn());
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (file_exists('config.php')) {
    echo json_encode(['status' => 'error', 'message' => 'Configuration already exists. Delete config.php to reinstall']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'No data provided']);
    exit;
}

$db_host = $data['db_host'] ?? '127.0.0.1';
// If the user kept the default "db" host, only remap it to localhost when it
// does not resolve (i.e., not running inside docker-compose).
if (trim($db_host) === 'db') {
    $resolved = gethostbyname('db');
    if ($resolved === 'db') {
        $db_host = '127.0.0.1';
    }
}
$db_name = $data['db_name'] ?? 'printshop';
$db_user = $data['db_user'] ?? 'root';
$db_pass = $data['db_pass'] ?? '';

$admin_user = $data['admin_user'] ?? 'admin';
$admin_pass = $data['admin_pass'] ?? 'password';
$api_key = $data['api_key'] ?? '';
$admin_slug = $data['admin_slug'] ?? 'manage';
$admin_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $admin_slug);
if (empty($admin_slug)) $admin_slug = 'manage';
$collect_email = !empty($data['collect_email']) ? 1 : 0;

try {
    // Helper to open a PDO connection to the host (no db selected yet)
    $connect = function ($user, $pass) use ($db_host) {
        $attempts = 8;
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $pdo = new PDO("mysql:host=$db_host", $user, $pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (PDOException $e) {
                $last = $e;
                $msg = $e->getMessage();
                $code = (string)$e->getCode();
                $retryable = ($code === '2002') || (stripos($msg, 'Connection refused') !== false);
                if ($retryable) {
                    sleep(1);
                    continue;
                }
                throw $e;
            }
        }
        throw $last ?: new PDOException('Database connection failed.');
    };

    // 1. Try with the provided credentials first
    try {
        $pdo = $connect($db_user, $db_pass);
    } catch (PDOException $e) {
        // If access is denied, attempt to bootstrap the user using common root creds
        $bootstrapped = false;
        foreach ([['root', ''], ['root', 'root']] as [$ru, $rp]) {
            try {
                $rootPdo = $connect($ru, $rp);
                $quotedPass = $rootPdo->quote($db_pass);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
                $rootPdo->exec("CREATE USER IF NOT EXISTS '$db_user'@'%' IDENTIFIED BY $quotedPass");
                $rootPdo->exec("CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY $quotedPass");
                $rootPdo->exec("GRANT ALL PRIVILEGES ON `$db_name`.* TO '$db_user'@'%'" );
                $rootPdo->exec("GRANT ALL PRIVILEGES ON `$db_name`.* TO '$db_user'@'localhost'" );
                $rootPdo->exec("FLUSH PRIVILEGES");
                $bootstrapped = true;
                break;
            } catch (PDOException $ie) {
                continue; // try next root candidate
            }
        }
        if (!$bootstrapped) {
            throw $e; // rethrow original access error
        }
        // Retry with the intended user after bootstrap
        $pdo = $connect($db_user, $db_pass);
    }

    // 2. Ensure DB exists and select it
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
    $pdo->exec("USE `$db_name`");

    // 3. Write config and open sql file
    $config_content = "<?php\ndefine('DB_HOST', '$db_host');\ndefine('DB_NAME', '$db_name');\ndefine('DB_USER', '$db_user');\ndefine('DB_PASS', '$db_pass');\n";
    file_put_contents('config.php', $config_content);

    // 4. Run SQL commands
    $sql = file_get_contents('database.sql');
    $pdo->exec($sql);

    // 5. Insert Admin
    $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->execute([$admin_user, $hash]);

    // 6. Update API Key + Admin Path + Email collection flag + default message
    $defaultThanks = "Votre commande a été enregistrée avec succès !";
    $stmt = $pdo->prepare("UPDATE settings SET tml_api_key = ?, admin_slug = ?, collect_email = ?, thanks_message = ? WHERE id = 1");
    $stmt->execute([$api_key, $admin_slug, $collect_email, $defaultThanks]);

    // 7. Initial Location Sync
    if (!empty($api_key)) {
        $ch = curl_init("https://tml.tn/api/v1/locations");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $api_key"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $json = json_decode($response, true);
        if (isset($json['locations'])) {
            $insert = $pdo->prepare("INSERT INTO locations (city_id, city_name, zone_id, zone_name) VALUES (?, ?, ?, ?)");
            foreach ($json['locations'] as $loc) {
                if (isset($loc['districts'])) {
                    foreach ($loc['districts'] as $zone) {
                        $insert->execute([$loc['id'], $loc['name'], $zone['id'], $zone['name']]);
                    }
                }
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Installation complete. Locations synced. You can now login.']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Setup Failed: ' . $e->getMessage()]);
}
