<?php
// Oturum ayarlarını yapılandır (session_start öncesi)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // HTTPS kullanıyorsanız
ini_set('session.use_only_cookies', 1);

// Oturumu başlat
session_start();

// Oturumun aktif olduğunu doğrula
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("index.php: Oturum başlatılamadı!");
    die("Oturum başlatılamadı. Lütfen sunucu yapılandırmasını kontrol edin.");
}

// Steam OpenID ayarları
$steam_openid_settings = [
    'openid.ns' => 'http://specs.openid.net/auth/2.0',
    'openid.mode' => 'checkid_setup',
    'openid.return_to' => 'https://sevkiyatbul.com.tr/callback.php',
    'openid.realm' => 'https://sevkiyatbul.com.tr',
    'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
    'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select'
];

// Rastgele bir state değeri oluştur ve oturuma kaydet
$_SESSION['oauth2state'] = bin2hex(random_bytes(16));

// OpenID yetkilendirme URL'sini oluştur
$auth_url = 'https://steamcommunity.com/openid/login?' . http_build_query($steam_openid_settings) . '&state=' . urlencode($_SESSION['oauth2state']);

// Oluşturulan state ve URL'yi logla
error_log("index.php - Oluşturulan State: " . $_SESSION['oauth2state']);
error_log("index.php - Auth URL: " . $auth_url);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sevkiyatbul - Giriş Yap</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --theme-color: #ff6600;
            --font-size: 16px;
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
        }
        .navbar-brand img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            margin-right: 12px;
        }
        .login-card {
            background-color: #fff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 400px;
            margin: 0 auto;
        }
        .login-card h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        .btn-steam {
            background-color: #171a21;
            color: #fff;
            padding: 12px 20px;
            border-radius: 5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        .btn-steam i {
            margin-right: 10px;
        }
        .btn-steam:hover {
            background-color: #2a3f5a;
            transform: translateY(-2px);
        }
        .footer {
            background-color: #1e212d;
            color: #fff;
            padding: 40px 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="https://via.placeholder.com/50" alt="Logo">
                Sevkiyatbul
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="/index.php">Ana Sayfa</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- İçerik -->
    <div class="container container-custom">
        <div class="login-card">
            <h3><i class="fa fa-sign-in"></i> Giriş Yap</h3>
            <?php if (isset($_SESSION['error'])) { ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php } ?>
            <p>VTC paneline erişmek için Steam hesabınızla giriş yapın.</p>
            <a href="<?php echo htmlspecialchars($auth_url); ?>" class="btn-steam">
                <i class="fa fa-steam"></i> Steam ile Giriş Yap
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>© <?php echo date('Y'); ?> Sevkiyatbul - Tüm Hakları Saklıdır</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>