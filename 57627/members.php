<?php
require_once dirname(__DIR__) . '/auth.php';
checkAuth('members.php');

// Kullanıcı bilgilerini al
$user = $_SESSION['user'];
$steam_id = $user['steam_id'];
$username = htmlspecialchars($user['username']);
$vtc_id = $user['vtc_id'];
$role = $user['role'];

// Türkçe dil ayarını yap
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

// TruckersMP API'den VTC bilgileri ve üyeler
$api_base_url = 'https://api.truckersmp.com/v2';
$vtc_id = $settings['vtc_id'];
$url_vtc = "$api_base_url/vtc/$vtc_id/";
$url_members = "$api_base_url/vtc/$vtc_id/members";

// VTC bilgileri
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_vtc);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
$response_vtc = curl_exec($ch);
$http_code_vtc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data_vtc = $http_code_vtc === 200 ? json_decode($response_vtc, true) : ['response' => []];
if ($http_code_vtc !== 200) {
    error_log("members.php - TruckersMP VTC API hatası: HTTP $http_code_vtc, Yanıt: " . $response_vtc);
}
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmiyor';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_cover = $data_vtc['response']['cover'] ?? 'https://via.placeholder.com/1200x400';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// Üye bilgileri
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_members);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
$response_members = curl_exec($ch);
$http_code_members = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data_members = $http_code_members === 200 ? json_decode($response_members, true) : ['response' => ['members' => []]];
if ($http_code_members !== 200) {
    error_log("members.php - TruckersMP Members API hatası: HTTP $http_code_members, Yanıt: " . $response_members);
}
$members = $data_members['response']['members'];

// Her üye için SteamID64'ü /player/{user_id} uç noktasından çek
$steam_ids = [];
if (!empty($members)) {
    $mh = curl_multi_init();
    $curl_handles = [];
    foreach ($members as $index => $member) {
        $user_id = $member['user_id'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$api_base_url/player/$user_id");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
        curl_multi_add_handle($mh, $ch);
        $curl_handles[$index] = $ch;
    }

    // Tüm istekleri çalıştır
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // Yanıtları işle
    foreach ($curl_handles as $index => $ch) {
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code === 200) {
            $player_data = json_decode($response, true);
            $steam_id64 = $player_data['response']['steamID64'] ?? null;
            if ($steam_id64 && preg_match('/^\d{17}$/', $steam_id64)) {
                $steam_ids[$members[$index]['user_id']] = $steam_id64;
                $members[$index]['steamID64'] = $steam_id64;
                error_log("members.php - SteamID64 alındı: user_id={$members[$index]['user_id']}, steamID64=$steam_id64");
            } else {
                error_log("members.php - Geçersiz SteamID64: user_id={$members[$index]['user_id']}, Yanıt: " . $response);
                $members[$index]['steamID64'] = null;
                $members[$index]['error'] = 'Steam profili alınamadı';
            }
        } else {
            error_log("members.php - Player API hatası: user_id={$members[$index]['user_id']}, HTTP $http_code, Yanıt: " . $response);
            $members[$index]['steamID64'] = null;
            $members[$index]['error'] = 'Steam profili alınamadı';
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

// Üye katılım tarihlerini düzenle
foreach ($members as &$member) {
    if (isset($member['joinDate']) && $member['joinDate'] !== null) {
        $member['joinDate_formatted'] = strftime('%d %B %Y Saat %H:%M', strtotime($member['joinDate']));
    } else {
        $member['joinDate_formatted'] = 'Tarih bulunamadı';
    }
}
unset($member); // Referansı temizle
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $vtc_name; ?> - Üyeler</title>
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
        .card-custom img {
            border-radius: 50%;
            width: 60px;
            height: 60px;
            border: 2px solid var(--theme-color);
        }
        .card-custom h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        .card-custom h5 {
            font-size: 1.3rem;
            margin: 10px 0;
            font-weight: 600;
        }
        .card-custom p {
            font-size: 0.95rem;
            color: #555;
            margin: 5px 0;
        }
        .card-custom i {
            color: var(--theme-color);
            margin-right: 8px;
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
        .modal-body .profile-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .modal-body .profile-header img {
            border: 3px solid var(--theme-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .modal-body .profile-header img:hover {
            transform: scale(1.05);
        }
        .modal-body .nav-tabs .nav-link {
            color: #555;
            font-weight: 500;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        .modal-body .nav-tabs .nav-link:hover {
            color: var(--theme-color);
            border-bottom: 2px solid var(--theme-color);
        }
        .modal-body .nav-tabs .nav-link.active {
            color: var(--theme-color);
            border-bottom: 2px solid var(--theme-color);
            background-color: transparent;
        }
        .modal-body .tab-content {
            background-color: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .modal-body .card {
            border: none;
            margin-bottom: 15px;
            background-color: #f8f9fa;
        }
        .modal-body .card-body {
            padding: 15px;
        }
        .modal-body .list-group-item {
            border: none;
            padding: 10px 0;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }
        .modal-body .list-group-item i {
            margin-right: 10px;
            color: var(--theme-color);
        }
        .modal-body .bans-hidden {
            color: var(--theme-color);
            font-style: italic;
            font-weight: 500;
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
        @media (max-width: 768px) {
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
            .card-custom {
                padding: 15px;
            }
            .modal-dialog {
                margin: 10px;
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
        <!-- Üyeler -->
        <div id="members" class="mb-5">
            <h3 class="section-title">Üyeler</h3>
            <div class="row">
                <?php foreach ($members as $member): ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="card-custom">
                            <div class="d-flex align-items-center">
                                <img src="<?php echo $vtc_logo; ?>" alt="VTC Logo">
                                <div class="ms-3">
                                    <h5>
                                        <a href="#" style="text-decoration:none; color:inherit;" 
                                           data-bs-toggle="modal" 
                                           data-bs-target="#userProfileModal" 
                                           onclick="loadMemberData(<?php echo $member['user_id']; ?>, '<?php echo $member['steamID64'] ?? ''; ?>')">
                                            <?php echo htmlspecialchars($member['username']); ?>
                                        </a>
                                    </h5>
                                    <p><i class="fa fa-calendar"></i> <?php echo htmlspecialchars($member['joinDate_formatted']); ?></p>
                                    <?php if (isset($member['error'])): ?>
                                        <p class="text-danger"><i class="fa fa-exclamation-triangle"></i> <?php echo $member['error']; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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

        <!-- Üye Profili Modal -->
        <div class="modal fade" id="userProfileModal" tabindex="-1" aria-labelledby="userProfileModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userProfileModalLabel">Üye Profili</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body" id="modalBody">
                        <p class="text-center"><i class="fa fa-spinner fa-spin"></i> Yükleniyor...</p>
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

        // Üye verilerini AJAX ile yükleme
        function loadMemberData(userId, steamID64) {
            fetch(`fetch_member.php?id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('modalBody').innerHTML = `<p class="text-center text-danger"><i class="fa fa-exclamation-triangle"></i> ${data.message}</p>`;
                        document.getElementById('userProfileModalLabel').innerText = 'Hata';
                        console.error(`Hata: ${data.message}, user_id=${userId}`);
                        return;
                    }

                    const user = data.user;
                    const bans = data.bans;
                    const vtcHistory = data.vtcHistory;
                    const events = data.events;
                    const bansHidden = data.bansHidden;

                    // SteamID64'ü öncelikli olarak üyeler listesinden al, fetch_member.php'dan geleni yedek olarak kullan
                    const finalSteamID64 = steamID64 || user.steamID64;
                    let steamLink = '<span class="text-danger">Steam profili bulunamadı</span>';
                    if (finalSteamID64 && /^\d{17}$/.test(finalSteamID64)) {
                        steamLink = `<a href="https://steamcommunity.com/profiles/${finalSteamID64}" target="_blank">Profili Görüntüle</a>`;
                        console.log(`SteamID64 doğrulandı: user_id=${userId}, steamID64=${finalSteamID64}`);
                    } else {
                        console.warn(`Geçersiz SteamID64: user_id=${userId}, steamID64=${finalSteamID64}`);
                    }

                    let modalContent = `
                        <div class="profile-header">
                            <img src="${user.avatar || 'https://via.placeholder.com/100'}" class="rounded-circle mb-3" style="width:100px; height:100px;" alt="Avatar">
                            <h4>${user.name}</h4>
                        </div>
                        <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="info-tab" data-bs-toggle="tab" href="#info" role="tab">Bilgiler</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="bans-tab" data-bs-toggle="tab" href="#bans" role="tab">Ban Geçmişi</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="vtc-tab" data-bs-toggle="tab" href="#vtc" role="tab">VTC Geçmişi</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="events-tab" data-bs-toggle="tab" href="#events" role="tab">Etkinlikler</a>
                            </li>
                        </ul>
                        <div class="tab-content mt-3" id="profileTabContent">
                            <div class="tab-pane fade show active" id="info" role="tabpanel">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="list-group list-group-flush">
                                            <div class="list-group-item">
                                                <i class="fa fa-id-badge"></i>
                                                <strong>TruckersMP ID:</strong> ${user.id}
                                            </div>
                                            <div class="list-group-item">
                                                <i class="fa fa-steam"></i>
                                                <strong>Steam:</strong> ${steamLink}
                                            </div>
                                            <div class="list-group-item">
                                                <i class="fa fa-calendar"></i>
                                                <strong>Kayıt Tarihi:</strong> ${user.joinDate ? new Date(user.joinDate).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' }) : 'Bilinmiyor'}
                                            </div>
                                            <div class="list-group-item">
                                                <i class="fa fa-ban"></i>
                                                <strong>Banlı mı?:</strong> ${user.banned ? 'Evet' : 'Hayır'}
                                            </div>
                                            <div class="list-group-item">
                                                <i class="fa fa-gavel"></i>
                                                <strong>Toplam Ban Sayısı:</strong> ${user.bansCount}
                                            </div>
                                            <div class="list-group-item">
                                                <i class="fa fa-users"></i>
                                                <strong>Grup:</strong> <span style="color: ${user.groupColor || '#000000'}">${user.groupName || 'Bilinmiyor'}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="bans" role="tabpanel">
                                <div class="card">
                                    <div class="card-body">
                                        ${bansHidden ? '<p class="bans-hidden"><i class="fa fa-lock"></i> Ban geçmişi gizli.</p>' : bans.length > 0 ? `
                                            <div class="list-group list-group-flush">
                                                ${bans.map(ban => `
                                                    <div class="list-group-item">
                                                        <i class="fa fa-gavel"></i>
                                                        <div>
                                                            <strong>Ban Tarihi:</strong> ${new Date(ban.date).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })}<br>
                                                            <strong>Ban Sebebi:</strong> ${ban.reason}<br>
                                                            <strong>Ban Bitti:</strong> ${ban.expired ? 'Evet' : 'Hayır'}
                                                        </div>
                                                    </div>
                                                `).join('')}
                                            </div>
                                        ` : '<p><i class="fa fa-check-circle"></i> Ban geçmişi bulunmamaktadır.</p>'}
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="vtc" role="tabpanel">
                                <div class="card">
                                    <div class="card-body">
                                        ${vtcHistory.length > 0 ? `
                                            <div class="list-group list-group-flush">
                                                ${vtcHistory.map(vtc => `
                                                    <div class="list-group-item">
                                                        <i class="fa fa-truck"></i>
                                                        <div>
                                                            <strong>VTC Adı:</strong> ${vtc.name}<br>
                                                            <strong>Katılım Tarihi:</strong> ${new Date(vtc.joinDate).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })}
                                                        </div>
                                                    </div>
                                                `).join('')}
                                            </div>
                                        ` : '<p><i class="fa fa-times-circle"></i> VTC geçmişi bulunmamaktadır.</p>'}
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="events" role="tabpanel">
                                <div class="card">
                                    <div class="card-body">
                                        ${events.length > 0 ? `
                                            <div class="list-group list-group-flush">
                                                ${events.map(event => `
                                                    <div class="list-group-item">
                                                        <i class="fa fa-calendar-check-o"></i>
                                                        <div>
                                                            <strong>Etkinlik Adı:</strong> ${event.name}<br>
                                                            <strong>Etkinlik Tarihi:</strong> ${new Date(event.startAt).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })}<br>
                                                            <strong>Lokasyon:</strong> ${event.city}
                                                        </div>
                                                    </div>
                                                `).join('')}
                                            </div>
                                        ` : '<p><i class="fa fa-times-circle"></i> Katıldığı etkinlik bulunmamaktadır.</p>'}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    document.getElementById('modalBody').innerHTML = modalContent;
                    document.getElementById('userProfileModalLabel').innerText = `${user.name} - Profil`;
                })
                .catch(error => {
                    document.getElementById('modalBody').innerHTML = '<p class="text-center text-danger"><i class="fa fa-exclamation-triangle"></i> Bir hata oluştu. Lütfen tekrar deneyin.</p>';
                    document.getElementById('userProfileModalLabel').innerText = 'Hata';
                    console.error(`Hata: ${error}, user_id=${userId}`);
                });
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