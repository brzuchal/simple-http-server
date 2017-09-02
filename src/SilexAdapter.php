<?php declare(strict_types=1);
namespace Brzuchal\SimpleHTTPServer;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SilexAdapter
{
    /**
     * @var \Silex\Application
     */
    private $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function __invoke(Request $request) : Response
    {
        $response = $this->application->handle($request);
        $this->application->terminate($request, $response);

        return $response;
    }
}
