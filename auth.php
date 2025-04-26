<?php
session_start();

// Superadmin Steam ID'si
define('SUPERADMIN_STEAM_ID', '76561198243975844');

function checkAuth($required_page = null) {
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['steam_id'])) {
        header('Location: /index.php');
        exit;
    }

    // Superadmin kontrolü
    if ($_SESSION['user']['steam_id'] === SUPERADMIN_STEAM_ID) {
        $_SESSION['user']['role'] = 'superadmin';
        $_SESSION['user']['vtc_id'] = $_SESSION['user']['vtc_id'] ?? '57627'; // Varsayılan VTC ID, gerektiğinde ayarlanabilir
        error_log("auth.php - Superadmin oturumu: Steam ID " . SUPERADMIN_STEAM_ID);
        return; // Superadmin için diğer kontroller atlanıyor
    }

    // VTC ID kontrolü
    if (!isset($_SESSION['user']['vtc_id'])) {
        header('Location: /index.php?error=No%20VTC%20ID');
        exit;
    }

    $current_vtc_id = basename(dirname($_SERVER['SCRIPT_NAME']));
    if ($current_vtc_id != $_SESSION['user']['vtc_id']) {
        header('Location: /' . $_SESSION['user']['vtc_id'] . '/index.php');
        exit;
    }

    // settings.json dosyasını ilgili VTC klasöründen yükle
    $settings_file = dirname(__FILE__) . '/' . $_SESSION['user']['vtc_id'] . '/json/settings.json';
    if (!file_exists($settings_file)) {
        session_destroy();
        header('Location: /index.php?error=Settings%20file%20not%20found');
        exit;
    }

    // permissions.json dosyasını yükle
    $permissions_file = dirname(__FILE__) . '/' . $_SESSION['user']['vtc_id'] . '/json/permissions.json';
    $default_permissions = [
        'roles' => [
            'superadmin' => ['permissions' => ['dashboard.php', 'members.php', 'delivery.php', 'events.php', 'server_status.php', 'ayarlar.php', 'permissions.php']],
            'owner' => ['permissions' => ['dashboard.php', 'members.php', 'delivery.php', 'events.php', 'server_status.php', 'permissions.php']],
            'admin' => ['permissions' => ['dashboard.php', 'members.php', 'delivery.php']],
            'member' => ['permissions' => ['dashboard.php']]
        ],
        'users' => [
            SUPERADMIN_STEAM_ID => 'superadmin'
        ]
    ];

    if (!file_exists($permissions_file)) {
        file_put_contents($permissions_file, json_encode($default_permissions, JSON_PRETTY_PRINT));
        $permissions = $default_permissions;
    } else {
        $permissions = json_decode(file_get_contents($permissions_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("auth.php - permissions.json parse hatası: " . json_last_error_msg());
            header('Location: /index.php?error=Invalid%20permissions%20file');
            exit;
        }
    }

    $steam_id = $_SESSION['user']['steam_id'];
    $vtc_id = $_SESSION['user']['vtc_id'];

    // VTC bilgilerini al
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("auth.php - TruckersMP VTC API hatası: HTTP $http_code, Yanıt: " . $response);
        header('Location: /index.php?error=API%20error');
        exit;
    }

    $vtc_data = json_decode($response, true);
    $owner_id = $vtc_data['response']['owner_id'] ?? null;

    // owner_id'den SteamID64 al
    $owner_steam_id = null;
    if ($owner_id) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/player/{$owner_id}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
        $player_response = curl_exec($ch);
        $player_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($player_http_code === 200) {
            $player_data = json_decode($player_response, true);
            $owner_steam_id = $player_data['response']['steamID64'] ?? null;
        } else {
            error_log("auth.php - TruckersMP Player API hatası: HTTP $player_http_code, Yanıt: " . $player_response);
        }
    }

    // Hata ayıklama için log
    error_log("auth.php - Kullanıcı Steam ID: $steam_id, VTC ID: $vtc_id, Sahip ID: $owner_id, Sahip Steam ID: " . ($owner_steam_id ?? 'null'));

    if ((string)$steam_id === (string)$owner_steam_id) {
        error_log("auth.php - Sahip eşleşti, owner rolü atanıyor: $steam_id");
        $permissions['users'][$steam_id] = 'owner';
        file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT));
        $_SESSION['user']['role'] = 'owner';
    } elseif (!isset($permissions['users'][$steam_id])) {
        error_log("auth.php - Yeni kullanıcı, member rolü atanıyor: $steam_id");
        $permissions['users'][$steam_id] = 'member';
        file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT));
        $_SESSION['user']['role'] = 'member';
    } else {
        error_log("auth.php - Mevcut rol kullanılıyor: $steam_id, Rol: " . $permissions['users'][$steam_id]);
        $_SESSION['user']['role'] = $permissions['users'][$steam_id];
    }

    if ($required_page) {
        $user_role = $_SESSION['user']['role'];
        if ($user_role !== 'superadmin') { // Superadmin için yetki kontrolü atlanıyor
            $allowed_pages = $permissions['roles'][$user_role]['permissions'] ?? [];
            if (!in_array($required_page, $allowed_pages)) {
                header('Location: /' . $vtc_id . '/dashboard.php?error=Unauthorized');
                exit;
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /index.php');
    exit;
}
?>