<?php
require_once dirname(__DIR__) . '/auth.php';
checkAuth('permissions.php');

// Hata loglamasını etkinleştir
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log("permissions.php - Sayfa yüklendi");

// Kullanıcı bilgilerini al
$user = $_SESSION['user'];
$steam_id = $user['steam_id'];
$username = htmlspecialchars($user['username']);
$vtc_id = $user['vtc_id'];
$role = $user['role'];

// Sadece superadmin veya owner erişebilir
if ($role !== 'superadmin' && $role !== 'owner') {
    $_SESSION['error'] = 'Bu sayfaya erişim yetkiniz yok.';
    header('Location: /dashboard.php');
    exit;
}

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

// VTC ID değişikliğini kontrol et ve üyeleri güncelle
if ($vtc_id !== $settings['vtc_id']) {
    error_log("permissions.php - VTC ID değişti: Eski={$settings['vtc_id']}, Yeni={$vtc_id}");

    // Yeni VTC ID'yi ayarlara kaydet
    $settings['vtc_id'] = $vtc_id;
    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));

    // Yeni VTC üyelerini TruckersMP API'den çek
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}/members");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $new_members = [];
    if ($http_code === 200) {
        $data = json_decode($response, true);
        $new_members = $data['response']['members'] ?? [];
    } else {
        error_log("permissions.php - Yeni VTC üyeleri alınamadı: HTTP $http_code, Yanıt: " . $response);
        $_SESSION['error'] = 'Yeni VTC üyeleri alınamadı. Lütfen tekrar deneyin.';
    }

    // permissions.json dosyasını yükle
    $permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
    if (file_exists($permissions_file)) {
        $permissions = json_decode(file_get_contents($permissions_file), true);
        if ($permissions === null) {
            error_log("permissions.php - permissions.json geçersiz JSON: $permissions_file");
            $_SESSION['error'] = 'permissions.json dosyası bozuk.';
        }
    } else {
        $permissions = [
            'restricted_pages' => [
                'ayarlar.php' => ['manage_settings'],
                'permissions.php' => ['superadmin', 'owner'],
                'announcements.php' => ['manage_content']
            ],
            'roles' => [
                'superadmin' => ['permissions' => ['manage_content', 'manage_users', 'manage_settings', 'manage_announcements']],
                'owner' => ['permissions' => ['manage_content', 'manage_settings']],
                'Uye' => ['permissions' => []]
            ],
            'users' => [
                '76561198243975844' => 'superadmin'
            ]
        ];
    }

    // Mevcut kullanıcı rollerini al
    $current_users = $permissions['users'] ?? [];

    // Yeni kullanıcı rolleri oluştur
    $updated_users = [];
    foreach ($new_members as $member) {
        $member_steam_id = $member['steam_id'] ?? $member['steamID'] ?? $member['steamID64'] ?? null;
        if ($member_steam_id === null) {
            continue;
        }

        // Mevcut rolü koru, yoksa varsayılan 'Uye' ata
        $updated_users[$member_steam_id] = isset($current_users[$member_steam_id]) ? $current_users[$member_steam_id] : 'Uye';
    }

    // Superadmin rolünü koru
    if (isset($current_users['76561198243975844'])) {
        $updated_users['76561198243975844'] = 'superadmin';
    }

    // permissions.json dosyasını güncelle
    $permissions['users'] = $updated_users;
    if (!file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT))) {
        error_log("permissions.php - permissions.json güncellenemedi: $permissions_file");
        $_SESSION['error'] = 'Kullanıcı rolleri güncellenirken hata oluştu.';
    } else {
        error_log("permissions.php - Kullanıcı rolleri güncellendi: $permissions_file");
        $_SESSION['success'] = 'VTC üyeleri ve rolleri başarıyla güncellendi!';
    }

    // Sayfayı yenile
    header('Location: permissions.php');
    exit;
}

// permissions.json dosyasını yükle (VTC ID değişmediyse)
$permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
if (file_exists($permissions_file)) {
    $permissions = json_decode(file_get_contents($permissions_file), true);
    if ($permissions === null) {
        error_log("permissions.php - permissions.json geçersiz JSON: $permissions_file");
        $_SESSION['error'] = 'permissions.json dosyası bozuk.';
    }
} else {
    $permissions = [
        'restricted_pages' => [
            'ayarlar.php' => ['manage_settings'],
            'permissions.php' => ['superadmin', 'owner'],
            'announcements.php' => ['manage_content']
        ],
        'roles' => [
            'superadmin' => ['permissions' => ['manage_content', 'manage_users', 'manage_settings', 'manage_announcements']],
            'owner' => ['permissions' => ['manage_content', 'manage_settings']],
            'Uye' => ['permissions' => []]
        ],
        'users' => [
            '76561198243975844' => 'superadmin'
        ]
    ];
    if (!file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT))) {
        error_log("permissions.php - İlk permissions.json oluşturulamadı: $permissions_file");
        $_SESSION['error'] = 'permissions.json dosyası oluşturulamadı.';
    }
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
if ($http_code_vtc !== 200) {
    error_log("permissions.php - TruckersMP VTC API hatası: HTTP $http_code_vtc, Yanıt: " . $response_vtc);
}
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// VTC üyelerini TruckersMP API'den çek
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}/members");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$members = [];
if ($http_code === 200) {
    $data = json_decode($response, true);
    $members = $data['response']['members'] ?? [];
} else {
    error_log("permissions.php - TruckersMP Members API hatası: HTTP $http_code, Yanıt: " . $response);
}

// Mesajlar
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Yetki güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role === 'superadmin' || $role === 'owner')) {
    error_log("permissions.php - POST isteği alındı: " . json_encode($_POST, JSON_PRETTY_PRINT));

    if (isset($_POST['update_restricted_pages'])) {
        // Kısıtlı sayfaları güncelle
        $restricted_pages = [];
        foreach ($_POST['restricted_pages'] ?? [] as $page => $value) {
            if ($value === 'on') {
                $required = $_POST["required_$page"] ?? [];
                $restricted_pages[$page] = array_filter($required, 'is_string');
                error_log("permissions.php - Kısıtlı sayfa güncellendi: $page, Gerekli=" . json_encode($restricted_pages[$page]));
            }
        }
        $permissions['restricted_pages'] = $restricted_pages;

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Kısıtlı sayfalar kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Kısıtlı sayfalar başarıyla güncellendi!';
            error_log("permissions.php - Kısıtlı sayfalar kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['update_role_permissions'])) {
        // Rol izinlerini güncelle
        foreach ($permissions['roles'] as $role_name => &$role_data) {
            if ($role_name !== 'superadmin') {
                $permissions_key = "role_permissions_{$role_name}";
                $role_data['permissions'] = array_filter($_POST[$permissions_key] ?? [], 'is_string');
                error_log("permissions.php - Rol güncellendi: $role_name, Yetkiler: " . json_encode($role_data['permissions']));
            }
        }
        unset($role_data);

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Yetkiler kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Rol yetkileri başarıyla güncellendi!';
            error_log("permissions.php - Rol yetkileri kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['update_user_roles'])) {
        // Kullanıcı rollerini güncelle
        foreach ($members as $member) {
            $member_steam_id = $member['steam_id'] ?? $member['steamID'] ?? $member['steamID64'] ?? null;
            if ($member_steam_id === null || $member_steam_id === '76561198243975844') {
                continue;
            }
            $new_role = $_POST['user_role_' . $member_steam_id] ?? 'Uye';
            if (isset($permissions['roles'][$new_role])) {
                $permissions['users'][$member_steam_id] = $new_role;
                error_log("permissions.php - Kullanıcı rolü güncellendi: steam_id=$member_steam_id, yeni_rol=$new_role");
            } else {
                error_log("permissions.php - Geçersiz rol atama denemesi: steam_id=$member_steam_id, rol=$new_role");
            }
        }

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Kullanıcı rolleri kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Kullanıcı rolleri başarıyla güncellendi!';
            error_log("permissions.php - Kullanıcı rolleri kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['create_role'])) {
        // Yeni rol oluşturma
        $new_role = trim($_POST['role_name']);
        $permissions_list = array_filter($_POST['role_permissions'] ?? [], 'is_string');

        if (!empty($new_role) && !isset($permissions['roles'][$new_role]) && $new_role !== 'superadmin') {
            $permissions['roles'][$new_role] = ['permissions' => $permissions_list];
            if (!is_writable($permissions_file)) {
                $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
                error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
            } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
                $_SESSION['error'] = 'Rol oluşturulurken hata oluştu.';
                error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
            } else {
                $_SESSION['success'] = 'Yeni rol başarıyla oluşturuldu!';
                error_log("permissions.php - Yeni rol oluşturuldu: $new_role");
            }
        } else {
            $_SESSION['error'] = 'Rol adı boş olamaz, zaten mevcut veya superadmin rolü oluşturamazsınız.';
            error_log("permissions.php - Geçersiz rol oluşturma denemesi: $new_role");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['delete_role'])) {
        // Rol silme
        $role_to_delete = $_POST['role_to_delete'];
        if (isset($permissions['roles'][$role_to_delete]) && $role_to_delete !== 'superadmin' && $role_to_delete !== 'owner') {
            unset($permissions['roles'][$role_to_delete]);
            foreach ($permissions['users'] as $user_id => &$user_role) {
                if ($user_role === $role_to_delete) {
                    $user_role = 'Uye';
                }
            }
            unset($user_role);
            if (!is_writable($permissions_file)) {
                $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
                error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
            } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
                $_SESSION['error'] = 'Rol silinirken hata oluştu.';
                error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
            } else {
                $_SESSION['success'] = 'Rol başarıyla silindi!';
                error_log("permissions.php - Rol silindi: $role_to_delete");
            }
        } else {
            $_SESSION['error'] = 'Bu rol silinemez veya mevcut değil.';
            error_log("permissions.php - Geçersiz rol silme denemesi: $role_to_delete");
        }
        header('Location: permissions.php');
        exit;
    }
}

// Mevcut sayfalar
$pages = [
    'dashboard.php' => 'Ana Sayfa',
    'members.php' => 'Üyeler',
    'delivery.php' => 'Teslimatlar',
    'events.php' => 'Etkinlikler',
    'server_status.php' => 'Sunucu Durumu',
    'announcements.php' => 'Duyurular',
    'ayarlar.php' => 'Ayarlar',
    'permissions.php' => 'Yetki Yönetimi'
];

$permissions_list = [
    'manage_content' => 'İçerik Yönetimi (Duyurular, vb.)',
    'manage_users' => 'Üye Yönetimi',
    'manage_settings' => 'Ayar Yönetimi'
];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Yetki Yönetimi - <?php echo $settings['header_text'] ?: $vtc_name; ?> VTC Panel</title>
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
        .

System: Kodunuzu düzenleyerek VTC ID değiştiğinde kullanıcı rollerinin TruckersMP API'den alınan VTC üyeleriyle otomatik olarak güncellenmesini sağladım. Aşağıda, `permissions.php` dosyasının tam ve son hali yer alıyor. Kod, mevcut yapınızı korurken, VTC ID değişikliğini algılayarak `permissions.json` dosyasındaki `users` bölümünü yeni üyelerle senkronize ediyor. Mevcut rolleri koruyor, yeni üyelere varsayılan olarak `Uye` rolü atıyor ve superadmin (`76561198243975844`) rolünü sabit tutuyor. Ayrıca, hata yönetimi ve loglama güçlendirildi.

### Önemli Değişiklikler
1. **VTC ID Değişiklik Kontrolü**: Ayarlar dosyasındaki (`settings.json`) VTC ID ile oturumdaki VTC ID karşılaştırılıyor. Farklıysa, TruckersMP API'den yeni üyeler çekiliyor ve `permissions.json` güncelleniyor.
2. **Kullanıcı Rolleri Senkronizasyonu**: Yeni üyeler için `Uye` rolü atanırken, mevcut kullanıcıların rolleri korunuyor. Superadmin rolü her zaman sabit kalıyor.
3. **Hata Yönetimi**: API çağrılarında ve dosya yazma işlemlerinde hata kontrolü güçlendirildi, hatalar loglanıyor ve kullanıcıya uygun mesajlar gösteriliyor.
4. **Kod Optimizasyonu**: `update_user_roles` kısmı, geçersiz rol atamalarını engellemek için kontrol edildi ve daha güvenli hale getirildi.

### Son Kod: `permissions.php`
```php
<?php
require_once dirname(__DIR__) . '/auth.php';
checkAuth('permissions.php');

// Hata loglamasını etkinleştir
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
error_log("permissions.php - Sayfa yüklendi");

// Kullanıcı bilgilerini al
$user = $_SESSION['user'];
$steam_id = $user['steam_id'];
$username = htmlspecialchars($user['username']);
$vtc_id = $user['vtc_id'];
$role = $user['role'];

// Sadece superadmin veya owner erişebilir
if ($role !== 'superadmin' && $role !== 'owner') {
    $_SESSION['error'] = 'Bu sayfaya erişim yetkiniz yok.';
    header('Location: /dashboard.php');
    exit;
}

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

// VTC ID değişikliğini kontrol et ve üyeleri güncelle
if ($vtc_id !== $settings['vtc_id']) {
    error_log("permissions.php - VTC ID değişti: Eski={$settings['vtc_id']}, Yeni={$vtc_id}");

    // Yeni VTC ID'yi ayarlara kaydet
    $settings['vtc_id'] = $vtc_id;
    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));

    // Yeni VTC üyelerini TruckersMP API'den çek
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}/members");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $new_members = [];
    if ($http_code === 200) {
        $data = json_decode($response, true);
        $new_members = $data['response']['members'] ?? [];
    } else {
        error_log("permissions.php - Yeni VTC üyeleri alınamadı: HTTP $http_code, Yanıt: " . $response);
        $_SESSION['error'] = 'Yeni VTC üyeleri alınamadı. Lütfen tekrar deneyin.';
    }

    // permissions.json dosyasını yükle
    $permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
    if (file_exists($permissions_file)) {
        $permissions = json_decode(file_get_contents($permissions_file), true);
        if ($permissions === null) {
            error_log("permissions.php - permissions.json geçersiz JSON: $permissions_file");
            $_SESSION['error'] = 'permissions.json dosyası bozuk.';
        }
    } else {
        $permissions = [
            'restricted_pages' => [
                'ayarlar.php' => ['manage_settings'],
                'permissions.php' => ['superadmin', 'owner'],
                'announcements.php' => ['manage_content']
            ],
            'roles' => [
                'superadmin' => ['permissions' => ['manage_content', 'manage_users', 'manage_settings', 'manage_announcements']],
                'owner' => ['permissions' => ['manage_content', 'manage_settings']],
                'Uye' => ['permissions' => []]
            ],
            'users' => [
                '76561198243975844' => 'superadmin'
            ]
        ];
    }

    // Mevcut kullanıcı rollerini al
    $current_users = $permissions['users'] ?? [];

    // Yeni kullanıcı rolleri oluştur
    $updated_users = [];
    foreach ($new_members as $member) {
        $member_steam_id = $member['steam_id'] ?? $member['steamID'] ?? $member['steamID64'] ?? null;
        if ($member_steam_id === null) {
            continue;
        }

        // Mevcut rolü koru, yoksa varsayılan 'Uye' ata
        $updated_users[$member_steam_id] = isset($current_users[$member_steam_id]) ? $current_users[$member_steam_id] : 'Uye';
    }

    // Superadmin rolünü koru
    $updated_users['76561198243975844'] = 'superadmin';

    // permissions.json dosyasını güncelle
    $permissions['users'] = $updated_users;
    if (!file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT))) {
        error_log("permissions.php - permissions.json güncellenemedi: $permissions_file");
        $_SESSION['error'] = 'Kullanıcı rolleri güncellenirken hata oluştu.';
    } else {
        error_log("permissions.php - Kullanıcı rolleri güncellendi: $permissions_file");
        $_SESSION['success'] = 'VTC üyeleri ve rolleri başarıyla güncellendi!';
    }

    // Sayfayı yenile
    header('Location: permissions.php');
    exit;
}

// permissions.json dosyasını yükle (VTC ID değişmediyse)
$permissions_file = dirname(__DIR__) . '/' . $vtc_id . '/json/permissions.json';
if (file_exists($permissions_file)) {
    $permissions = json_decode(file_get_contents($permissions_file), true);
    if ($permissions === null) {
        error_log("permissions.php - permissions.json geçersiz JSON: $permissions_file");
        $_SESSION['error'] = 'permissions.json dosyası bozuk.';
    }
} else {
    $permissions = [
        'restricted_pages' => [
            'ayarlar.php' => ['manage_settings'],
            'permissions.php' => ['superadmin', 'owner'],
            'announcements.php' => ['manage_content']
        ],
        'roles' => [
            'superadmin' => ['permissions' => ['manage_content', 'manage_users', 'manage_settings', 'manage_announcements']],
            'owner' => ['permissions' => ['manage_content', 'manage_settings']],
            'Uye' => ['permissions' => []]
        ],
        'users' => [
            '76561198243975844' => 'superadmin'
        ]
    ];
    if (!file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT))) {
        error_log("permissions.php - İlk permissions.json oluşturulamadı: $permissions_file");
        $_SESSION['error'] = 'permissions.json dosyası oluşturulamadı.';
    }
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
if ($http_code_vtc !== 200) {
    error_log("permissions.php - TruckersMP VTC API hatası: HTTP $http_code_vtc, Yanıt: " . $response_vtc);
}
$vtc_name = $data_vtc['response']['name'] ?? 'Bilinmeyen VTC';
$vtc_logo = $data_vtc['response']['logo'] ?? 'https://via.placeholder.com/50';
$vtc_slogan = $data_vtc['response']['slogan'] ?? '';
$vtc_website = $data_vtc['response']['website'] ?? '#';
$twitter = $data_vtc['response']['socials']['twitter'] ?? '';
$twitch = $data_vtc['response']['socials']['twitch'] ?? '';
$discord = $data_vtc['response']['socials']['discord'] ?? '';

// VTC üyelerini TruckersMP API'den çek
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.truckersmp.com/v2/vtc/{$vtc_id}/members");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Sevkiyatbul/1.0']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$members = [];
if ($http_code === 200) {
    $data = json_decode($response, true);
    $members = $data['response']['members'] ?? [];
} else {
    error_log("permissions.php - TruckersMP Members API hatası: HTTP $http_code, Yanıt: " . $response);
}

// Mesajlar
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['error'], $_SESSION['success']);

// Yetki güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role === 'superadmin' || $role === 'owner')) {
    error_log("permissions.php - POST isteği alındı: " . json_encode($_POST, JSON_PRETTY_PRINT));

    if (isset($_POST['update_restricted_pages'])) {
        // Kısıtlı sayfaları güncelle
        $restricted_pages = [];
        foreach ($_POST['restricted_pages'] ?? [] as $page => $value) {
            if ($value === 'on') {
                $required = $_POST["required_$page"] ?? [];
                $restricted_pages[$page] = array_filter($required, 'is_string');
                error_log("permissions.php - Kısıtlı sayfa güncellendi: $page, Gerekli=" . json_encode($restricted_pages[$page]));
            }
        }
        $permissions['restricted_pages'] = $restricted_pages;

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Kısıtlı sayfalar kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Kısıtlı sayfalar başarıyla güncellendi!';
            error_log("permissions.php - Kısıtlı sayfalar kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['update_role_permissions'])) {
        // Rol izinlerini güncelle
        foreach ($permissions['roles'] as $role_name => &$role_data) {
            if ($role_name !== 'superadmin') {
                $permissions_key = "role_permissions_{$role_name}";
                $role_data['permissions'] = array_filter($_POST[$permissions_key] ?? [], 'is_string');
                error_log("permissions.php - Rol güncellendi: $role_name, Yetkiler: " . json_encode($role_data['permissions']));
            }
        }
        unset($role_data);

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Yetkiler kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Rol yetkileri başarıyla güncellendi!';
            error_log("permissions.php - Rol yetkileri kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['update_user_roles'])) {
        // Kullanıcı rollerini güncelle
        foreach ($members as $member) {
            $member_steam_id = $member['steam_id'] ?? $member['steamID'] ?? $member['steamID64'] ?? null;
            if ($member_steam_id === null || $member_steam_id === '76561198243975844') {
                continue;
            }
            $new_role = $_POST['user_role_' . $member_steam_id] ?? 'Uye';
            if (isset($permissions['roles'][$new_role])) {
                $permissions['users'][$member_steam_id] = $new_role;
                error_log("permissions.php - Kullanıcı rolü güncellendi: steam_id=$member_steam_id, yeni_rol=$new_role");
            } else {
                error_log("permissions.php - Geçersiz rol atama denemesi: steam_id=$member_steam_id, rol=$new_role");
            }
        }

        if (!is_writable($permissions_file)) {
            $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
            error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
        } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
            $_SESSION['error'] = 'Kullanıcı rolleri kaydedilirken hata oluştu.';
            error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
        } else {
            $_SESSION['success'] = 'Kullanıcı rolleri başarıyla güncellendi!';
            error_log("permissions.php - Kullanıcı rolleri kaydedildi: $permissions_file");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['create_role'])) {
        // Yeni rol oluşturma
        $new_role = trim($_POST['role_name']);
        $permissions_list = array_filter($_POST['role_permissions'] ?? [], 'is_string');

        if (!empty($new_role) && !isset($permissions['roles'][$new_role]) && $new_role !== 'superadmin') {
            $permissions['roles'][$new_role] = ['permissions' => $permissions_list];
            if (!is_writable($permissions_file)) {
                $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
                error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
            } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
                $_SESSION['error'] = 'Rol oluşturulurken hata oluştu.';
                error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
            } else {
                $_SESSION['success'] = 'Yeni rol başarıyla oluşturuldu!';
                error_log("permissions.php - Yeni rol oluşturuldu: $new_role");
            }
        } else {
            $_SESSION['error'] = 'Rol adı boş olamaz, zaten mevcut veya superadmin rolü oluşturamazsınız.';
            error_log("permissions.php - Geçersiz rol oluşturma denemesi: $new_role");
        }
        header('Location: permissions.php');
        exit;
    } elseif (isset($_POST['delete_role'])) {
        // Rol silme
        $role_to_delete = $_POST['role_to_delete'];
        if (isset($permissions['roles'][$role_to_delete]) && $role_to_delete !== 'superadmin' && $role_to_delete !== 'owner') {
            unset($permissions['roles'][$role_to_delete]);
            foreach ($permissions['users'] as $user_id => &$user_role) {
                if ($user_role === $role_to_delete) {
                    $user_role = 'Uye';
                }
            }
            unset($user_role);
            if (!is_writable($permissions_file)) {
                $_SESSION['error'] = 'permissions.json dosyası yazılabilir değil.';
                error_log("permissions.php - permissions.json yazılabilir değil: $permissions_file");
            } elseif (file_put_contents($permissions_file, json_encode($permissions, JSON_PRETTY_PRINT)) === false) {
                $_SESSION['error'] = 'Rol silinirken hata oluştu.';
                error_log("permissions.php - permissions.json yazılamadı: $permissions_file");
            } else {
                $_SESSION['success'] = 'Rol başarıyla silindi!';
                error_log("permissions.php - Rol silindi: $role_to_delete");
            }
        } else {
            $_SESSION['error'] = 'Bu rol silinemez veya mevcut değil.';
            error_log("permissions.php - Geçersiz rol silme denemesi: $role_to_delete");
        }
        header('Location: permissions.php');
        exit;
    }
}

// Mevcut sayfalar
$pages = [
    'dashboard.php' => 'Ana Sayfa',
    'members.php' => 'Üyeler',
    'delivery.php' => 'Teslimatlar',
    'events.php' => 'Etkinlikler',
    'server_status.php' => 'Sunucu Durumu',
    'announcements.php' => 'Duyurular',
    'ayarlar.php' => 'Ayarlar',
    'permissions.php' => 'Yetki Yönetimi'
];

$permissions_list = [
    'manage_content' => 'İçerik Yönetimi (Duyurular, vb.)',
    'manage_users' => 'Üye Yönetimi',
    'manage_settings' => 'Ayar Yönetimi'
];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Yetki Yönetimi - <?php echo $settings['header_text'] ?: $vtc_name; ?> VTC Panel</title>
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
        .form-check {
            margin-bottom: 10px;
            padding-left: 2rem;
            transition: background-color 0.2s ease;
        }
        .form-check:hover {
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .form-check-input {
            margin-top: 0.3rem;
            cursor: pointer;
        }
        .form-check-label {
            font-size: 0.95rem;
            color: #555;
            cursor: pointer;
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card-custom {
            animation: fadeInUp 0.6s ease-in-out;
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
        .permissions-table {
            border: none;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
        }
        .permissions-table th {
            background-color: #1e212d;
            color: #fff;
            font-weight: 600;
            padding: 12px 15px;
            text-align: left;
            font-size: 0.95rem;
        }
        .permissions-table td {
            vertical-align: middle;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
        }
        .permissions-table tr:last-child td {
            border-bottom: none;
        }
        .permissions-table .role-name {
            font-weight: 600;
            color: #333;
            text-transform: capitalize;
        }
        .permissions-table .permissions-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .permissions-table .action-cell {
            text-align: right;
        }
        .permissions-table .btn-danger {
            background-color: #ff4d4f;
            border: none;
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        .permissions-table .btn-danger:hover {
            background-color: #d9363e;
        }
        .permissions-save-btn {
            margin-top: 15px;
            width: 200px;
            text-align: center;
        }
        /* Responsive Ayarlar */
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
            .nav-item.dropdown:hover .dropdown-menu {
                display: none;
            }
            .permissions-table th, .permissions-table td {
                padding: 10px;
                font-size: 0.85rem;
            }
            .permissions-table .btn-danger {
                padding: 5px 10px;
                font-size: 0.8rem;
            }
            .permissions-save-btn {
                width: 100%;
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
        <h3 class="section-title">Yetki Yönetimi</h3>

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

        <?php if ($role === 'superadmin' || $role === 'owner'): ?>
            <!-- Kısıtlı Sayfaları Yönet -->
            <div class="card-custom">
                <h3><i class="fa fa-ban"></i> Kısıtlı Sayfalar</h3>
                <form method="POST" action="permissions.php" id="restricted-pages-form">
                    <table class="table permissions-table">
                        <thead>
                            <tr>
                                <th scope="col">Sayfa</th>
                                <th scope="col">Kısıtlı</th>
                                <th scope="col">Gerekli Yetkiler/Roller</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pages as $page => $name): ?>
                                <tr>
                                    <td><?php echo $name; ?></td>
                                    <td>
                                        <input type="checkbox" class="form-check-input" name="restricted_pages[<?php echo $page; ?>]" <?php echo isset($permissions['restricted_pages'][$page]) ? 'checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <div class="permissions-list">
                                            <?php foreach ($permissions_list as $perm => $perm_name): ?>
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="required_<?php echo $page; ?>[]" value="<?php echo $perm; ?>" <?php echo in_array($perm, $permissions['restricted_pages'][$page] ?? []) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label"><?php echo $perm_name; ?></label>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php foreach ($permissions['roles'] as $role_name => $role_data): ?>
                                                <?php if ($role_name !== 'Uye'): ?>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" name="required_<?php echo $page; ?>[]" value="<?php echo $role_name; ?>" <?php echo in_array($role_name, $permissions['restricted_pages'][$page] ?? []) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label"><?php echo ucfirst($role_name); ?> Rolü</label>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="update_restricted_pages" class="btn btn-custom permissions-save-btn">Sayfaları Kaydet</button>
                </form>
            </div>

            <!-- Rolleri Yönet -->
            <div class="card-custom">
                <h3><i class="fa fa-lock"></i> Mevcut Roller ve Yetkiler</h3>
                <form method="POST" action="permissions.php" id="role-permissions-form">
                    <table class="table permissions-table">
                        <thead>
                            <tr>
                                <th scope="col">Rol</th>
                                <th scope="col">Yetkiler</th>
                                <th scope="col">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions['roles'] as $role_name => $role_data): ?>
                                <?php if ($role_name === 'superadmin') continue; ?>
                                <tr>
                                    <td class="role-name"><?php echo ucfirst($role_name); ?></td>
                                    <td>
                                        <div class="permissions-list">
                                            <?php foreach ($permissions_list as $perm => $name): ?>
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="role_permissions_<?php echo $role_name; ?>[]" value="<?php echo $perm; ?>" <?php if (in_array($perm, $role_data['permissions'] ?? [])) echo 'checked'; ?>>
                                                    <label class="form-check-label"><?php echo $name; ?></label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td class="action-cell">
                                        <?php if ($role_name !== 'owner'): ?>
                                            <form method="POST" action="permissions.php" style="display: inline;">
                                                <input type="hidden" name="role_to_delete" value="<?php echo $role_name; ?>">
                                                <button type="submit" name="delete_role" class="btn btn-danger" onclick="return confirm('Bu rolü silmek istediğinizden emin misiniz?');">Rolü Sil</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="update_role_permissions" class="btn btn-custom permissions-save-btn">Yetkileri Kaydet</button>
                </form>
            </div>

            <!-- Kullanıcı Rolleri -->
            <div class="card-custom">
                <h3><i class="fa fa-users"></i> Kullanıcı Rolleri</h3>
                <form method="POST" action="permissions.php" id="user-roles-form">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Kullanıcı</th>
                                <th>Rol</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <?php 
                                    $member_steam_id = $member['steam_id'] ?? $member['steamID'] ?? $member['steamID64'] ?? null;
                                    if ($member_steam_id === null || $member_steam_id === '76561198243975844') {
                                        continue;
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($member['username']); ?> (<?php echo $member_steam_id; ?>)</td>
                                    <td>
                                        <select name="user_role_<?php echo $member_steam_id; ?>" class="form-select">
                                            <?php foreach ($permissions['roles'] as $role_name => $role_data): ?>
                                                <?php if ($role_name === 'superadmin') continue; ?>
                                                <option value="<?php echo $role_name; ?>" <?php if (($permissions['users'][$member_steam_id] ?? 'Uye') === $role_name) echo 'selected'; ?>><?php echo ucfirst($role_name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" name="update_user_roles" class="btn btn-custom">Rolleri Kaydet</button>
                </form>
            </div>

            <!-- Yeni Rol Oluşturma -->
            <div class="card-custom">
                <h3><i class="fa fa-plus-circle"></i> Yeni Rol Oluştur</h3>
                <form method="POST" action="permissions.php" id="create-role-form">
                    <div class="form-group">
                        <label for="role_name">Rol Adı</label>
                        <input type="text" class="form-control" id="role_name" name="role_name" required>
                    </div>
                    <div class="form-group">
                        <label>Yetkiler</label>
                        <?php foreach ($permissions_list as $perm => $name): ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="role_permissions[]" value="<?php echo $perm; ?>">
                                <label class="form-check-label"><?php echo $name; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="create_role" class="btn btn-custom">Rol Oluştur</button>
                </form>
            </div>
        <?php else: ?>
            <div class="card-custom">
                <div class="alert alert-warning">Bu sayfayı yalnızca VTC sahibi veya süper yönetici düzenleyebilir.</div>
            </div>
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