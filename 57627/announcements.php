<?php
require_once dirname(__DIR__) . '/auth.php';
checkAuth('announcements.php');

// Hata loglamasını etkinleştir
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log("announcements.php - Sayfa yüklendi");

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

// permissions.json dosyasını yükle
$permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
if (file_exists($permissions_file)) {
    $permissions = json_decode(file_get_contents($permissions_file), true);
    if ($permissions === null) {
        error_log("announcements.php - permissions.json geçersiz JSON: $permissions_file");
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

// Duyuru ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_announcement']) && in_array('manage_content', $user_permissions)) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $status = $_POST['status'] ?? 'draft';

    if (!empty($title) && !empty($content)) {
        $new_announcement = [
            'id' => uniqid(),
            'title' => htmlspecialchars($title),
            'content' => htmlspecialchars($content),
            'author_id' => $steam_id,
            'author_name' => $username,
            'created_at' => date('c'),
            'status' => $status
        ];
        $announcements[] = $new_announcement;
        $announcements_data['announcements'] = $announcements;

        if (file_put_contents($announcements_file, json_encode($announcements_data, JSON_PRETTY_PRINT))) {
            $_SESSION['success'] = 'Duyuru başarıyla eklendi!';
            error_log("announcements.php - Yeni duyuru eklendi: " . json_encode($new_announcement));
        } else {
            $_SESSION['error'] = 'Duyuru eklenirken bir hata oluştu. Dosya yazma iznini kontrol edin.';
            error_log("announcements.php - Duyuru kaydedilemedi: $announcements_file");
        }
    } else {
        $_SESSION['error'] = 'Başlık ve içerik boş olamaz.';
        error_log("announcements.php - Boş başlık veya içerik: title=$title, content=$content");
    }
    header('Location: announcements.php');
    exit;
}

// Duyuru düzenleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_announcement']) && in_array('manage_content', $user_permissions)) {
    $announcement_id = $_POST['announcement_id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $status = $_POST['status'] ?? 'draft';

    if (!empty($title) && !empty($content)) {
        foreach ($announcements as &$announcement) {
            if ($announcement['id'] === $announcement_id) {
                $announcement['title'] = htmlspecialchars($title);
                $announcement['content'] = htmlspecialchars($content);
                $announcement['status'] = $status;
                $announcement['updated_at'] = date('c');
                break;
            }
        }
        $announcements_data['announcements'] = $announcements;

        if (file_put_contents($announcements_file, json_encode($announcements_data, JSON_PRETTY_PRINT))) {
            $_SESSION['success'] = 'Duyuru başarıyla güncellendi!';
            error_log("announcements.php - Duyuru güncellendi: ID=$announcement_id");
        } else {
            $_SESSION['error'] = 'Duyuru güncellenirken bir hata oluştu. Dosya yazma iznini kontrol edin.';
            error_log("announcements.php - Duyuru güncellenemedi: $announcements_file");
        }
    } else {
        $_SESSION['error'] = 'Başlık ve içerik boş olamaz.';
        error_log("announcements.php - Boş başlık veya içerik: title=$title, content=$content");
    }
    header('Location: announcements.php');
    exit;
}

// Duyuru silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement']) && in_array('manage_content', $user_permissions)) {
    $announcement_id = $_POST['announcement_id'];
    $announcements = array_filter($announcements, function($announcement) use ($announcement_id) {
        return $announcement['id'] !== $announcement_id;
    });
    $announcements_data['announcements'] = array_values($announcements);

    if (file_put_contents($announcements_file, json_encode($announcements_data, JSON_PRETTY_PRINT))) {
        $_SESSION['success'] = 'Duyuru başarıyla silindi!';
        error_log("announcements.php - Duyuru silindi: ID=$announcement_id");
    } else {
        $_SESSION['error'] = 'Duyuru silinirken bir hata oluştu. Dosya yazma iznini kontrol edin.';
        error_log("announcements.php - Duyuru silinemedi: $announcements_file");
    }
    header('Location: announcements.php');
    exit;
}

// TruckersMP VTC bilgileri
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
$vtc_slogan = $data_vtc['response']['slogan'] ?? 'Slogan Yok';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';

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
    <title>Duyurular - <?php echo $settings['header_text'] ?: $vtc_name; ?> VTC Panel</title>
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
        /* Aktif sayfanın rengini beyaz yap */
        .navbar-nav .nav-link.active {
            color: #fff !important;
            background-color: transparent !important;
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
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e212d;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .btn-custom {
            background-color: var(--theme-color);
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            background-color: #e65c00;
            transform: translateY(-2px);
        }
        .btn-danger {
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 0.9rem;
            background-color: #dc3545;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }
        .btn-warning {
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 0.9rem;
            background-color: #ffc107;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-warning:hover {
            background-color: #e0a800;
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
        .custom-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            overflow: auto;
        }
        .custom-modal-content {
            background-color: #fff;
            margin: 0;
            padding: 20px;
            border-radius: 0;
            width: 100%;
            height: 100%;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
        }
        .custom-modal-header {
            background-color: #1e212d;
            color: #fff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .custom-modal-header h5 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
        }
        .custom-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.8rem;
            cursor: pointer;
            padding: 0 10px;
        }
        .custom-modal-close:hover {
            color: #ccc;
        }
        .custom-modal-body {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        .custom-modal-footer {
            padding: 20px;
            text-align: right;
            border-top: 1px solid #eee;
        }
        .custom-modal .form-label {
            font-weight: 500;
            color: #333;
            font-size: 1.1rem;
        }
        .custom-modal .form-control,
        .custom-modal .form-select {
            border-radius: 5px;
            font-size: 1rem;
            padding: 10px;
        }
        .custom-modal .btn-secondary {
            background-color: #6c757d;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            margin-right: 10px;
        }
        .custom-modal .btn-secondary:hover {
            background-color: #5a6268;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .custom-modal.show {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }
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
        .announcement-list {
            max-height: 600px;
            overflow-y: auto;
        }
        .announcement-item {
            border-bottom: 1px solid #eee;
            padding: 15px 0;
        }
        .announcement-item:last-child {
            border-bottom: none;
        }
        .announcement-item h5 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .announcement-item p {
            font-size: 0.95rem;
            color: #555;
            margin-bottom: 10px;
        }
        .announcement-item small {
            font-size: 0.85rem;
            color: #777;
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
            .card-custom {
                padding: 15px;
            }
            .announcement-item h5 {
                font-size: 1.1rem;
            }
            .announcement-item p {
                font-size: 0.9rem;
            }
            .announcement-item small {
                font-size: 0.8rem;
            }
            .custom-modal-body {
                padding: 20px;
            }
            .custom-modal-header h5 {
                font-size: 1.5rem;
            }
            .custom-modal .form-label {
                font-size: 1rem;
            }
            .custom-modal .form-control,
            .custom-modal .form-select {
                font-size: 0.9rem;
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
            /* Mobil cihazlarda hover devre dışı */
            .nav-item.dropdown:hover .dropdown-menu {
                display: none;
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
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#accountModal"><ATM
                            <i class="fa fa-user"></i> Hesabım</a></li>
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
        <h3 class="section-title">Duyurular</h3>

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

        <!-- Duyuru Ekleme Formu -->
        <?php if (in_array('manage_content', $user_permissions) || $role === 'superadmin'): ?>
            <div class="card-custom">
                <h3><i class="fa fa-plus-circle"></i> Yeni Duyuru Ekle</h3>
                <form method="POST" action="announcements.php">
                    <div class="form-group mb-3">
                        <label for="title">Başlık</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="form-group mb-3">
                        <label for="content">İçerik</label>
                        <textarea class="form-control" id="content" name="content" rows="5" required></textarea>
                    </div>
                    <div class="form-group mb-3">
                        <label for="status">Durum</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="draft">Taslak</option>
                            <option value="published">Yayınlandı</option>
                        </select>
                    </div>
                    <button type="submit" name="add_announcement" class="btn btn-custom">Duyuru Ekle</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Duyuru Listesi -->
        <div class="card-custom">
            <h3><i class="fa fa-bullhorn"></i> Tüm Duyurular</h3>
            <?php if (empty($announcements)): ?>
                <p class="text-center">Henüz duyuru bulunmamaktadır.</p>
            <?php else: ?>
                <div class="announcement-list">
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-item">
                            <h5>
                                <?php echo htmlspecialchars($announcement['title']); ?>
                                <span class="badge <?php echo $announcement['status'] === 'published' ? 'bg-success' : 'bg-warning'; ?>">
                                    <?php echo $announcement['status'] === 'published' ? 'Yayınlandı' : 'Taslak'; ?>
                                </span>
                            </h5>
                            <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                            <small>
                                <i class="fa fa-user"></i> <?php echo htmlspecialchars($announcement['author_name']); ?> 
                                | <i class="fa fa-calendar"></i> <?php echo strftime('%d %B %Y %H:%M', strtotime($announcement['created_at'])); ?>
                                <?php if (isset($announcement['updated_at'])): ?>
                                    | <i class="fa fa-edit"></i> Güncellenme: <?php echo strftime('%d %B %Y %H:%M', strtotime($announcement['updated_at'])); ?>
                                <?php endif; ?>
                            </small>
                            <?php if (in_array('manage_content', $user_permissions) || $role === 'superadmin'): ?>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-warning btn-sm me-2" onclick='openModal("<?php echo $announcement['id']; ?>", "<?php echo htmlspecialchars(addslashes($announcement['title'])); ?>", "<?php echo htmlspecialchars(addslashes($announcement['content'])); ?>", "<?php echo $announcement['status']; ?>")'>Düzenle</button>
                                    <form method="POST" action="announcements.php" style="display: inline;">
                                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                        <button type="submit" name="delete_announcement" class="btn btn-danger btn-sm" onclick="return confirm('Bu duyuruyu silmek istediğinizden emin misiniz?');">Sil</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tek Özel Düzenleme Modalı -->
    <div class="custom-modal" id="editModal">
        <div class="custom-modal-content">
            <div class="custom-modal-header">
                <h5>Duyuru Düzenle</h5>
                <button type="button" class="custom-modal-close" onclick="closeModal()">×</button>
            </div>
            <form method="POST" action="announcements.php" id="editForm">
                <div class="custom-modal-body">
                    <input type="hidden" name="announcement_id" id="edit_id">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Başlık</label>
                        <input type="text" class="form-control" id="edit_title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_content" class="form-label">İçerik</label>
                        <textarea class="form-control" id="edit_content" name="content" rows="10" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Durum</label>
                        <select class="form-select" id="edit_status" name="status" required>
                            <option value="draft">Taslak</option>
                            <option value="published">Yayınlandı</option>
                        </select>
                    </div>
                </div>
                <div class="custom-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Kapat</button>
                    <button type="submit" name="edit_announcement" class="btn btn-custom">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hesabım Modal -->
    <div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="accountModalLabel">Hesabım</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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

    <!-- Scroll to Top -->
    <button class="scroll-to-top"><i class="fa fa-chevron-up"></i></button>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" integrity="sha384-w76AqPfDkMBDXo30jS1Sgez6pr3x5MlQ1ZAGC+nuZB+EYdgRZgiwxhTBTkF7CXvN" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Özel Modal Açma/Kapama Fonksiyonları
        function openModal(id, title, content, status) {
            console.log('Açılan modal: ID=' + id);
            const modal = document.getElementById('editModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_content').value = content;
            document.getElementById('edit_status').value = status;
        }

        function closeModal() {
            console.log('Kapanan modal');
            const modal = document.getElementById('editModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

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