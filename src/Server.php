<?php declare(strict_types=1);
namespace Brzuchal\SimpleHTTPServer;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Server
{
    private const SERVER_SOFTWARE = 'PHP ' . \PHP_VERSION . ' ' . self::class;
    private $socket;
    /** @var bool */
    private $debug;
    private $terminate = false;
    /** @var ?string */
    private $documentRoot;

    public function __construct(string $host, int $port, bool $debug)
    {
        $this->attachSignals();
        $this->createSocketServer($host, $port);
        $this->logPID($host, $port);
        $this->debug = $debug;
    }

    public function documentRoot(string $directory) : self
    {
        if (!\file_exists($directory) && !\is_dir($directory)) {
            throw new \RuntimeException(
                "Unable to set document root: {$directory} doesn't exiusts or is not a directory"
            );
        }
        $this->documentRoot = $directory;

        return $this;
    }

    private function handleRequest(Request $request, callable $callback, $clientSocket) : void
    {
        if (
            \in_array($request->getMethod(), ['GET', 'HEAD'], true) &&
            !empty($this->documentRoot) &&
            \file_exists($filepath = ($this->documentRoot . $request->getPathInfo())) &&
            \is_file($filepath)
        ) {
            $size = $this->handleStaticFile($clientSocket, $filepath, $request->getMethod() === 'HEAD');
            $this->debug && $this->log("{$request->getMethod()} {$request->getPathInfo()} 200 {$size}");
        } else {
            try {
                $response = $callback($request);
            } catch (\Exception $exception) {
                $response = new Response("<h1>Internal Server Error</h1><p>{$exception->getMessage()}</p>", 500);
            } finally {
                $this->debug && $this->log("{$request->getMethod()} {$request->getPathInfo()} {$response->getStatusCode()}");
                $this->handleResponse($clientSocket, $response);
            }
        }
    }

    private function createServer($clientSocket) : array
    {
        $server = [];
        $peerName = \stream_socket_get_name($clientSocket, true);
        $portStartPos = \strrpos($peerName, ':');
        $server['REMOTE_ADDR'] = \substr($peerName, 0, $portStartPos);
        $server['REMOTE_PORT'] = \substr($peerName, $portStartPos + 1);
        $serverName = \stream_socket_get_name($clientSocket, false);
        $serverPortStartPos = \strrpos($serverName, ':');
        $server['SERVER_ADDR'] = \substr($serverName, 0, $serverPortStartPos);
        $server['SERVER_PORT'] = \substr($serverName, $serverPortStartPos + 1);
        $server['SERVER_SOFTWARE'] = self::SERVER_SOFTWARE;
        $time = new \DateTime('now');
        $server['REQUEST_TIME_FLOAT'] = $time->format('U.u');
        $server['REQUEST_TIME'] = $time->format('U');

        return $server;
    }

    public function run(callable $callback) : void
    {
        while (true) {
            try {
                if ($this->terminate) {
                    break;
                }
                if (!$clientSocket = @\stream_socket_accept($this->socket, $timeout = 1)) {
                    continue;
                }
                $buffer = \stream_socket_recvfrom($clientSocket, 8192);
                $meta = \stream_get_meta_data($clientSocket);
                if (true === $meta['timed_out']) {
                    $response = new Response('', 408);
                    $this->handleResponse($clientSocket, $response);
                } else {
                    if (empty($buffer)) {
                        $response = new Response('', 400);
                        $this->handleResponse($clientSocket, $response);
                    } else {
                        $request = $this->parseRequest($buffer, $this->createServer($clientSocket));
                        $this->handleRequest($request, $callback, $clientSocket);
                    }
                }
                \fclose($clientSocket);
            } catch (\Throwable $error) {
                $this->error("[internal] Error: {$error->getMessage()} on {$error->getLine()}");
            } catch (\Exception $exception) {
                $this->error("[internal] Exception: {$exception->getMessage()}");
            }
        }
    }

    public function parseRequest(string $raw, array $server = []) : Request
    {
        $lines = \explode("\r\n", $raw);
        $count = \count($lines);
        $headers = [];
        $method = 'GET';
        $uri = '/';
        $http = 1.0;
        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (0 === $i) {
                list($method, $uri, $http) = \sscanf($line, '%s %s HTTP/%f');
                continue;
            }
            if (empty($line) && $i + 1 < $count) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                $line = $lines[$i + 1];

                $path = \parse_url($uri, PHP_URL_PATH);
                $params = [];
                if (null !== $query = \parse_url($uri, PHP_URL_QUERY)) {
                    \parse_str($query, $params);
                }
                $server['SERVER_PROTOCOL'] = "HTTP/{$http}";
                $server['REQUEST_URI'] = $uri;
                $server['REQUEST_METHOD'] = $method;
                $server['QUERY_STRING'] = $query;
                $cookies = [];
                foreach ($headers as $name => $value) {
                    if ($name === 'cookie') {
                        foreach (\explode('; ', $value) as $cookie){
                            if (\preg_match('/^(.*?)=(.*?)$/', \trim($cookie), $matches)) {
                                $cookies[\trim($matches[1])] = \urldecode($matches[2]);
                            }
                        }
                    }
                    $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
                }
                return Request::create($path, $method, $params, $cookies, [], $server, null);
            }
            if (!empty($line)) {
                list($name, $value) = \explode(': ', $line);
                $headers[\strtolower($name)] = $value;
            }
        }
        if ($i > 0 && \is_string($method)) {
            $path = \parse_url($uri, PHP_URL_PATH);
            \parse_str(\parse_url($uri, PHP_URL_QUERY), $params);
            return Request::create($path, $method, $params, $headers['Cookie'], [], [], null);
        }

        throw new \RuntimeException('Malformed request');
    }

    private function log(string $msg, string $color = "\033[0;32m")
    {
        \printf("%s[%s] %s\033[0m\n", $color, \date('Y-m-d H:i:s'), $msg);
    }

    private function error(string $msg)
    {
        $this->log($msg, "\033[0;31m");
    }

    private function attachSignals() : void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGTERM, function () {
                $this->terminate = true;
                $this->log('[internal] HTTP Server gracefully terminating by SIGTERM');
            });
        }
    }

    private function logPID(string $host, int $port) : void
    {
        if (\function_exists('posix_getpid')) {
            $pid = \posix_getpid();
            $this->log("[internal] HTTP Server started on {$host}:{$port} [PID:{$pid}]");
        }
    }

    private function createSocketServer(string $host, int $port) : void
    {
        $context = \stream_context_create([
            'socket' => [
                'so_reuseport' => true,
            ],
        ]);
        $this->socket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errNo,
            $errStr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context
        );
        if (false === $this->socket) {
            $this->error($errStr);
        }
        \stream_set_blocking($this->socket, false);
    }

    private function handleStaticFile($clientSocket, $filepath, $headOnly = false) : int
    {
        $mime = \mime_content_type($filepath);
        $size = \filesize($filepath);
        $date = new \DateTime('now');
        $lastModified = \DateTime::createFromFormat('U', (string)\filemtime($filepath));
        $etag = \sha1($lastModified->format(\DateTime::RFC822));
        \stream_socket_sendto(
            $clientSocket,
            "HTTP/1.1 200 OK\r\n" .
            "Date: {$date->format(\DateTime::RFC822)}\r\n" .
            "Last-Modified: {$lastModified->format(\DateTime::RFC822)}\r\n" .
            "Etag: {$etag}\r\n" .
            "Content-Type: {$mime}\r\n" .
            "Content-Length: {$size}\r\n" .
            "Connection: Closed\r\n\r\n"
        );
        if (false === $headOnly) {
            $fh = \fopen($filepath, 'r+');
            \stream_copy_to_stream($fh, $clientSocket);
            \fclose($fh);
        }

        return $size;
    }

    private function handleResponse($clientSocket, Response $response) : void
    {
        $date = new \DateTime('now');
        $response->headers->set('Date', $date->format(\DateTime::RFC822));
        \stream_socket_sendto($clientSocket, (string)$response);
    }
}
