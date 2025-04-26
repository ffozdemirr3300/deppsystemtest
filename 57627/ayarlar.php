<?php
session_start();
require_once dirname(__DIR__) . '/auth.php';
checkAuth('ayarlar.php');

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
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}

// Ayarları kaydet
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['theme_color'] = filter_input(INPUT_POST, 'theme_color', FILTER_SANITIZE_STRING) ?? $settings['theme_color'];
    $settings['header_text'] = filter_input(INPUT_POST, 'header_text', FILTER_SANITIZE_STRING) ?? $settings['header_text'];
    $settings['footer_text'] = filter_input(INPUT_POST, 'footer_text', FILTER_SANITIZE_STRING) ?? $settings['footer_text'];
    $settings['font_size'] = filter_input(INPUT_POST, 'font_size', FILTER_VALIDATE_INT) ?? $settings['font_size'];
    $new_vtc_id = filter_input(INPUT_POST, 'vtc_id', FILTER_VALIDATE_INT) ?? $settings['vtc_id'];
    $settings['trucky_api_key'] = filter_input(INPUT_POST, 'trucky_api_key', FILTER_SANITIZE_STRING) ?? $settings['trucky_api_key'];
    $settings['trucky_company_id'] = filter_input(INPUT_POST, 'trucky_company_id', FILTER_VALIDATE_INT) ?? $settings['trucky_company_id'];

    // Doğrulama
    if (!$new_vtc_id || !$settings['trucky_company_id'] || !$settings['trucky_api_key']) {
        $error = 'VTC ID, Trucky Company ID ve Trucky API Anahtarı zorunludur.';
    } else {
        // VTC ID değiştiyse oturumu güncelle
        if ($new_vtc_id !== $settings['vtc_id']) {
            $_SESSION['user']['vtc_id'] = $new_vtc_id;
            error_log("ayarlar.php - Oturumdaki VTC ID güncellendi: Yeni=$new_vtc_id");
        }
        $settings['vtc_id'] = $new_vtc_id;

        // Ayarları kaydet
        if (file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT)) === false) {
            $error = 'Ayarlar kaydedilirken hata oluştu.';
            error_log("ayarlar.php - settings.json yazılamadı: $settings_file");
        } else {
            header('Location: ayarlar.php?success=1');
            exit;
        }
    }
}

// VTC bilgileri (navbar için)
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
if ($http_code_vtc !== 200) {
    error_log("ayarlar.php - TruckersMP VTC API hatası: HTTP $http_code_vtc, Yanıt: " . $response_vtc);
}
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $vtc_name; ?> - Ayarlar</title>
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
        .settings-form {
            background-color: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .settings-form h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        .settings-form .form-group {
            margin-bottom: 20px;
        }
        .settings-form label {
            font-weight: 500;
            margin-bottom: 5px;
            display: block;
        }
        .settings-form input, .settings-form select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        .settings-form button {
            background-color: var(--theme-color);
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .settings-form button:hover {
            background-color: #e65c00;
            transform: translateY(-2px);
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
            .settings-form {
                padding: 15px;
            }
            .settings-form h3 {
                font-size: 1.5rem;
            }
            .settings-form input, .settings-form select {
                font-size: 0.9rem;
                padding: 8px;
            }
            .settings-form button {
                padding: 8px 16px;
                font-size: 0.9rem;
            }
            .nav-item.dropdown:hover .dropdown-menu {
                display: none;
            }
        }
        @media (max-width: 576px) {
            .settings-form h3 {
                font-size: 1.3rem;
            }
            .settings-form input, .settings-form select {
                font-size: 0.85rem;
            }
            .settings-form button {
                font-size: 0.85rem;
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

    <!-- İçerik -->
    <div class="container container-custom">
        <div class="settings-form">
            <h3><i class="fa fa-cog"></i> Ayarlar</h3>
            <?php if (isset($_GET['success'])) { ?>
                <div class="alert alert-success">Ayarlar başarıyla kaydedildi!</div>
            <?php } ?>
            <?php if ($error) { ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php } ?>
            <form method="POST">
                <div class="form-group">
                    <label for="vtc_id">VTC ID</label>
                    <input type="number" id="vtc_id" name="vtc_id" value="<?php echo $settings['vtc_id']; ?>" placeholder="VTC ID'sini girin" required>
                </div>
                <div class="form-group">
                    <label for="trucky_api_key">Trucky API Anahtarı</label>
                    <input type="text" id="trucky_api_key" name="trucky_api_key" value="<?php echo $settings['trucky_api_key']; ?>" placeholder="Trucky API anahtarını girin" required>
                </div>
                <div class="form-group">
                    <label for="trucky_company_id">Trucky Company ID</label>
                    <input type="number" id="trucky_company_id" name="trucky_company_id" value="<?php echo $settings['trucky_company_id']; ?>" placeholder="Trucky Company ID'sini girin" required>
                </div>
                <div class="form-group">
                    <label for="theme_color">Tema Rengi</label>
                    <input type="color" id="theme_color" name="theme_color" value="<?php echo $settings['theme_color']; ?>">
                </div>
                <div class="form-group">
                    <label for="header_text">Header Metni</label>
                    <input type="text" id="header_text" name="header_text" value="<?php echo $settings['header_text']; ?>" placeholder="Header metnini girin">
                </div>
                <div class="form-group">
                    <label for="footer_text">Footer Metni</label>
                    <input type="text" id="footer_text" name="footer_text" value="<?php echo $settings['footer_text']; ?>" placeholder="Footer metnini girin">
                </div>
                <div class="form-group">
                    <label for="font_size">Yazı Tipi Boyutu (px)</label>
                    <input type="number" id="font_size" name="font_size" value="<?php echo $settings['font_size']; ?>" min="12" max="24">
                </div>
                <button type="submit">Ayarları Kaydet</button>
            </form>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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