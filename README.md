# Simple HTTP Server

Simple HTTP Server based on Symfony/HttpFoundation w/o concurrency in PHP

## Install

With _Composer_

```bash
composer require brzuchal/simple-http-server
```

## Usage

Simple usage:

```php
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
```

## License

MIT License

Copyright (c) 2017 Michał Brzuchalski <michal.brzuchalski@gmail.com>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
