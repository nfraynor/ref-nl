<?php

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_DATABASE') ?: 'refnl',
    'username' => getenv('DB_USERNAME') ?: 'root',

    'password' => getenv('DB_PASSWORD') ?: 'password',

    'charset' => 'utf8mb4'
];
