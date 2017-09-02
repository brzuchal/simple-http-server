<?php declare(strict_types = 1);

use Brzuchal\SimpleHTTPServer\Server;
use Brzuchal\SimpleHTTPServer\SilexAdapter;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/vendor/autoload.php';

$app = new Application(['debug' => true]);
$app->get('/', function (Request $request) use ($app) {
    return new Response('<h1>Hello World!</h1><p>Hello from Silex v' . $app::VERSION . '</p>');
});

$server = new Server('0.0.0.0', $argc > 1 ? (int)$argv[1] : 80, $argc > 2 ? (bool)($argv[2] === '-v') : false);
$server->documentRoot(__DIR__);
$server->run(new SilexAdapter($app));
