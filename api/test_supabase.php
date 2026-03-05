<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$project_ref = 'ycbqadjsjphovxcbicvm';
$password = 'PprVXblC3jp6oBfi';
$dbname = 'postgres';

$configs = [
    [
        'name' => 'Direct Host + Port 5432 (Likely IPv6 fail)',
        'host' => "db.$project_ref.supabase.co",
        'port' => '5432',
        'user' => 'postgres',
    ],
    [
        'name' => 'Direct Host + Port 6543 (Pooler)',
        'host' => "db.$project_ref.supabase.co",
        'port' => '6543',
        'user' => "postgres.$project_ref",
    ],
    [
        'name' => 'Regional Pooler + Port 5432 (Session Mode)',
        'host' => "aws-0-ap-southeast-1.pooler.supabase.com",
        'port' => '5432',
        'user' => "postgres.$project_ref",
    ],
    [
        'name' => 'Regional Pooler + Port 6543 (Transaction Mode)',
        'host' => "aws-0-ap-southeast-1.pooler.supabase.com",
        'port' => '6543',
        'user' => "postgres.$project_ref",
    ],
    [
        'name' => 'Regional Pooler + Port 5432 + Just postgres user',
        'host' => "aws-0-ap-southeast-1.pooler.supabase.com",
        'port' => '5432',
        'user' => "postgres",
    ]
];

foreach ($configs as $cfg) {
    echo "Testing: {$cfg['name']}\n";
    echo "Target: {$cfg['user']}@{$cfg['host']}:{$cfg['port']}\n";

    try {
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname=$dbname;sslmode=require";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ];
        $start = microtime(true);
        $pdo = new PDO($dsn, $cfg['user'], $password, $options);
        $end = microtime(true);
        echo "SUCCESS! Connection took " . round($end - $start, 3) . "s\n";
        $stmt = $pdo->query("SELECT version()");
        echo "Version: " . $stmt->fetchColumn() . "\n";
    }
    catch (Exception $e) {
        echo "FAILURE: " . $e->getMessage() . "\n";
    }
    echo "-------------------------------------------\n\n";
}
