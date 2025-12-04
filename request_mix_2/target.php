<?php

use Illuminate\Http\UploadedFile;
use PhpFuzzer\Config;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Fruitcake\Cors\CorsService;

// Подключаем автозагрузчик
require __DIR__ . '/../vendor/autoload.php';

// Создаем Laravel приложение
$app = require __DIR__ . '/../vendor/laravel/laravel/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
/** @var \PhpFuzzer\Config $config*/

function generateFuzzedRequest(string $input): Request
{
    // HTTP-метод
    $methods = ['GET','POST','PUT','PATCH','DELETE','OPTIONS'];
    $method  = $methods[random_int(0, count($methods)-1)];

    // URI: несколько сегментов + разные кодировки
    $segs = array_map('rawurlencode', str_split($input, 4));
    $path = '/' . implode('/', array_slice($segs, 0, 3));
    if (random_int(0,1)) {
        $path .= '?q=' . rawurlencode($input);
    }

    // Заголовки + CORS
    $headers = [
        'CONTENT_TYPE'           => ['application/json','text/plain','multipart/form-data'][random_int(0,2)],
        'HTTP_ACCEPT'            => ['*/*','application/json','text/html'][random_int(0,2)],
        'HTTP_USER_AGENT'        => 'FuzzerBot/'.random_int(1,100),
        'HTTP_ORIGIN'            => ['https://evil.com','https://api.local'][random_int(0,1)],
        'HTTP_ACCEPT_ENCODING'   => ['gzip, deflate','br',''][random_int(0,2)],
        'HTTP_X_FORWARDED_FOR'   => '192.168.0.'.random_int(1,254),
        'SERVER_PROTOCOL'        => ['HTTP/1.0','HTTP/1.1','HTTP/2'][random_int(0,2)],
    ];

    // Серверные переменные
    $server = [
        'SERVER_NAME' => ['app.local','api.test'][random_int(0,1)],
        'REMOTE_ADDR' => '127.0.0.'.random_int(1,254),
    ];

    // Тело запроса
    $body = '';
    if (in_array($method, ['POST','PUT','PATCH'], true)) {
        if ($headers['CONTENT_TYPE'] === 'application/json') {
            $body = json_encode(['data'=>$input], JSON_THROW_ON_ERROR);
        } elseif ($headers['CONTENT_TYPE'] === 'multipart/form-data') {
            $boundary = '----FUZ'.random_int(1,9999);
            $headers['CONTENT_TYPE'] .= "; boundary={$boundary}";
            $body = "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"payload\"\r\n\r\n"
                . $input . "\r\n"
                . "--{$boundary}--";
        } else {
            $body = 'payload='.rawurlencode($input);
        }
        // Вариация Content-Length
        $headers['CONTENT_LENGTH'] = strlen($body) . random_int(0,5) . '';
    }

    // Куки и сессия
    $cookies = ['fuzz_session'=>sha1($input)];
    $files   = [];
    if (random_int(0,4) === 0) {
        $tmp = tempnam(sys_get_temp_dir(), 'fz');
        file_put_contents($tmp, $input);
        $files['upload'] = new UploadedFile($tmp, 'fuzz.bin', 'application/octet-stream', null, true);
    }

    // Сборка самого Request
    $req = Request::create(
        $path,
        $method,
        [],      // query
        $cookies,
        $files,
        array_merge($server, $headers),
        $body
    );

    // Привязываем «фазз-сессию»
    $req->setLaravelSession(
        new \Illuminate\Session\Store('fuzz', new \Illuminate\Session\ArraySessionHandler(1))
    );
    $req->session()->put('locale', ['en','ru','de'][random_int(0,2)]);

    return $req;
}


// Настройка цели фаззинга
$config->setTarget(function (string $input) use ($kernel) {
    try {
        $request = generateFuzzedRequest($input);

        // — случайно включаем PJAX
        if (random_int(0,1)) {
            $request->headers->set('X-PJAX', 'true');
        }

        // — случайно префетч
        switch (random_int(0,3)) {
            case 1: $request->server->set('HTTP_X_MOZ','prefetch'); break;
            case 2: $request->headers->set('Purpose','prefetch'); break;
            case 3: $request->headers->set('Sec-Purpose','prefetch'); break;
        }

        // — случайно HTTPS
        if (random_int(0,1)) {
            $request->server->set('HTTPS','on');
        }

        $request->ajax();
        $request->pjax();
        $request->prefetch();
        $request->secure();

        // Пропускаем через CORS
        $corsService = new CorsService();
        $corsService->handlePreflightRequest($request, function ($req){
            return new \Symfony\Component\HttpFoundation\Response('', 200);
        });

        // Пропускаем через стандартный Kernel
        $response = $kernel->handle($request);

        // Дополнительно дергаем методы Request,
        // чтобы повысить покрытие Illuminate\Http\Request
        $request->ip();
        $request->path();
        $request->url();
        $request->fullUrl();
        $request->fullUrlWithQuery($request->query());
        $request->root();
        $request->segments();
        $request->isMethod($request->method());
        $request->wantsJson();
        $request->expectsJson();
        $request->header('Accept');
        $request->all();
        $request->query();
        $request->post();
        $request->cookie('fuzz_session');
        $request->has('payload');
        if ($request->isJson()) {
            $request->json()->all();
        }

        // IPs, UA
        $request->ips();
        $request->userAgent();

        // merge / mergeIfMissing / replace
        $request->merge(['alpha' => 'β']);
        $request->mergeIfMissing(['gamma' => 'δ']);
        $request->replace(['x'=>1,'y'=>2]);

        // Symfony-специфичный get()
        $request->get('nosuch','def');

        // duplicate
        $dup = $request->duplicate();
        $dup->getMethod();

        // Завершение middleware
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        file_put_contents(
            './log.txt',
            "Exception: " . $e->getMessage() . "\n" .
            "File: " . $e->getFile() . ":" . $e->getLine() . "\n" .
            "Trace:\n" . $e->getTraceAsString() . "\n",
            FILE_APPEND
        );
        throw $e;
    }
});

