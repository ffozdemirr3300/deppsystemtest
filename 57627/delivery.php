<?php
session_start();
require_once dirname(__DIR__) . '/auth.php';
checkAuth('delivery.php');

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

// permissions.json dosyasını yükle
$permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
if (file_exists($permissions_file)) {
    $permissions = json_decode(file_get_contents($permissions_file), true);
    if ($permissions === null) {
        error_log("delivery.php - permissions.json geçersiz JSON: $permissions_file");
        $_SESSION['error'] = 'permissions.json dosyası bozuk.';
        header('Location: dashboard.php');
        exit;
    }
} else {
    $_SESSION['error'] = 'permissions.json dosyası bulunamadı.';
    header('Location: dashboard.php');
    exit;
}

// Kullanıcı yetkilerini al
$user_permissions = $permissions['roles'][$role]['permissions'] ?? [];

// VTC bilgilerini al (navbar ve banner için)
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$settings['vtc_id']}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response_vtc = curl_exec($ch);
$http_code_vtc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data_vtc = $http_code_vtc === 200 ? json_decode($response_vtc, true) : ['response' => []];
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_cover = $data_vtc['response']['cover'] ?? 'https://via.placeholder.com/1200x400';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// Trucky API'den tüm yükleri çekme fonksiyonu
function fetchTruckyJobs($company_id, $api_key) {
    $url = "https://e.truckyapp.com/api/v1/company/{$company_id}/jobs?status=completed";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: RouteVTC',
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Trucky API hatası: HTTP $http_code, Yanıt: $response");
        return ['data' => [], 'meta' => ['total' => 0]];
    }

    $data = json_decode($response, true);
    $jobs = $data['data'] ?? [];

    // Meta verileri al (varsa)
    $meta = $data['meta'] ?? [];
    $meta['total'] = $meta['total'] ?? count($jobs);

    // Yalnızca meta veriler boşsa veya beklenenden farklıysa logla
    if (empty($meta) || $meta['total'] !== count($jobs)) {
        error_log("API Yanıtı: DataCount=" . count($jobs) . ", Meta=" . json_encode($data['meta'] ?? []));
    }

    return [
        'data' => $jobs,
        'meta' => $meta
    ];
}

// Teslimat istatistiklerini hesaplayan fonksiyon
function calculateJobStats($jobs, $meta) {
    $stats = [
        'total_km' => 0,
        'job_count' => $meta['total'] ?? count($jobs),
        'max_distance' => 0,
        'avg_distance' => 0,
        'max_duration' => 0,
        'avg_duration' => 0
    ];

    $durations = [];
    foreach ($jobs as $job) {
        // Mesafe hesaplama
        $distance = $job['driven_distance_km'] ?? $job['planned_distance_km'] ?? 0;
        $stats['total_km'] += $distance;
        if ($distance > $stats['max_distance']) {
            $stats['max_distance'] = $distance;
        }

        // Süre hesaplama (varsayımsal: mesafeye bağlı tahmini süre)
        $duration = estimateJobDuration($distance); // Süreyi saniye cinsinden tahmin et
        $durations[] = $duration;
        if ($duration > $stats['max_duration']) {
            $stats['max_duration'] = $duration;
        }
    }

    // Ortalama mesafe
    $stats['avg_distance'] = $stats['job_count'] > 0 ? $stats['total_km'] / $stats['job_count'] : 0;

    // Ortalama süre
    $stats['avg_duration'] = !empty($durations) ? array_sum($durations) / count($durations) : 0;

    return $stats;
}

// Süre tahmini (örnek: her 100 km için 1 saat)
function estimateJobDuration($distance) {
    // Varsayımsal: 100 km = 3600 saniye (1 saat)
    return ($distance / 100) * 3600;
}

// Süreyi insan dostu formata çevir
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf('%d saat %d dakika', $hours, $minutes);
}

// Mevcut sayfa numarasını al (GET parametresinden)
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$per_page = 10; // Her sayfada 10 yük

// Teslimatları çek (tüm yükler)
$jobs_data = fetchTruckyJobs($settings['trucky_company_id'], $settings['trucky_api_key']);
$all_jobs = $jobs_data['data'];
$meta = $jobs_data['meta'];

// Toplam yük sayısını ve sayfa sayısını hesapla
$total_jobs = $meta['total'] ?? count($all_jobs);
$total_pages = ceil($total_jobs / $per_page);

// Sayfa numarasını toplam sayfa sayısıyla sınırlandır
$page = min($page, max(1, $total_pages));

// Geçerli sayfanın yüklerini seç (istemci tarafında sayfalama)
$jobs = array_slice($all_jobs, ($page - 1) * $per_page, $per_page);

// İstatistikleri hesapla (tüm yükler için)
$job_stats = calculateJobStats($all_jobs, $meta);

// Teslimatları tarihe göre sırala
usort($jobs, function($a, $b) {
    return strtotime($b['completed_at']) - strtotime($a['completed_at']);
});

// Mesajlar
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $vtc_name; ?> - Teslimatlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --theme-color: <?php echo $settings['theme_color']; ?>;
            --font-size: <?php echo $settings['font_size']; ?>px;
        }

        /* Genel Tasarım Ayarları */
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

        /* Header ve Navigasyon */
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

        /* Dropdown Menü */
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

        /* Kartlar */
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
            display: flex;
            align-items: center;
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

        /* İstatistik Kartları */
        .stats-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%; /* Tüm kartlar aynı yükseklikte olsun */
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

        /* Bölüm Başlıkları */
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e212d;
            margin-bottom: 30px;
            text-align: center;
        }

        /* Modal Stilleri */
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
        .modal-body strong {
            color: #333;
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

        /* Footer */
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
        }
        .footer .social-icons a {
            font-size: 1.8rem;
            margin: 0 10px;
        }

        /* Scroll to Top */
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

        /* Sayfalama Linkleri için Stil */
        .pagination-links {
            text-align: center;
            margin-top: 20px;
            z-index: 1;
        }
        .pagination-links a {
            color: var(--theme-color);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 15px;
            margin: 0 5px;
            border-radius: 5px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        .pagination-links a:hover {
            background-color: var(--theme-color);
            color: #fff;
            transform: translateY(-2px);
        }
        .pagination-links .active {
            background-color: var(--theme-color);
            color: #fff;
            font-weight: bold;
        }
        .pagination-links .disabled {
            color: #999;
            pointer-events: none;
            cursor: not-allowed;
        }

        /* Mesaj Stilleri */
        .error-message, .success-message {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }
        .success-message {
            background-color: #d4edda;
            color: #155724;
        }

        /* Responsive Ayarlar */
        @media (max-width: 768px) {
            .card-custom {
                padding: 15px;
            }
            .modal-dialog {
                margin: 10px;
            }
            .table-custom th, .table-custom td {
                font-size: 0.85rem;
                padding: 8px;
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
            .navbar-brand {
                font-size: 1.5rem;
            }
            .navbar-brand img {
                width: 35px;
                height: 35px;
            }
            .social-icons a {
                font-size: 1.4rem;
                margin-left: 10px;
            }
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
            .stats-card {
                padding: 10px;
            }
            .stats-card h4 {
                font-size: 0.9rem;
            }
            .stats-card p {
                font-size: 1rem;
            }
            .stats-icon {
                font-size: 1.6rem;
            }
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
            <a class="navbar-brand" href="dashboard.php">
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
                    <li class="nav-item dropdown">
                        <a class="nav-link user-dropdown dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-user-circle"></i> <?php echo $username; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#accountModal"><i class="fa fa-user"></i> Hesabım</a></li>
                            <li><a class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) === 'announcements.php' ? 'active' : ''; ?>" href="announcements.php"><i class="fa fa-bullhorn"></i> Duyurular</a></li>
                            <li><a class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) === 'ayarlar.php' ? 'active' : ''; ?>" href="ayarlar.php"><i class="fa fa-cog"></i> Ayarlar</a></li>
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
        <!-- Mesajlar -->
        <?php if ($success): ?>
            <div class="success-message">
                <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fa fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- İstatistik Kutucukları -->
        <h3 class="section-title">Teslimat İstatistikleri</h3>
        <div class="row g-4">
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-road stats-icon"></i>
                    <h4>Toplam Mesafe</h4>
                    <p><?php echo number_format($job_stats['total_km'], 0, ',', '.') . ' km'; ?></p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-truck stats-icon"></i>
                    <h4>Teslim Edilen İş</h4>
                    <p><?php echo number_format($job_stats['job_count'], 0, ',', '.'); ?></p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-tachometer stats-icon"></i>
                    <h4>En Uzun Mesafe</h4>
                    <p><?php echo number_format($job_stats['max_distance'], 0, ',', '.') . ' km'; ?></p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-map-signs stats-icon"></i>
                    <h4>Ortalama Mesafe</h4>
                    <p><?php echo number_format($job_stats['avg_distance'], 0, ',', '.') . ' km'; ?></p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-clock-o stats-icon"></i>
                    <h4>En Uzun Süre</h4>
                    <p><?php echo formatDuration($job_stats['max_duration']); ?></p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stats-card">
                    <i class="fa fa-hourglass stats-icon"></i>
                    <h4>Ortalama Süre</h4>
                    <p><?php echo formatDuration($job_stats['avg_duration']); ?></p>
                </div>
            </div>
        </div>

        <!-- Teslimatlar Tablosu -->
        <h3 class="section-title">Teslimatlar</h3>
        <div class="card-custom">
            <h3><i class="fa fa-truck"></i> Tüm Teslimatlar</h3>
            <?php if (empty($jobs)): ?>
                <p class="text-center"><i class="fa fa-exclamation-triangle"></i> Teslimat verisi bulunamadı. Lütfen Trucky API anahtarını, Company ID'yi kontrol edin veya yüklerin tamamlandığından emin olun.</p>
            <?php else: ?>
                <table class="table table-custom table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Sürücü</th>
                            <th>Nereden</th>
                            <th>Nereye</th>
                            <th>Mesafe</th>
                            <th>Yük Tipi ve Ağırlığı</th>
                            <th>Ücret</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr class="job-row" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#jobModal" data-job='<?php echo htmlspecialchars(json_encode($job)); ?>'>
                                <td><?php echo htmlspecialchars($job['id'] ?? 'Bilinmiyor'); ?></td>
                                <td><?php echo htmlspecialchars($job['driver']['name'] ?? 'Bilinmiyor'); ?></td>
                                <td><?php echo htmlspecialchars($job['source_city_name'] ?? 'Bilinmiyor') . ' (' . htmlspecialchars($job['source_company_name'] ?? 'Bilinmiyor') . ')'; ?></td>
                                <td><?php echo htmlspecialchars($job['destination_city_name'] ?? 'Bilinmiyor') . ' (' . htmlspecialchars($job['destination_company_name'] ?? 'Bilinmiyor') . ')'; ?></td>
                                <td><?php echo number_format($job['driven_distance_km'] ?? $job['planned_distance_km'] ?? 0, 0, ',', '.') . ' km'; ?></td>
                                <td><?php echo htmlspecialchars($job['cargo_name'] ?? 'Bilinmiyor') . ' (' . number_format($job['cargo_mass_t'] ?? 0, 0, ',', '.') . ' ton)'; ?></td>
                                <td><?php echo number_format($job['income'] ?? 0, 0, ',', '.') . ' €'; ?></td>
                                <td><?php echo $job['completed_at'] ? strftime('%d %B %Y %H:%M', strtotime($job['completed_at'])) : 'Bilinmiyor'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Sayfalama Linkleri -->
                <div class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="pagination-link"><i class="fa fa-arrow-left"></i> Önceki</a>
                    <?php else: ?>
                        <span class="disabled"><i class="fa fa-arrow-left"></i> Önceki</span>
                    <?php endif; ?>

                    <!-- Sayfa numaralarını göster -->
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="pagination-link">Sonraki <i class="fa fa-arrow-right"></i></a>
                    <?php else: ?>
                        <span class="disabled">Sonraki <i class="fa fa-arrow-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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

    <!-- Teslimat Detay Modal -->
    <div class="modal fade" id="jobModal" tabindex="-1" aria-labelledby="jobModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="jobModalLabel">Teslimat Detayları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p><strong><i class="fa fa-hashtag"></i> Teslimat No:</strong> <span id="job_id"></span></p>
                    <p><strong><i class="fa fa-user"></i> Sürücü:</strong> <span id="driver_name"></span></p>
                    <p><strong><i class="fa fa-map-marker"></i> Nereden:</strong> <span id="source"></span></p>
                    <p><strong><i class="fa fa-map-marker"></i> Nereye:</strong> <span id="destination"></span></p>
                    <p><strong><i class="fa fa-road"></i> Mesafe:</strong> <span id="driven_distance"></span></p>
                    <p><strong><i class="fa fa-cubes"></i> Yük Tipi ve Ağırlığı:</strong> <span id="cargo"></span></p>
                    <p><strong><i class="fa fa-money"></i> Ücret:</strong> <span id="income"></span></p>
                    <p><strong><i class="fa fa-calendar"></i> Tarih:</strong> <span id="completed_at"></span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" integrity="sha384-w76AqPfDkMBDXo30jS1Sgez6pr3x5MlQ1ZAGC+nuZB+EYdgRZgiwxhTBTkF7CXvN" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        // Modal verilerini doldurma
        $(document).ready(function() {
            $('.job-row').click(function(e) {
                e.preventDefault(); // Varsayılan davranışı engelle
                try {
                    const job = JSON.parse($(this).attr('data-job'));
                    $('#job_id').text(job.id || 'Bilinmiyor');
                    $('#driver_name').text(job.driver?.name || 'Bilinmiyor');
                    $('#source').text((job.source_city_name || 'Bilinmiyor') + ' (' + (job.source_company_name || 'Bilinmiyor') + ')');
                    $('#destination').text((job.destination_city_name || 'Bilinmiyor') + ' (' + (job.destination_company_name || 'Bilinmiyor') + ')');
                    $('#driven_distance').text((job.driven_distance_km || job.planned_distance_km || '0') + ' km');
                    $('#cargo').text((job.cargo_name || 'Bilinmiyor') + ' (' + (job.cargo_mass_t ? parseInt(job.cargo_mass_t).toLocaleString('tr-TR') : '0') + ' ton)');
                    $('#income').text(job.income ? parseInt(job.income).toLocaleString('tr-TR') + ' €' : '0 €');
                    $('#completed_at').text(job.completed_at ? new Date(job.completed_at).toLocaleString('tr-TR', { timeZone: 'Europe/Istanbul', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Bilinmiyor');
                } catch (e) {
                    console.error('JSON parse hatası:', e, 'Veri:', $(this).attr('data-job'));
                    $('#jobModal .modal-body').html('<p class="text-center text-danger"><i class="fa fa-exclamation-triangle"></i> Veri yüklenemedi. Lütfen tekrar deneyin.</p>');
                }
            });

            // Dropdown menüsünü hover ile açma
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