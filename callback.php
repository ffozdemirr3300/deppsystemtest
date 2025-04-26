<?php
// Oturum ayarlarını yapılandır (session_start öncesi)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // HTTPS kullanıyorsanız
ini_set('session.use_only_cookies', 1);

// Oturumu başlat
session_start();

// Oturumun aktif olduğunu doğrula
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("callback.php: Oturum başlatılamadı!");
    die("Oturum başlatılamadı. Lütfen sunucu yapılandırmasını kontrol edin.");
}

// Gelen parametreleri logla
error_log("callback.php - Gelen GET Parametreleri: " . print_r($_GET, true));

// openid.return_to doğrulama (CSRF koruması)
$expected_return_to = 'https://sevkiyatbul.com.tr/callback.php';
$received_return_to = $_GET['openid_return_to'] ?? '';

if ($received_return_to !== $expected_return_to) {
    $_SESSION['error'] = 'Geçersiz openid.return_to değeri. Lütfen tekrar deneyin.';
    header('Location: /index.php');
    exit;
}

// Steam OpenID doğrulama
try {
    $params = [
        'openid.ns' => $_GET['openid_ns'] ?? 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'check_authentication',
        'openid.op_endpoint' => $_GET['openid_op_endpoint'] ?? 'https://steamcommunity.com/openid/login',
        'openid.claimed_id' => $_GET['openid_claimed_id'],
        'openid.identity' => $_GET['openid_identity'],
        'openid.return_to' => $_GET['openid_return_to'],
        'openid.response_nonce' => $_GET['openid_response_nonce'],
        'openid.assoc_handle' => $_GET['openid_assoc_handle'],
        'openid.signed' => $_GET['openid_signed'],
        'openid.sig' => $_GET['openid_sig']
    ];

    // Steam OpenID doğrulama isteği
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://steamcommunity.com/openid/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || strpos($response, 'is_valid:true') === false) {
        $_SESSION['error'] = 'Steam doğrulaması başarısız.';
        header('Location: /index.php');
        exit;
    }

    // Steam ID'yi al
    $claimed_id = $_GET['openid_claimed_id'] ?? '';
    error_log("callback.php - openid_claimed_id: " . $claimed_id);

    // Steam ID'yi çıkarmak için regex (format: https://steamcommunity.com/openid/id/xxx)
    if (preg_match('/\/id\/(\d+)/', $claimed_id, $matches)) {
        $steam_id = $matches[1];
        error_log("callback.php - Çıkarılan Steam ID: " . $steam_id);
    } else {
        error_log("callback.php - Steam ID çıkarma başarısız. Regex eşleşmedi.");
        $_SESSION['error'] = 'Steam ID alınamadı.';
        header('Location: /index.php');
        exit;
    }

    // TruckersMP API ile kullanıcı bilgilerini al
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/player/{$steam_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $_SESSION['error'] = 'TruckersMP API\'ye ulaşılamadı.';
        header('Location: /index.php');
        exit;
    }

    $data = json_decode($response, true);
    $vtc_id = $data['response']['vtc']['id'] ?? null;
    error_log("callback.php - Çekilen VTC ID: " . $vtc_id);

    if (!$vtc_id) {
        $_SESSION['error'] = 'Herhangi bir VTC\'ye üye değilsiniz.';
        header('Location: /index.php');
        exit;
    }

    // VTC klasöründe settings.json var mı kontrol et
    // VTC klasörleri doğrudan kök dizinde (örneğin, /home/kap192ustacom/sevkiyatbul.com.tr/57627/settings.json)
    $settings_file = __DIR__ . "/{$vtc_id}/json/settings.json";
    error_log("callback.php - Kontrol edilen settings.json yolu: " . $settings_file);
    error_log("callback.php - settings.json var mı: " . (file_exists($settings_file) ? 'Evet' : 'Hayır'));

    if (!file_exists($settings_file)) {
        error_log("callback.php - settings.json dosyası bulunamadı: " . $settings_file);
        $_SESSION['error'] = 'Bu VTC için yapılandırma dosyası bulunamadı (VTC ID: ' . htmlspecialchars($vtc_id) . ').';
        header('Location: /index.php');
        exit;
    }

    // settings.json dosyasını oku ve doğrula
    $settings_content = file_get_contents($settings_file);
    $settings = json_decode($settings_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("callback.php - settings.json parse hatası: " . json_last_error_msg());
        $_SESSION['error'] = 'Yapılandırma dosyası geçersiz (VTC ID: ' . htmlspecialchars($vtc_id) . ').';
        header('Location: /index.php');
        exit;
    }
    error_log("callback.php - settings.json içeriği: " . print_r($settings, true));

    // VTC üyeliğini doğrula
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}/members");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $_SESSION['error'] = 'TruckersMP API\'ye ulaşılamadı.';
        header('Location: /index.php');
        exit;
    }

    $data = json_decode($response, true);
    $members = $data['response']['members'] ?? [];

    $is_member = false;
    $username = '';
    foreach ($members as $member) {
        if ($member['steamID'] == $steam_id) {
            $is_member = true;
            $username = $member['username'];
            break;
        }
    }

    if ($is_member) {
        $_SESSION['user'] = [
            'steam_id' => $steam_id,
            'username' => $username,
            'vtc_id' => $vtc_id,
            'settings' => $settings // settings.json içeriğini oturuma ekle
        ];
        header("Location: /{$vtc_id}/dashboard.php");
        exit;
    } else {
        $_SESSION['error'] = 'Bu VTC\'nin üyesi değilsiniz.';
        header('Location: /index.php');
        exit;
    }
} catch (Exception $e) {
    error_log("callback.php - Hata: " . $e->getMessage());
    $_SESSION['error'] = 'Bir hata oluştu: ' . $e->getMessage();
    header('Location: /index.php');
    exit;
}
?>