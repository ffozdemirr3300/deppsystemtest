<?php
// Oturumu başlat
session_start();

// Oturumu temizle
$_SESSION = [];
session_destroy();

// Çıkış yaptıktan sonra login.php'ye yönlendir
header('Location: /index.php');
exit;