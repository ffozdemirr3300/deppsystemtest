<?php
setlocale(LC_TIME, 'tr_TR.UTF-8');
date_default_timezone_set('Europe/Istanbul');

header('Content-Type: application/json');

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => true, 'message' => 'Geçersiz kullanıcı ID.']);
    exit;
}

$user_id = intval($_GET['id']);
$api_base_url = 'https://api.truckersmp.com/v2';
$url_player = "$api_base_url/player/$user_id";

// Kullanıcı bilgilerini çek
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_player);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 saniye zaman aşımı
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Bağlantı zaman aşımı
$response_player = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response_player === false || $curl_error) {
    error_log("fetch_member.php - cURL hatası: user_id=$user_id, Hata: $curl_error");
    echo json_encode(['error' => true, 'message' => 'API bağlantı hatası: ' . $curl_error]);
    exit;
}

if ($http_code !== 200) {
    error_log("fetch_member.php - Player API hatası: user_id=$user_id, HTTP $http_code, Yanıt: $response_player");
    echo json_encode(['error' => true, 'message' => "Kullanıcı bulunamadı (HTTP $http_code)."]);
    exit;
}

$data_player = json_decode($response_player, true);
if (!isset($data_player['response']) || $data_player['error'] === true) {
    error_log("fetch_member.php - Geçersiz API yanıtı: user_id=$user_id, Yanıt: $response_player");
    echo json_encode(['error' => true, 'message' => 'Kullanıcı verisi alınamadı veya kullanıcı silinmiş olabilir.']);
    exit;
}

$user = [
    'id' => $data_player['response']['id'],
    'name' => htmlspecialchars($data_player['response']['name']),
    'avatar' => $data_player['response']['avatar'] ?? 'https://via.placeholder.com/100',
    'joinDate' => $data_player['response']['joinDate'] ?? null,
    'steamID64' => $data_player['response']['steamID64'] ?? null,
    'banned' => $data_player['response']['banned'] ?? false,
    'bansCount' => $data_player['response']['bansCount'] ?? 0,
    'groupName' => $data_player['response']['groupName'] ?? 'Bilinmiyor',
    'groupColor' => $data_player['response']['groupColor'] ?? '#000000'
];

// SteamID64 doğrulama
if (!$user['steamID64'] || !preg_match('/^\d{17}$/', $user['steamID64'])) {
    error_log("fetch_member.php - Geçersiz SteamID64: user_id=$user_id, steamID64={$user['steamID64']}");
    $user['steamID64'] = null;
}

// Ban geçmişi
$url_bans = "$api_base_url/bans/$user_id";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_bans);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response_bans = curl_exec($ch);
$http_code_bans = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error_bans = curl_error($ch);
curl_close($ch);

$bans = [];
$bansHidden = false;
if ($response_bans === false || $curl_error_bans) {
    error_log("fetch_member.php - Ban API cURL hatası: user_id=$user_id, Hata: $curl_error_bans");
} elseif ($http_code_bans === 200) {
    $bans_data = json_decode($response_bans, true);
    if (!isset($bans_data['error'])) {
        $bans = $bans_data['response'] ?? [];
    }
} elseif ($http_code_bans === 403) {
    $bansHidden = true;
} else {
    error_log("fetch_member.php - Ban API hatası: user_id=$user_id, HTTP $http_code_bans, Yanıt: $response_bans");
}

// VTC geçmişi
$vtc_history = $data_player['response']['vtcHistory'] ?? [];

// Katıldığı etkinlikler
$url_events = "$api_base_url/player/$user_id/events";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_events);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response_events = curl_exec($ch);
$http_code_events = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error_events = curl_error($ch);
curl_close($ch);

$events = [];
if ($response_events === false || $curl_error_events) {
    error_log("fetch_member.php - Events API cURL hatası: user_id=$user_id, Hata: $curl_error_events");
} elseif ($http_code_events === 200) {
    $events_data = json_decode($response_events, true);
    if (!isset($events_data['error'])) {
        $events = $events_data['response'] ?? [];
    }
} else {
    error_log("fetch_member.php - Events API hatası: user_id=$user_id, HTTP $http_code_events, Yanıt: $response_events");
}

// Yanıt
echo json_encode([
    'error' => false,
    'user' => $user,
    'bans' => $bans,
    'bansHidden' => $bansHidden,
    'vtcHistory' => $vtc_history,
    'events' => $events
]);
?>