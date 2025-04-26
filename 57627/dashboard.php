<?php
// Oturumu başlat
session_start();

// auth.php dosyasını dahil et
require_once dirname(__DIR__) . '/auth.php';

// Oturum ve yetki kontrolü
checkAuth('dashboard.php');

// Kullanıcı bilgilerini al
$user = $_SESSION['user'];
$steam_id = $user['steam_id'];
$username = htmlspecialchars($user['username']);
$vtc_id = $user['vtc_id'];
$role = $user['role'];

// Türkçe dil ayarını yapıyoruz
setlocale(LC_TIME, 'tr_TR.UTF-8');
date_default_timezone_set('Europe/Istanbul');

// Ayarları yükle
$settings_file = dirname(__DIR__) . '/' . $vtc_id . '/json/settings.json';
$default_settings = [
    'theme_color' => '#ff6600',
    'header_text' => '',
    'footer_text' => '',
    'font_size' => '16',
    'vtc_id' => '57627',
    'trucky_api_key' => '',
    'trucky_company_id' => ''
];

if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
} else {
    $settings = $default_settings;
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}

// Duyuruları yükle
$announcements_file = dirname(__DIR__) . '/' . $vtc_id . '/json/announcements.json';
if (file_exists($announcements_file)) {
    $announcements_data = json_decode(file_get_contents($announcements_file), true);
    $announcements = $announcements_data['announcements'] ?? [];
} else {
    $announcements = [];
    $default_announcements = ['announcements' => []];
    file_put_contents($announcements_file, json_encode($default_announcements, JSON_PRETTY_PRINT));
}

// Yayınlanmış duyuruları filtrele ve son 3'ünü al
$active_announcements = array_filter($announcements, function($announcement) {
    return isset($announcement['status']) && $announcement['status'] === 'published';
});
usort($active_announcements, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
$recent_announcements = array_slice($active_announcements, 0, 3);

// TruckersMP API'den VTC bilgileri
$url_vtc = 'https://api.truckersmp.com/v2/vtc/' . $settings['vtc_id'] . '/';

// VTC bilgileri
$response_vtc = @file_get_contents($url_vtc);
$data_vtc = $response_vtc ? json_decode($response_vtc, true) : ['response' => []];
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmiyor';
$vtc_owner = $data_vtc['response']['owner_username'] ?? 'Bilinmiyor';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_cover = $data_vtc['response']['cover'] ?? 'https://via.placeholder.com/1200x400';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$members_count = $data_vtc['response']['members_count'] ?? 0;
$language = $data_vtc['response']['language'] ?? 'Bilinmiyor';
$verified = $data_vtc['response']['verified'] ?? false;
$validated = $data_vtc['response']['validated'] ?? false;

// Sosyal medya bağlantıları
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// Trucky API'den tüm teslimatları çekme (sayfalama desteği ile)
function fetchTruckyJobs($company_id, $api_key) {
    $all_jobs = [];
    $page = 1;
    $error = null;

    do {
        $url = "https://e.truckyapp.com/api/v1/company/{$company_id}/jobs?status=completed&page={$page}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Sevkiyatbul/1.0',
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $api_key
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            error_log("Trucky API hatası: HTTP $http_code, Yanıt: $response, Sayfa: $page");
            $error = 'API bağlantı hatası';
            break;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['data'])) {
            error_log("Trucky API geçersiz yanıt: " . json_encode($data));
            $error = 'Geçersiz API yanıtı';
            break;
        }

        $all_jobs = array_merge($all_jobs, $data['data'] ?? []);
        $meta = $data['meta'] ?? [];
        $last_page = $meta['last_page'] ?? 1;
        $page++;
    } while ($page <= $last_page);

    // Nisan 2025 teslimatlarını logla
    $current_month = date('Y-m'); // 2025-04
    $month_jobs = [];
    foreach ($all_jobs as $job) {
        $completed_at = $job['completed_at'] ?? null;
        if ($completed_at) {
            try {
                $job_date = new DateTime($completed_at);
                if ($job_date->format('Y-m') === $current_month) {
                    $month_jobs[] = [
                        'id' => $job['id'] ?? 0,
                        'driver_name' => $job['driver']['name'] ?? 'Bilinmeyen',
                        'driven_distance_km' => $job['driven_distance_km'] ?? null,
                        'planned_distance_km' => $job['planned_distance_km'] ?? null
                    ];
                }
            } catch (Exception $e) {
                error_log("Geçersiz completed_at: {$completed_at}, ID: {$job['id']}");
            }
        }
    }
    error_log("Nisan 2025 teslimatları: " . json_encode($month_jobs));
    error_log("Toplam çekilen teslimat sayısı: " . count($all_jobs));

    return [
        'data' => $all_jobs,
        'meta' => ['total' => count($all_jobs)],
        'error' => $error
    ];
}

// İstatistikleri hesapla
function calculateJobStats($jobs, $meta) {
    $stats = [
        'total_km' => 0,
        'job_count' => count($jobs),
        'total_income' => 0,
        'max_distance' => 0,
        'avg_distance' => 0
    ];

    foreach ($jobs as $job) {
        $distance = $job['driven_distance_km'] ?? $job['planned_distance_km'] ?? 0;
        $distance = floatval($distance); // Sayısal formata çevir
        $income = $job['income'] ?? 0;

        $stats['total_km'] += $distance;
        $stats['total_income'] += $income;
        if ($distance > $stats['max_distance']) {
            $stats['max_distance'] = $distance;
        }
    }

    $stats['avg_distance'] = $stats['job_count'] > 0 ? $stats['total_km'] / $stats['job_count'] : 0;
    return $stats;
}

// Teslimatları çek
$jobs_data = fetchTruckyJobs($settings['trucky_company_id'], $settings['trucky_api_key']);
$all_jobs = $jobs_data['data'];
$meta = $jobs_data['meta'];
$api_error = $jobs_data['error'];
$job_stats = calculateJobStats($all_jobs, $meta);

// Geçerli ayın lider sürücüsünü hesapla
$current_month = date('Y-m'); // 2025-04
$driver_stats = [];
$current_month_jobs = 0;
$invalid_distance_jobs = [];
$invalid_driver_jobs = [];

foreach ($all_jobs as $job) {
    $job_id = $job['id'] ?? 0;
    $completed_at = $job['completed_at'] ?? null;

    if (!$completed_at) {
        error_log("Eksik completed_at: Teslimat ID: $job_id");
        continue;
    }

    // completed_at tarihini kontrol et
    try {
        $job_date = new DateTime($completed_at);
        $job_month = $job_date->format('Y-m');
        if ($job_month !== $current_month) {
            continue; // Sadece Nisan 2025 teslimatları
        }
        $current_month_jobs++;
    } catch (Exception $e) {
        error_log("Geçersiz completed_at formatı: $completed_at, Teslimat ID: $job_id, Hata: " . $e->getMessage());
        continue;
    }

    // Sürücü bilgilerini kontrol et
    if (!isset($job['driver']) || !isset($job['driver']['name']) || empty($job['driver']['name'])) {
        error_log("Geçersiz veya eksik sürücü bilgisi: Teslimat ID: $job_id");
        $invalid_driver_jobs[] = $job_id;
        continue;
    }

    $driver_name = $job['driver']['name'];
    $driver_id = $job['driver']['id'] ?? null;
    $distance = $job['driven_distance_km'] ?? $job['planned_distance_km'] ?? 0;
    $distance = floatval($distance); // Sayısal formata çevir

    // Mesafe kontrolü
    if ($distance <= 0) {
        error_log("Geçersiz mesafe (0 veya eksik): Teslimat ID: $job_id, Sürücü: $driver_name");
        $invalid_distance_jobs[] = $job_id;
    }

    if (!isset($driver_stats[$driver_name])) {
        $driver_stats[$driver_name] = [
            'id' => $driver_id,
            'deliveries' => 0,
            'distance' => 0,
            'income' => 0
        ];
    }
    $driver_stats[$driver_name]['deliveries']++;
    $driver_stats[$driver_name]['distance'] += $distance;
    $driver_stats[$driver_name]['income'] += $job['income'] ?? 0;
}

// Debug için loglar
error_log("Geçerli ay ($current_month) teslimat sayısı: $current_month_jobs");
error_log("Driver stats: " . json_encode($driver_stats));
if (!empty($invalid_distance_jobs)) {
    error_log("Geçersiz mesafe olan teslimatlar: " . implode(', ', $invalid_distance_jobs));
}
if (!empty($invalid_driver_jobs)) {
    error_log("Geçersiz sürücü bilgisi olan teslimatlar: " . implode(', ', $invalid_driver_jobs));
}

// Lider sürücüyü kilometreye göre sırala
uasort($driver_stats, function($a, $b) {
    if ($b['distance'] === $a['distance']) {
        return $b['deliveries'] - $a['deliveries']; // Aynı mesafede teslimat sayısına göre sırala
    }
    return $b['distance'] - $a['distance'];
});

// Lider sürücüyü seç
$leader_driver = null;
$leader_driver_name = 'Bilinmeyen Sürücü';
if (!empty($driver_stats)) {
    $leader_driver = reset($driver_stats); // İlk sürücüyü al
    $leader_driver_name = key($driver_stats); // İlk sürücünün adını al
} else {
    $leader_driver = ['id' => null, 'deliveries' => 0, 'distance' => 0, 'income' => 0];
}
error_log("Lider sürücü seçimi: Ad: $leader_driver_name, Mesafe: " . ($leader_driver['distance'] ?? 'Yok') . ", Teslimat: " . ($leader_driver['deliveries'] ?? 'Yok'));

// Son 5 teslimat
usort($all_jobs, function($a, $b) {
    return strtotime($b['completed_at'] ?? 'now') - strtotime($a['completed_at'] ?? 'now');
});
$recent_jobs = array_slice($all_jobs, 0, 5);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $vtc_name; ?> - VTC Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --theme-color: <?php echo $settings['theme_color']; ?>;
            --font-size: <?php echo $settings['font_size']; ?>px;
        }

        body {
            font-family: 'Roboto', Arial, sans-serif;
            background-color: #f1f4f9;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            font-size: var(--font-size);
        }
        .container-custom {
            margin-top: 40px;
            margin-bottom: 40px;
        }
        nav.navbar {
            background-color: #1e212d;
            padding: 15px 0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            color: #fff;
            font-size: 1.8rem;
            font-weight: 700;
            transition: color 0.3s ease;
        }
        .navbar-brand img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            margin-right: 12px;
            transition: transform 0.3s ease;
        }
        .navbar-brand:hover img {
            transform: scale(1.1);
        }
        .navbar-nav .nav-link {
            color: #fff !important;
            font-weight: 500;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        .navbar-nav .nav-link:hover {
            color: var(--theme-color) !important;
            transform: translateY(-2px);
        }
        .social-icons a {
            color: #fff;
            font-size: 1.6rem;
            margin-left: 15px;
            transition: all 0.3s ease;
        }
        .social-icons a:hover {
            color: var(--theme-color);
            transform: scale(1.2);
        }
        /* Banner */
        .banner {
            position: relative;
            width: 100%;
            height: 300px;
            background-image: url('<?php echo $vtc_cover; ?>');
            background-size: cover;
            background-position: center;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            margin-bottom: 40px;
            overflow: hidden;
        }
        .banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1;
        }
        .banner-content {
            position: relative;
            z-index: 2;
            padding: 20px;
        }
        .banner-content h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        .banner-content p {
            font-size: 1.2rem;
            font-weight: 400;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }
        .btn-custom {
            background-color: var(--theme-color);
            color: #fff;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .btn-custom:hover {
            background-color: #e65c00;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
        }
        .card-custom {
            background-color: #fff;
            border: none;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card-custom:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .card-custom h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        /* Kompakt card sınıfı */
        .card-compact {
            background-color: #fff;
            border: none;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card-compact:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .card-compact h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e212d;
            margin-bottom: 30px;
            text-align: center;
        }
        .footer {
            background-color: #1e212d;
            color: #fff;
            padding: 40px 0;
            text-align: center;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.2);
        }
        .footer a {
            color: var(--theme-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .footer a:hover {
            color: #e65c00;
        }
        .footer .social-icons {
            margin-top: 15px;
        }
        .footer .social-icons a {
            font-size: 1.8rem;
            margin: 0 10px;
        }
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background-color: var(--theme-color);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(100px);
        }
        .scroll-to-top.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .scroll-to-top:hover {
            background-color: #e65c00;
            transform: scale(1.1);
        }
        .stats-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background-color: var(--theme-color);
        }
        .stats-card h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin: 8px 0;
        }
        .stats-card p {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--theme-color);
            margin: 0;
        }
        .stats-icon {
            font-size: 2.2rem;
            color: var(--theme-color);
            margin-bottom: 8px;
        }
        .table-custom th {
            background-color: var(--theme-color);
            color: #fff;
            font-weight: 600;
        }
        .table-custom td {
            vertical-align: middle;
            cursor: pointer;
        }
        .table-custom .job-row:hover {
            background-color: #f8f9fa;
        }
        .modal-content {
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            background-color: #1e212d;
            color: #fff;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .modal-body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .modal-body p {
            font-size: 0.95rem;
            color: #555;
            margin: 5px 0;
        }
        .modal-footer {
            background-color: #f8f9fa;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out;
            transform: translateY(-50px);
        }
        .modal.show .modal-dialog {
            transform: translateY(0);
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        .dropdown-menu {
            background-color: #1e212d;
            border: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .dropdown-menu .dropdown-item {
            color: #fff;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        .dropdown-menu .dropdown-item:hover {
            background-color: var(--theme-color);
            color: #fff;
        }
        .dropdown-toggle::after {
            border-top-color: #fff;
        }
        .dropdown-toggle:hover::after {
            border-top-color: var(--theme-color);
        }
        .user-dropdown {
            color: #fff !important;
            font-weight: 500;
            padding: 10px 15px;
        }
        .user-dropdown:hover {
            color: var(--theme-color) !important;
        }
        /* Duyuru Kutucuğu Stilleri */
        .announcement-list {
            max-height: 250px;
            overflow-y: auto;
        }
        .announcement-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .announcement-item:last-child {
            border-bottom: none;
        }
        .announcement-item h5 {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .announcement-item p {
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
        }
        .announcement-item small {
            font-size: 0.75rem;
            color: #777;
        }
        /* VTC Bilgileri için kompakt stiller */
        .vtc-info p {
            font-size: 0.85rem;
            margin: 5px 0;
        }
        .vtc-info strong {
            font-weight: 600;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card-custom, .card-compact, .stats-card {
            animation: fadeInUp 0.6s ease-in-out;
        }
        @media (max-width: 768px) {
            .card-custom {
                padding: 15px;
            }
            .card-compact {
                padding: 12px;
            }
            .card-compact h3 {
                font-size: 1.3rem;
            }
            .stats-card {
                padding: 12px;
            }
            .stats-card h4 {
                font-size: 1rem;
            }
            .stats-card p {
                font-size: 1.1rem;
            }
            .stats-icon {
                font-size: 1.8rem;
            }
            .modal-dialog {
                margin: 10px;
            }
            .announcement-item h5 {
                font-size: 0.9rem;
            }
            .announcement-item p {
                font-size: 0.8rem;
            }
            .announcement-item small {
                font-size: 0.7rem;
            }
            .vtc-info p {
                font-size: 0.8rem;
            }
            /* Mobil cihazlarda hover devre dışı bırakılır */
            .nav-item.dropdown:hover .dropdown-menu {
                display: none;
            }
            .banner {
                height: 200px;
            }
            .banner-content h1 {
                font-size: 1.8rem;
            }
            .banner-content p {
                font-size: 1rem;
            }
        }
        @media (max-width: 576px) {
            .banner {
                height: 150px;
            }
            .banner-content h1 {
                font-size: 1.5rem;
            }
            .banner-content p {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="<?php echo $vtc_logo; ?>" alt="VTC Logo">
                <?php echo $settings['header_text'] ?: $vtc_name; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'members.php' ? 'active' : ''; ?>" href="members.php">Üyeler</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>" href="delivery.php">Teslimatlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'events.php' ? 'active' : ''; ?>" href="events.php">Etkinlikler</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'server_status.php' ? 'active' : ''; ?>" href="server_status.php">Sunucu Durumu</a>
                    </li>
                    <!-- Kullanıcı Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link user-dropdown dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-user-circle"></i> <?php echo $username; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#accountModal"><i class="fa fa-user"></i> Hesabım</a></li>
                            <li><a class="dropdown-item" href="announcements.php"><i class="fa fa-bullhorn"></i> Duyurular</a></li>
                            <li><a class="dropdown-item" href="ayarlar.php"><i class="fa fa-cog"></i> Ayarlar</a></li>
                            <?php if ($role === 'owner' || $role === 'superadmin') { ?>
                                <li><a class="dropdown-item" href="permissions.php"><i class="fa fa-shield"></i> Yetki Yönetimi</a></li>
                            <?php } ?>
                            <li><a class="dropdown-item" href="logout.php"><i class="fa fa-sign-out"></i> Çıkış Yap</a></li>
                        </ul>
                    </li>
                </ul>
                <div class="social-icons">
                    <?php if ($twitter) { ?>
                        <a href="<?php echo $twitter; ?>" target="_blank"><i class="fa fa-twitter"></i></a>
                    <?php } ?>
                    <?php if ($twitch) { ?>
                        <a href="<?php echo $twitch; ?>" target="_blank"><i class="fa fa-twitch"></i></a>
                    <?php } ?>
                    <?php if ($discord) { ?>
                        <a href="<?php echo $discord; ?>" target="_blank"><i class="fa fa-discord"></i></a>
                    <?php } ?>
                    <?php if ($vtc_website) { ?>
                        <a href="<?php echo $vtc_website; ?>" target="_blank"><i class="fa fa-globe"></i></a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Banner -->
    <div class="banner">
        <div class="banner-content">
            <h1><?php echo $settings['header_text'] ?: $vtc_name; ?></h1>
            <p><?php echo htmlspecialchars($vtc_slogan); ?></p>
        </div>
    </div>

    <!-- İçerik -->
    <div class="container container-custom">
        <!-- API Hata Mesajı -->
        <?php if ($api_error) { ?>
            <div class="error-message">
                <i class="fa fa-exclamation-triangle"></i> Teslimat verileri yüklenemedi: <?php echo htmlspecialchars($api_error); ?>
            </div>
        <?php } ?>

        <!-- İstatistik Kutucukları -->
        <h3 class="section-title">VTC İstatistikleri</h3>
        <div class="row g-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fa fa-road stats-icon"></i>
                    <h4>Toplam Mesafe</h4>
                    <p><?php echo number_format($job_stats['total_km'], 0, ',', '.') . ' km'; ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fa fa-truck stats-icon"></i>
                    <h4>Teslim Edilen İş</h4>
                    <p><?php echo number_format($job_stats['job_count'], 0, ',', '.'); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fa fa-money stats-icon"></i>
                    <h4>Toplam Kazanç</h4>
                    <p><?php echo number_format($job_stats['total_income'], 0, ',', '.') . ' €'; ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fa fa-trophy stats-icon"></i>
                    <h4>Ayın Lider Sürücüsü</h4>
                    <p>
                        <?php if (!empty($driver_stats) && $leader_driver['distance'] > 0) { ?>
                            <?php echo htmlspecialchars($leader_driver_name); ?>
                            <br>
                            <small>
                                <?php echo number_format($leader_driver['distance'], 0, ',', '.') . ' km'; ?>, 
                                <?php echo $leader_driver['deliveries']; ?> teslimat
                            </small>
                        <?php } else { ?>
                            Lider sürücü belirlenemedi.
                            <?php if ($current_month_jobs > 0) { ?>
                                <br><small>Teslimatlar mevcut, ancak mesafe bilgisi eksik veya sıfır.</small>
                            <?php } ?>
                            <?php if (!empty($invalid_distance_jobs)) { ?>
                                <br><small>Geçersiz mesafe teslimatları: <?php echo implode(', ', $invalid_distance_jobs); ?>.</small>
                            <?php } ?>
                            <?php if (!empty($invalid_driver_jobs)) { ?>
                                <br><small>Geçersiz sürücü bilgisi: <?php echo implode(', ', $invalid_driver_jobs); ?>.</small>
                            <?php } ?>
                        <?php } ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Duyurular ve VTC Bilgileri (Yan Yana) -->
        <div class="row mt-5">
            <!-- Duyurular -->
            <div class="col-md-6">
                <div class="card-compact">
                    <h3><i class="fa fa-bullhorn"></i> Duyurular</h3>
                    <?php if (empty($recent_announcements)) { ?>
                        <p class="text-center">Henüz yayınlanmış duyuru bulunmamaktadır.</p>
                    <?php } else { ?>
                        <div class="announcement-list">
                            <?php foreach ($recent_announcements as $announcement) { ?>
                                <div class="announcement-item">
                                    <h5>
                                        <?php echo htmlspecialchars($announcement['title']); ?>
                                        <span class="badge bg-success">Yayınlandı</span>
                                    </h5>
                                    <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    <small>
                                        <i class="fa fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?> 
                                        | <i class="fa fa-calendar"></i> <?php echo strftime('%d %B %Y %H:%M', strtotime($announcement['created_at'])); ?>
                                        <?php if (isset($announcement['updated_at'])): ?>
                                            | <i class="fa fa-edit"></i> Güncellenme: <?php echo strftime('%d %B %Y %H:%M', strtotime($announcement['updated_at'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <a href="announcements.php" class="btn btn-custom btn-sm mt-3">Tüm Duyuruları Gör</a>
                </div>
            </div>
            <!-- VTC Bilgileri -->
            <div class="col-md-6">
                <div class="card-compact">
                    <h3><i class="fa fa-info-circle"></i> VTC Bilgileri</h3>
                    <div class="vtc-info">
                        <p><i class="fa fa-user"></i> <strong>Kurucu:</strong> <?php echo $vtc_owner; ?></p>
                        <p><i class="fa fa-users"></i> <strong>Toplam Üye Sayısı:</strong> <?php echo $members_count; ?></p>
                        <p><i class="fa fa-check-circle"></i> <strong>Onay Durumu:</strong> <?php echo $verified ? 'Evet' : 'Hayır'; ?></p>
                        <p><i class="fa fa-shield"></i> <strong>Doğrulama Durumu:</strong> <?php echo $validated ? 'Evet' : 'Hayır'; ?></p>
                        <p><i class="fa fa-globe"></i> <strong>Dil:</strong> <?php echo $language; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Canlı Teslimat Akışı -->
        <div class="mt-5">
            <h3 class="section-title">Canlı Teslimat Akışı</h3>
            <div class="card-custom">
                <table class="table table-custom table-striped">
                    <thead>
                        <tr>
                            <th>Sürücü</th>
                            <th>Kargo</th>
                            <th>Rota</th>
                            <th>Mesafe</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_jobs)) { ?>
                            <tr>
                                <td colspan="5" class="text-center">Henüz teslimat bulunmamaktadır.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($recent_jobs as $job) { ?>
                                <tr class="job-row" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#jobDetailModal" 
                                    onclick="showJobDetails(<?php echo htmlspecialchars(json_encode($job)); ?>)">
                                    <td><?php echo htmlspecialchars($job['driver']['name'] ?? 'Bilinmeyen Sürücü'); ?></td>
                                    <td><?php echo htmlspecialchars($job['cargo_name'] ?? 'Bilinmiyor'); ?></td>
                                    <td><?php echo htmlspecialchars($job['source_city_name'] ?? 'Bilinmiyor') . ' → ' . htmlspecialchars($job['destination_city_name'] ?? 'Bilinmiyor'); ?></td>
                                    <td><?php echo number_format($job['driven_distance_km'] ?? $job['planned_distance_km'] ?? 0, 0, ',', '.'); ?> km</td>
                                    <td><?php echo $job['completed_at'] ? strftime('%d %B %Y %H:%M', strtotime($job['completed_at'])) : 'Bilinmiyor'; ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
                <a href="delivery.php" class="btn btn-custom">Tüm Teslimatları Gör</a>
            </div>
        </div>

        <!-- Yük Detay Modal -->
        <div class="modal fade" id="jobDetailModal" tabindex="-1" aria-labelledby="jobDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="jobDetailModalLabel">Yük Detayları</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body" id="jobDetailBody">
                        <p class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hesabım Modal -->
        <div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="accountModalLabel">Hesabım</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p><i class="fa fa-user"></i> <strong>Kullanıcı Adı:</strong> <?php echo $username; ?></p>
                        <p><i class="fa fa-steam"></i> <strong>Steam ID:</strong> <?php echo $steam_id; ?></p>
                        <p><i class="fa fa-truck"></i> <strong>VTC ID:</strong> <?php echo $vtc_id; ?></p>
                        <p><i class="fa fa-shield"></i> <strong>Rol:</strong> <?php echo ucfirst($role); ?></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p><?php echo $settings['footer_text'] ?: '© ' . date('Y') . ' ' . $vtc_name . ' - Tüm Hakları Saklıdır'; ?></p>
            <div class="social-icons">
                <?php if ($twitter) { ?>
                    <a href="<?php echo $twitter; ?>" target="_blank"><i class="fa fa-twitter"></i></a>
                <?php } ?>
                <?php if ($twitch) { ?>
                    <a href="<?php echo $twitch; ?>" target="_blank"><i class="fa fa-twitch"></i></a>
                <?php } ?>
                <?php if ($discord) { ?>
                    <a href="<?php echo $discord; ?>" target="_blank"><i class="fa fa-discord"></i></a>
                <?php } ?>
                <?php if ($vtc_website) { ?>
                    <a href="<?php echo $vtc_website; ?>" target="_blank"><i class="fa fa-globe"></i></a>
                <?php } ?>
            </div>
        </div>
    </footer>

    <!-- Scroll to Top -->
    <button class="scroll-to-top"><i class="fa fa-chevron-up"></i></button>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Scroll to top butonu
        window.onscroll = function() {
            var scrollButton = document.querySelector('.scroll-to-top');
            if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
                scrollButton.classList.add('visible');
            } else {
                scrollButton.classList.remove('visible');
            }
        };

        // Scroll to top tıklama
        document.querySelector('.scroll-to-top').addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Yük detaylarını gösterme
        function showJobDetails(job) {
            const modalBody = document.getElementById('jobDetailBody');
            modalBody.innerHTML = `
                <p><i class="fa fa-user"></i> <strong>Sürücü:</strong> ${job.driver?.name || 'Bilinmeyen Sürücü'}</p>
                <p><i class="fa fa-cube"></i> <strong>Kargo:</strong> ${job.cargo_name || 'Bilinmiyor'}</p>
                <p><i class="fa fa-map-marker"></i> <strong>Rota:</strong> ${job.source_city_name || 'Bilinmiyor'} → ${job.destination_city_name || 'Bilinmiyor'}</p>
                <p><i class="fa fa-road"></i> <strong>Mesafe:</strong> ${job.driven_distance_km ? job.driven_distance_km.toLocaleString('tr-TR') : job.planned_distance_km?.toLocaleString('tr-TR') || 0} km</p>
                <p><i class="fa fa-money"></i> <strong>Gelir:</strong> ${job.income ? job.income.toLocaleString('tr-TR') + ' €' : 'Bilinmiyor'}</p>
                <p><i class="fa fa-calendar"></i> <strong>Teslim Tarihi:</strong> ${job.completed_at ? new Date(job.completed_at).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Bilinmiyor'}</p>
                <p><i class="fa fa-tachometer"></i> <strong>Hasar:</strong> ${job.damage ? job.damage + '%' : 'Bilinmiyor'}</p>
                <p><i class="fa fa-truck"></i> <strong>Tır Modeli:</strong> ${job.truck_model || 'Bilinmiyor'}</p>
            `;
        }

        // Dropdown menüsünü hover ile açma
        document.addEventListener('DOMContentLoaded', function() {
            const dropdowns = document.querySelectorAll('.nav-item.dropdown');
            dropdowns.forEach(dropdown => {
                dropdown.addEventListener('mouseenter', function() {
                    const dropdownMenu = this.querySelector('.dropdown-menu');
                    dropdownMenu.classList.add('show');
                    this.querySelector('.dropdown-toggle').setAttribute('aria-expanded', 'true');
                });
                dropdown.addEventListener('mouseleave', function() {
                    const dropdownMenu = this.querySelector('.dropdown-menu');
                    dropdownMenu.classList.remove('show');
                    this.querySelector('.dropdown-toggle').setAttribute('aria-expanded', 'false');
                });
            });
        });
    </script>
</body>
</html>