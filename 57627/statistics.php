<?php
require_once dirname(__DIR__) . '/auth.php';
checkAuth('dashboard.php');

// Oturum zaten auth.php içinde başlatılıyor, burada session_start() gerekmiyor

// Kullanıcı bilgilerini al
$user = $_SESSION['user'];
$steam_id = $user['steam_id'];
$username = htmlspecialchars($user['username']);
$vtc_id = $user['vtc_id'];
$role = $user['role'];

// Türkçe dil ayarları
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
    file_put_contents($settings_file, json_encode($default_settings));
}

// Trucky API'den veri çekme fonksiyonu
function fetchTruckyData($endpoint, $token, $company_id) {
    $url = "https://e.truckyapp.com/api/v1/$endpoint";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "x-access-token: $token",
        "Accept: application/json",
        "Content-Type: application/json",
        "User-Agent: YourVTCName"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("cURL Hatası ($endpoint): $error");
        return ['error' => "API bağlantı hatası: $error"];
    }
    if ($http_code !== 200) {
        $error_message = "HTTP $http_code";
        $decoded_response = json_decode($response, true);
        if ($decoded_response && isset($decoded_response['error'])) {
            $error_message .= ": " . $decoded_response['error'];
        }
        error_log("API Hata ($endpoint): $error_message, Yanıt: $response");
        return ['error' => "API hatası: $error_message"];
    }

    $data = json_decode($response, true);
    if (!$data) {
        error_log("Geçersiz JSON yanıtı ($endpoint): $response");
        return ['error' => "Geçersiz JSON yanıtı"];
    }

    error_log("API Yanıtı ($endpoint): " . json_encode($data, JSON_PRETTY_PRINT));

    if (isset($data['response'])) {
        return ['data' => $data['response']];
    } elseif (isset($data['data'])) {
        return ['data' => $data['data']];
    } elseif (isset($data['company'])) {
        return ['data' => $data['company']];
    } elseif (isset($data['jobs'])) {
        return ['data' => $data['jobs']];
    } elseif (isset($data['members'])) {
        return ['data' => $data['members']];
    } elseif (is_array($data)) {
        return ['data' => $data];
    } else {
        error_log("Beklenmeyen API yanıt formatı ($endpoint): " . json_encode($data));
        return ['error' => "API yanıtı beklenen formatta değil"];
    }
}

// API'den verileri çek
$trucky_token = $settings['trucky_api_key'];
$company_id = $settings['trucky_company_id'];
$api_error = null;

if (empty($trucky_token) || empty($company_id)) {
    $api_error = "Trucky API anahtarı veya şirket ID'si eksik. Lütfen settings.json dosyasını kontrol edin.";
    error_log("Hata: Trucky API anahtarı veya şirket ID'si eksik.");
    $company_stats = $members = $jobs = [];
} else {
    $company_data = fetchTruckyData("company/$company_id", $trucky_token, $company_id);
    if (isset($company_data['error'])) {
        $api_error = $company_data['error'];
        $company_stats = [];
    } else {
        $company_stats = $company_data['data'] ?? [];
    }

    $members_data = fetchTruckyData("company/$company_id/members", $trucky_token, $company_id);
    if (isset($members_data['error'])) {
        $api_error = $api_error ?? $members_data['error'];
        $members = [];
    } else {
        $members = $members_data['data'] ?? [];
        $members = is_array($members) ? $members : [$members];
    }

    $jobs_data = fetchTruckyData("company/$company_id/jobs?status=completed", $trucky_token, $company_id);
    if (isset($jobs_data['error'])) {
        $api_error = $api_error ?? $jobs_data['error'];
        $jobs = [];
    } else {
        $jobs = $jobs_data['data'] ?? [];
        $jobs = is_array($jobs) ? $jobs : [$jobs];
    }
}

// İstatistikler
$total_members = count($members);
$total_jobs = count($jobs);
$total_distance = 0;
$total_revenue = 0;
$total_fuel_consumed = 0;
$total_cargo_weight = 0;
$cargo_types = [];

foreach ($jobs as $job) {
    $distance = floatval($job['driven_distance_km'] ?? $job['planned_distance_km'] ?? $job['distance'] ?? $job['distance_km'] ?? 0);
    $revenue = floatval($job['income'] ?? $job['revenue'] ?? $job['earnings'] ?? 0);
    $fuel = floatval($job['fuel_consumed'] ?? $job['fuel'] ?? $job['fuel_used'] ?? 0);
    $weight = floatval($job['cargo_weight'] ?? $job['weight'] ?? 0);
    $cargo = $job['cargo_name'] ?? $job['cargo'] ?? $job['cargo_type'] ?? 'Bilinmeyen';

    $total_distance += $distance;
    $total_revenue += $revenue;
    $total_fuel_consumed += $fuel;
    $total_cargo_weight += $weight;
    $cargo_types[$cargo] = true;
}

// Toplam kargo türü çeşitliliği
$total_cargo_types = count($cargo_types);

// Ortalama iş mesafesi
$average_distance = $total_jobs > 0 ? $total_distance / $total_jobs : 0;

// Aylık iş sayısı (son 6 ay)
$monthly_jobs = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthly_jobs[$month] = 0;
}
foreach ($jobs as $job) {
    $job_month = date('Y-m', strtotime($job['completed_at'] ?? $job['created_at'] ?? $job['date'] ?? 'now'));
    if (isset($monthly_jobs[$job_month])) {
        $monthly_jobs[$job_month]++;
    }
}

// En popüler kalkış şehirleri
$departure_cities = [];
foreach ($jobs as $job) {
    $city = $job['source_city_name'] ?? $job['source_city'] ?? $job['from_city'] ?? 'Bilinmiyor';
    $departure_cities[$city] = ($departure_cities[$city] ?? 0) + 1;
}
arsort($departure_cities);
$top_departure_cities = array_slice($departure_cities, 0, 5, true);

// En popüler varış şehirleri
$destination_cities = [];
foreach ($jobs as $job) {
    $city = $job['destination_city_name'] ?? $job['destination_city'] ?? 'Bilinmiyor';
    $destination_cities[$city] = ($destination_cities[$city] ?? 0) + 1;
}
arsort($destination_cities);
$top_destination_cities = array_slice($destination_cities, 0, 5, true);

// En iyi sürücüler (mesafe bazında)
$driver_distances = [];
foreach ($members as $member) {
    $driver_jobs = array_filter($jobs, function($job) use ($member) {
        $job_driver_id = $job['driver']['id'] ?? $job['user_id'] ?? $job['driver_id'] ?? null;
        $member_id = $member['id'] ?? $member['user_id'] ?? null;
        return $job_driver_id === $member_id;
    });
    $distance = 0;
    foreach ($driver_jobs as $job) {
        $distance += floatval($job['driven_distance_km'] ?? $job['planned_distance_km'] ?? $job['distance'] ?? $job['distance_km'] ?? 0);
    }
    $driver_name = $member['name'] ?? $member['username'] ?? $member['display_name'] ?? 'Bilinmeyen';
    $driver_distances[$driver_name] = $distance;
}
arsort($driver_distances);
$top_drivers = array_slice($driver_distances, 0, 5, true);

// TruckersMP VTC bilgileri
$url_vtc = 'https://api.truckersmp.com/v2/vtc/' . $settings['vtc_id'];
$response_vtc = @file_get_contents($url_vtc);
$data_vtc = $response_vtc ? json_decode($response_vtc, true) : ['response' => []];
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $settings['header_text'] ?: $vtc_name; ?> - İstatistikler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e212d;
            margin-bottom: 30px;
            text-align: center;
        }
        .stat-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card h3 {
            font-size: 2.5rem;
            color: var(--theme-color);
            margin: 10px 0;
        }
        .stat-card p {
            font-size: 1.1rem;
            color: #555;
        }
        .chart-container {
            background-color: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stat-card, .chart-container {
            animation: fadeInUp 0.6s ease-in-out;
        }
        @media (max-width: 768px) {
            .stat-card h3 {
                font-size: 2rem;
            }
            .stat-card p {
                font-size: 1rem;
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
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'ayarlar.php' ? 'active' : ''; ?>" href="ayarlar.php">Ayarlar</a>
                    </li>
                    <?php if ($role === 'owner' or 'superadmin') { ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'permissions.php' ? 'active' : ''; ?>" href="permissions.php">Yetki Yönetimi</a>
                        </li>
                    <?php } ?>
                    <!-- Kullanıcı Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link user-dropdown dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-user-circle"></i> <?php echo $username; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#accountModal"><i class="fa fa-user"></i> Hesabım</a></li>
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

    <!-- İçerik -->
    <div class="container container-custom">
        <h3 class="section-title">VTC İstatistikler</h3>

        <!-- Hata Mesajı -->
        <?php if ($api_error): ?>
            <div class="error-message">
                <i class="fa fa-exclamation-triangle"></i> Veriler yüklenemedi: <?php echo htmlspecialchars($api_error); ?>
                <br><small>Lütfen Trucky API anahtarını ve şirket ID'sini kontrol edin veya Trucky destek ile iletişime geçin.</small>
            </div>
        <?php endif; ?>

        <!-- Genel İstatistik Kartları -->
        <div class="row g-4 mb-5">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-users fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo $total_members; ?></h3>
                    <p>Toplam Sürücü</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-truck fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo $total_jobs; ?></h3>
                    <p>Toplam İş</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-road fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo number_format($total_distance, 0, ',', '.'); ?> km</h3>
                    <p>Toplam Mesafe</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-cubes fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo $total_cargo_types; ?></h3>
                    <p>Kargo Türü</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-tachometer fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo number_format($average_distance, 0, ',', '.'); ?> km</h3>
                    <p>Ort. Mesafe</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-fire fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo number_format($total_fuel_consumed, 0, ',', '.'); ?> L</h3>
                    <p>Toplam Yakıt</p>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card">
                    <i class="fa fa-money fa-2x" style="color: var(--theme-color);"></i>
                    <h3><?php echo number_format($total_revenue, 0, ',', '.'); ?> €</h3>
                    <p>Toplam Gelir</p>
                </div>
            </div>
        </div>

        <!-- Grafikler -->
        <div class="row g-4 mb-5">
            <div class="col-md-6">
                <div class="chart-container">
                    <h4 class="text-center mb-4">Aylık İş Sayısı</h4>
                    <canvas id="monthlyJobsChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h4 class="text-center mb-4">En İyi Sürücüler (Mesafe)</h4>
                    <canvas id="topDriversChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h4 class="text-center mb-4">En Popüler Kalkış Şehirleri</h4>
                    <canvas id="departureCitiesChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h4 class="text-center mb-4">En Popüler Varış Şehirleri</h4>
                    <canvas id="destinationCitiesChart"></canvas>
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

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Aylık İş Sayısı Grafiği (Çubuk)
        const monthlyJobsChart = new Chart(document.getElementById('monthlyJobsChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($monthly_jobs)); ?>,
                datasets: [{
                    label: 'İş Sayısı',
                    data: <?php echo json_encode(array_values($monthly_jobs)); ?>,
                    backgroundColor: 'var(--theme-color)',
                    borderColor: 'var(--theme-color)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // En İyi Sürücüler Grafiği (Yatay Çubuk)
        const topDriversChart = new Chart(document.getElementById('topDriversChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($top_drivers)); ?>,
                datasets: [{
                    label: 'Toplam Mesafe (km)',
                    data: <?php echo json_encode(array_values($top_drivers)); ?>,
                    backgroundColor: 'var(--theme-color)',
                    borderColor: 'var(--theme-color)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true }
                }
            }
        });

        // En Popüler Kalkış Şehirleri Grafiği (Pasta)
        const departureCitiesChart = new Chart(document.getElementById('departureCitiesChart'), {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_keys($top_departure_cities)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($top_departure_cities)); ?>,
                    backgroundColor: ['#ff6600', '#e65c00', '#cc5200', '#b34700', '#993d00']
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // En Popüler Varış Şehirleri Grafiği (Pasta)
        const destinationCitiesChart = new Chart(document.getElementById('destinationCitiesChart'), {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_keys($top_destination_cities)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($top_destination_cities)); ?>,
                    backgroundColor: ['#ff6600', '#e65c00', '#cc5200', '#b34700', '#993d00']
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>