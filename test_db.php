<?php
$host = 'db.ycbqadjsjphovxcbicvm.supabase.co';
$port = '6543';
$dbname = 'postgres';
$username = 'postgres.ycbqadjsjphovxcbicvm';
$password = 'PprVXblC3jp6oBfi';
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

try {
    echo "Connecting to $host:$port...\n";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "Connection successful!\n";
    $stmt = $pdo->query("SELECT version()");
    $row = $stmt->fetch();
    echo "PostgreSQL version: " . $row[0] . "\n";
}
catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
