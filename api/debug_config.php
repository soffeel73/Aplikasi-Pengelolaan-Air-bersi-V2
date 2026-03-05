<?php
require_once 'util/db.php';
echo json_encode([
    'VERCEL' => getenv('VERCEL'),
    'DSN' => $dsn, // This variable is defined in util/db.php
    'USERNAME' => $username,
    'PHP_VERSION' => phpversion()
]);
