<?php
// JALUR DARURAT: Menghindari masalah Environment Variables Vercel
$host = '153.92.15.84'; // IP Hostinger Anda
$port = '3306';
$dbname = 'u915147866_db_hippams'; // GANTI dengan nama database Hostinger Anda
$username = 'u915147866_hippams'; // GANTI dengan username database Hostinger Anda
$password = 'Hippams2026!'; // GANTI dengan password database Hostinger Anda

try {
    // Kita paksa menggunakan dsn tanpa variabel getenv
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);

}
catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        "success" => false,
        "message" => "Gagal konek Hostinger: " . $e->getMessage(),
        "info" => "Cek kembali username/password/remote MySQL (%)"
    ]);
    exit();
}