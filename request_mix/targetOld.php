<?php

use Illuminate\Http\UploadedFile;
use PhpFuzzer\Config;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Подключаем автозагрузчик
require __DIR__ . '/../vendor/autoload.php';

// Создаем Laravel приложение
$app = require __DIR__ . '/../vendor/laravel/laravel/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
/** @var \PhpFuzzer\Config $config*/



function generateFuzzedRequest(string $input): Request
{
    // 1. HTTP-метод
    $methods = ['GET','POST','PUT','PATCH','DELETE','OPTIONS'];
    $method  = $methods[random_int(0, count($methods) - 1)];

    // 2. URI-путь (с рандомными сегментами и параметрами)
    $pathSegs = array_map('rawurlencode', str_split($input, 3));
    $uri      = '/' . implode('/', array_slice($pathSegs, 0, 3));
    if (random_int(0,1)) {
        $uri .= '?' . rawurlencode($input) . '=' . rawurlencode($input);
    }

    // 3. Host
    $hosts = ['example.com','api.local','fuzzer.test'];
    $host  = $hosts[random_int(0, count($hosts)-1)];

    // 4. Протокол
    $protocols = ['HTTP/1.0','HTTP/1.1','HTTP/2'];
    $server    = [
        'SERVER_PROTOCOL'  => $protocols[random_int(0, count($protocols)-1)],
        'SERVER_NAME'      => $host,
        'REMOTE_ADDR'      => '127.0.0.' . random_int(1,254),
    ];

    // 5. Заголовки
    $headers = [
        'CONTENT_TYPE'    => ['application/json','application/x-www-form-urlencoded','text/plain'][random_int(0,2)],
        'HTTP_ACCEPT'     => ['*/*','application/json','text/html'][random_int(0,2)],
        'HTTP_USER_AGENT' => 'FuzzerBot/' . random_int(1,100),
        'HTTP_ACCEPT_ENCODING' => ['gzip, deflate','br',''][random_int(0,2)],
        'HTTP_ACCEPT_LANGUAGE' => ['en-US','ru-RU','de-DE'][random_int(0,2)],
        'HTTP_REFERER'    => 'https://' . $host . '/ref?src=' . rawurlencode($input),
        'HTTP_AUTHORIZATION' => 'Bearer ' . bin2hex($input),
        'X-CSRF-TOKEN'    => bin2hex($input),
    ];

    // 6. Query-параметры как массивы
    parse_str(rawurlencode($input) . '=' . rawurlencode($input) . '&arr[]=' . rawurlencode($input), $query);

    // 7. Тело запроса (JSON / form-data / raw)
    if (in_array($method, ['POST','PUT','PATCH'], true)) {
        if ($headers['CONTENT_TYPE'] === 'application/json') {
            $body = json_encode(['data'=>$input,'items'=>[$input,$input]], JSON_THROW_ON_ERROR);
        } elseif (random_int(0,1)) {
            // multipart/form-data
            $body = "--fuz\r\nContent-Disposition: form-data; name=\"data\"\r\n\r\n{$input}\r\n--fuz--";
            $server['CONTENT_TYPE'] .= '; boundary=fuz';
        } else {
            $body = http_build_query($query);
        }
    } else {
        $body = '';
    }

    // 8. Куки
    $cookies = ['session_id'=>bin2hex(random_bytes(4))];
    if (random_int(0,2) === 0) {
        $cookies['fuzzer_token'] = sha1($input);
    }

    // 9. Сессия
    $session = ['_token'=>bin2hex(random_bytes(8))];
    if (random_int(0,2) === 1) {
        $session['user_lang'] = ['en','ru','de'][random_int(0,2)];
    }

    // 10. Файлы
    $files = [];
    if (random_int(0,3) === 0) {
        $tmp = tempnam(sys_get_temp_dir(),'fz');
        file_put_contents($tmp, $input);
        $files['upload'] = new UploadedFile($tmp,'fuzz.txt','application/octet-stream',null,true);
    }

    // 11. Серверные переменные (X-Forwarded-For, X-Requested-With)
    if (random_int(0,1)) {
        $server['HTTP_X_FORWARDED_FOR']   = '192.168.0.' . random_int(2,254);
        $server['HTTP_X_REQUESTED_WITH']  = 'XMLHttpRequest';
    }

    // 12. Принудительный cookie-лидарбордер
    if (random_int(0,2) === 2) {
        $cookies['__stripe_mid'] = bin2hex(random_bytes(6));
    }

    // Собираем Request
    $request = Request::create(
        $uri,
        $method,
        $query,
        $cookies,
        $files,
        array_merge($server, $headers),
        $body
    );

    // Привязываем фейковую сессию
    $request->setLaravelSession(new \Illuminate\Session\Store('fuzz', new \Illuminate\Session\ArraySessionHandler(1)));
    foreach ($session as $k=>$v) {
        $request->session()->put($k,$v);
    }

    return $request;
}


// Настройка цели фаззинга
$config->setTarget(function (string $input) use ($kernel) {
    try {
        $request = generateFuzzedRequest($input);
        // Обработка запроса ядром Laravel
        $response = $kernel->handle($request);

        // Завершение middleware
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        // Бросаем ошибку дальше — фаззер зафиксирует crash
        throw $e;
    }
});
