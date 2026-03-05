<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$password = 'PprVXblC3jp6oBfi';
$ref = 'ycbqadjsjphovxcbicvm';
$region = 'ap-southeast-1';

$tests = [
    [
        'name' => 'Direct Host, Port 5432, User postgres',
        'host' => "db.$ref.supabase.co",
        'port' => '5432',
        'user' => 'postgres'
    ],
    [
        'name' => 'Direct Host, Port 5432, Pooled User',
        'host' => "db.$ref.supabase.co",
        'port' => '5432',
        'user' => "postgres.$ref"
    ],
    [
        'name' => 'Regional Pooler, Port 6543, Pooled User',
        'host' => "aws-0-$region.pooler.supabase.com",
        'port' => '6543',
        'user' => "postgres.$ref"
    ],
    [
        'name' => 'Regional Pooler, Port 5432, Pooled User',
        'host' => "aws-0-$region.pooler.supabase.com",
        'port' => '5432',
        'user' => "postgres.$ref"
    ],
];

$results = [];

foreach ($tests as $test) {
    try {
        $dsn = "pgsql:host={$test['host']};port={$test['port']};dbname=postgres;sslmode=require";
        $start = microtime(true);
        $pdo = new PDO($dsn, $test['user'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        $end = microtime(true);
        $results[] = [
            'test' => $test['name'],
            'success' => true,
            'time' => round($end - $start, 3) . 's',
            'version' => $pdo->query("SELECT version()")->fetchColumn()
        ];
    }
    catch (Exception $e) {
        $results[] = [
            'test' => $test['name'],
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
