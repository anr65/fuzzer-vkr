<?php

use PhpFuzzer\Config;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Подключаем автозагрузчик
require __DIR__ . '/../vendor/autoload.php';

// Создаем Laravel приложение
$app = require __DIR__ . '/../vendor/laravel/laravel/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
/** @var \PhpFuzzer\Config $config*/

// Настройка цели фаззинга
$config->setTarget(function (string $input) use ($kernel) {
    try {
        $uri = '/' . rawurlencode($input);

        // Кодировка данных как URI
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $method = $methods[random_int(0, count($methods)-1)];

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json'
        ];

        // Создаем Laravel Request
        $request = Request::create($uri, $method, [], [], [], $headers, json_encode(['data' => $input]));


        if (random_int(0, 1)) {
            $request->cookies->set('token', bin2hex($input));
        }

        // Обработка запроса ядром Laravel
        $response = $kernel->handle($request);

        // Завершение middleware
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        // Бросаем ошибку дальше — фаззер зафиксирует crash
        throw $e;
    }
});
