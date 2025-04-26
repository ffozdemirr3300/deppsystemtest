<?php
session_start();
require_once dirname(__DIR__) . '/auth.php';
checkAuth('events.php');

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
        error_log("events.php - permissions.json geçersiz JSON: $permissions_file");
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

// TruckersMP API'den VTC bilgileri ve etkinlikler
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

// VTC bilgileri
curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$settings['vtc_id']}");
$response_vtc = curl_exec($ch);
$http_code_vtc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$data_vtc = $http_code_vtc === 200 ? json_decode($response_vtc, true) : ['response' => []];
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_cover = $data_vtc['response']['cover'] ?? 'https://via.placeholder.com/1200x400';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';

// Sosyal medya bağlantıları
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// Etkinlik verileri
curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$settings['vtc_id']}/events/attending");
$response_events_attending = curl_exec($ch);
$http_code_events = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$data_events_attending = $http_code_events === 200 ? json_decode($response_events_attending, true) : ['response' => []];
curl_close($ch);
$attending_events = $data_events_attending['response'] ?? [];

// Gelecekteki etkinlikler
$future_events = [];
$current_time = time();
foreach ($attending_events as $event) {
    $event_start_time = strtotime($event['start_at']);
    if ($event_start_time > $current_time) {
        $future_events[] = $event;
    }
}

// Takvim için ay ve yıl
$current_month = date('n'); // Nisan = 4
$current_year = date('Y'); // 2025
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $current_month, $current_year);
$first_day_of_month = date('w', mktime(0, 0, 0, $current_month, 1, $current_year)); // Ayın ilk günü hangi gün?

// Etkinlikleri tarihlere göre gruplandırma
$events_by_date = [];
foreach ($future_events as $event) {
    $event_date = date('j', strtotime($event['start_at'])); // Gün numarası (1-31)
    $event_month = date('n', strtotime($event['start_at']));
    $event_year = date('Y', strtotime($event['start_at']));
    if ($event_month == $current_month && $event_year == $current_year) {
        $events_by_date[$event_date][] = $event;
    }
}

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
    <title><?php echo $vtc_name; ?> - Etkinlikler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
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
        .social-icons {
            display: flex;
            align-items: center;
        }
        .social-icons a {
            color: #fff;
            font-size: 1.6rem;
            margin: 0 10px;
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
        .calendar {
            background-color: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .calendar-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .calendar-day.empty {
            background-color: transparent;
        }
        .calendar-day.header {
            font-weight: 700;
            color: #333;
            background-color: #f8f9fa;
        }
        .calendar-day.event {
            background-color: var(--theme-color);
            color: #fff;
            cursor: pointer;
        }
        .calendar-day.event:hover {
            background-color: #e65c00;
            transform: scale(1.05);
        }
        .calendar-day.today {
            border: 2px solid var(--theme-color);
        }
        .event-list {
            margin-top: 30px;
        }
        .event-item {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }
        .event-item:hover {
            transform: translateY(-3px);
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .calendar, .card-custom {
            animation: fadeInUp 0.6s ease-in-out;
        }
        @media (max-width: 768px) {
            .calendar-day {
                font-size: 0.9rem;
            }
            .calendar-header h3 {
                font-size: 1.5rem;
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
                margin: 0 8px;
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
            .calendar-day {
                font-size: 0.8rem;
            }
            .calendar-header h3 {
                font-size: 1.3rem;
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
                        <a href="<?php echo $twitter; ?>" target="_blank"><i class="fa fa-twitter"></i></ Liss
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

        <h3 class="section-title">Yaklaşan Etkinlikler</h3>
        <div class="calendar">
            <div class="calendar-header">
                <button class="btn btn-custom" onclick="changeMonth(-1)">← Önceki</button>
                <h3><?php echo strftime('%B %Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></h3>
                <button class="btn btn-custom" onclick="changeMonth(1)">Sonraki →</button>
            </div>
            <div class="calendar-grid">
                <!-- Gün başlıkları -->
                <div class="calendar-day header">Paz</div>
                <div class="calendar-day header">Pzt</div>
                <div class="calendar-day header">Sal</div>
                <div class="calendar-day header">Çar</div>
                <div class="calendar-day header">Per</div>
                <div class="calendar-day header">Cum</div>
                <div class="calendar-day header">Cmt</div>
                <!-- Boş günler -->
                <?php for ($i = 0; $i < $first_day_of_month; $i++): ?>
                    <div class="calendar-day empty"></div>
                <?php endfor; ?>
                <!-- Ayın günleri -->
                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                    <div class="calendar-day <?php echo isset($events_by_date[$day]) ? 'event' : ''; ?> <?php echo $day == date('j') && $current_month == date('n') && $current_year == date('Y') ? 'today' : ''; ?>" 
                         <?php if (isset($events_by_date[$day])): ?>
                             data-bs-toggle="modal" 
                             data-bs-target="#eventModal" 
                             onclick='showEventDetails(<?php echo htmlspecialchars(json_encode($events_by_date[$day])); ?>)'
                         <?php endif; ?>>
                        <?php echo $day; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Etkinlik Listesi -->
        <div class="event-list">
            <h4 class="section-title">Etkinlik Listesi</h4>
            <?php if (empty($future_events)): ?>
                <p class="text-center">Yaklaşan etkinlik bulunmamaktadır.</p>
            <?php else: ?>
                <?php foreach ($future_events as $event): ?>
                    <div class="event-item" 
                         data-bs-toggle="modal" 
                         data-bs-target="#eventModal" 
                         onclick='showEventDetails([<?php echo htmlspecialchars(json_encode($event)); ?>])'>
                        <h5><i class="fa fa-calendar-check-o"></i> <?php echo htmlspecialchars($event['name']); ?></h5>
                        <p><i class="fa fa-tags"></i> <strong>Tip:</strong> <?php echo htmlspecialchars($event['event_type']['name']); ?></p>
                        <p><i class="fa fa-clock-o"></i> <strong>Başlangıç:</strong> <?php echo strftime('%d %B %Y Saat %H:%M', strtotime($event['start_at'])); ?></p>
                        <p><i class="fa fa-map-marker"></i> <strong>Lokasyon:</strong> <?php echo htmlspecialchars($event['departure']['city']); ?> → <?php echo htmlspecialchars($event['arrive']['city']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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

        <!-- Etkinlik Detay Modal -->
        <div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eventModalLabel">Etkinlik Detayları</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body" id="eventModalBody">
                        <p class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <a id="eventDetailsLink" href="#" class="btn btn-custom" target="_blank">Etkinlik Detayları</a>
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

        // Etkinlik detaylarını gösterme
        function showEventDetails(events) {
            const modalBody = document.getElementById('eventModalBody');
            const eventDetailsLink = document.getElementById('eventDetailsLink');
            let content = '';
            events.forEach(event => {
                content += `
                    <div class="event-detail">
                        <h5><i class="fa fa-calendar-check-o"></i> ${event.name || 'Bilinmiyor'}</h5>
                        <p><i class="fa fa-tags"></i> <strong>Tip:</strong> ${event.event_type?.name || 'Bilinmiyor'}</p>
                        <p><i class="fa fa-clock-o"></i> <strong>Başlangıç:</strong> ${event.start_at ? new Date(event.start_at).toLocaleString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Bilinmiyor'}</p>
                        <p><i class="fa fa-map-marker"></i> <strong>Lokasyon:</strong> ${event.departure?.city || 'Bilinmiyor'} → ${event.arrive?.city || 'Bilinmiyor'}</p>
                        <p><i class="fa fa-users"></i> <strong>Katılım:</strong> ${event.attendances_count || 0} kişi</p>
                        <hr>
                    </div>
                `;
                // İlk etkinliğin URL'sini detay butonuna ata (birden fazla etkinlik varsa ilkini kullan)
                if (event.url) {
                    eventDetailsLink.href = `https://truckersmp.com${event.url}`;
                    eventDetailsLink.classList.remove('disabled');
                } else {
                    eventDetailsLink.href = '#';
                    eventDetailsLink.classList.add('disabled');
                }
            });
            modalBody.innerHTML = content;
        }

        // Ay değiştirme
        let currentMonth = <?php echo $current_month; ?>;
        let currentYear = <?php echo $current_year; ?>;
        function changeMonth(offset) {
            currentMonth += offset;
            if (currentMonth < 1) {
                currentMonth = 12;
                currentYear--;
            } else if (currentMonth > 12) {
                currentMonth = 1;
                currentYear++;
            }
            // AJAX ile yeni takvim verilerini yükle
            fetch(`fetch_calendar.php?month=${currentMonth}&year=${currentYear}&vtc_id=<?php echo $settings['vtc_id']; ?>`)
                .then(response => response.json())
                .then(data => {
                    updateCalendar(data);
                })
                .catch(error => {
                    console.error('Hata:', error);
                    alert('Takvim yüklenemedi.');
                });
        }

        // Takvimi güncelleme
        function updateCalendar(data) {
            const calendarGrid = document.querySelector('.calendar-grid');
            const calendarHeader = document.querySelector('.calendar-header h3');
            calendarHeader.textContent = new Date(currentYear, currentMonth - 1).toLocaleString('tr-TR', { month: 'long', year: 'numeric' });

            // Takvimi sıfırla
            calendarGrid.innerHTML = `
                <div class="calendar-day header">Paz</div>
                <div class="calendar-day header">Pzt</div>
                <div class="calendar-day header">Sal</div>
                <div class="calendar-day header">Çar</div>
                <div class="calendar-day header">Per</div>
                <div class="calendar-day header">Cum</div>
                <div class="calendar-day header">Cmt</div>
            `;

            // Boş günler
            for (let i = 0; i < data.first_day; i++) {
                calendarGrid.innerHTML += `<div class="calendar-day empty"></div>`;
            }

            // Günler
            for (let day = 1; day <= data.days_in_month; day++) {
                const events = data.events_by_date[day] || [];
                const isToday = day == <?php echo date('j'); ?> && currentMonth == <?php echo date('n'); ?> && currentYear == <?php echo date('Y'); ?>;
                calendarGrid.innerHTML += `
                    <div class="calendar-day ${events.length > 0 ? 'event' : ''} ${isToday ? 'today' : ''}"
                         ${events.length > 0 ? `data-bs-toggle="modal" data-bs-target="#eventModal" onclick='showEventDetails(${JSON.stringify(events)})'` : ''}>
                        ${day}
                    </div>
                `;
            }

            // Etkinlik listesini güncelle
            const eventList = document.querySelector('.event-list');
            eventList.innerHTML = '<h4 class="section-title">Etkinlik Listesi</h4>';
            if (data.future_events.length === 0) {
                eventList.innerHTML += '<p class="text-center">Yaklaşan etkinlik bulunmamaktadır.</p>';
            } else {
                data.future_events.forEach(event => {
                    eventList.innerHTML += `
                        <div class="event-item" 
                             data-bs-toggle="modal" 
                             data-bs-target="#eventModal" 
                             onclick='showEventDetails([${JSON.stringify(event)}])'>
                            <h5><i class="fa fa-calendar-check-o"></i> ${event.name || 'Bilinmiyor'}</h5>
                            <p><i class="fa fa-tags"></i> <strong>Tip:</strong> ${event.event_type?.name || 'Bilinmiyor'}</p>
                            <p><i class="fa fa-clock-o"></i> <strong>Başlangıç:</strong> ${event.start_at ? new Date(event.start_at).toLocaleString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Bilinmiyor'}</p>
                            <p><i class="fa fa-map-marker"></i> <strong>Lokasyon:</strong> ${event.departure?.city || 'Bilinmiyor'} → ${event.arrive?.city || 'Bilinmiyor'}</p>
                        </div>
                    `;
                });
            }
        }

        // Dropdown menüsünü hover ile açma
        $(document).ready(function() {
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