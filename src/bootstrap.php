<?php

define('TASK_WORKER_ROOT_PATH', dirname(__DIR__));

$composerAutoload = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
];
$vendorPath = null;
foreach ($composerAutoload as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        $vendorPath = dirname($autoload);
        break;
    }
}

$envFile = TASK_WORKER_ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    // 从 env.ini 加载环境变量
    $dotenv = new \Symfony\Component\Dotenv\Dotenv();
    $dotenv->load($envFile);
}
