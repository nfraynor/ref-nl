<?php

return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'dbname' => getenv('DB_DATABASE') ?: 'refnl',
    'username' => getenv('DB_USERNAME') ?: 'refnl_user',
    'password' => getenv('DB_PASSWORD') ?: 'password',
    'charset' => 'utf8mb4'
];
