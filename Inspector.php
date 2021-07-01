<?php

declare(strict_types=1);

namespace Quark\Inspector;

use Illuminate\Support\Collection;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\RedirectResponse;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchPeriod;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class Inspector
{
    public const ROUTE_PREFIX = '/_inspector';

    protected const CACHE_DIR = 'cache/inspector/';

    /** @var Stopwatch */
    private $stopwatch;

    /** @var mixed[] */
    private $currentData;

    /** @var ContainerInterface */
    private $container;

    /** @var string[] */
    private $files;

    public function __construct(Stopwatch $stopwatch, ContainerInterface $container)
    {
        $this->container = $container;
        $this->stopwatch = $stopwatch;

        if (!is_dir($this->basePath(Inspector::CACHE_DIR))) {
            if (!mkdir($concurrentDirectory = $this->basePath(Inspector::CACHE_DIR), 0755, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }
    }

    private function getUri(Request $request): string
    {
        if (null !== $qs = $request->getQueryString()) {
            $qs = '?'.$qs;
        }

        return $request->getPathInfo() . $qs;
    }

    public function saveRequest(Request $request)
    {
        $data = [
            'uri' => $this->getUri($request),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'headers' => $request->headers->all(),
            'cookies' => $request->cookies->all(),
            //                'headers' => $this->headers($request->headers->all()),
            'payload' => $this->input($request), // todo filter some data ?
            //                'session' => $this->payload($this->sessionVariables($event->request)),
            'duration' => $this->durationFromStart(),
            'server' => $request->server->all(),
            'query' => $request->query->all(),
            'request' => $request->request->all(),
            'attributes' => $request->attributes->all(),
//            'files' => $request->files->all(),
        ];

        if ($request instanceof IlluminateRequest) {
            $data['controllerAction'] = optional($request->route())->getActionName();
            $data['middleware'] = array_values(optional($request->route())->gatherMiddleware() ?? []);
        }

        $this->currentData['request'] = $data;
    }

    public function saveResponse(Response $response)
    {
        $this->currentData['response'] = [
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'payload' => $this->response($response),
            'duration' => $this->durationFromStart(),
        ];
    }

    protected function durationFromStart(): ?float
    {
        if (defined('APP_START')) {
            return floor((microtime(true) - APP_START) * 1000);
        }

        if (defined('LARAVEL_START')) {
            return floor((microtime(true) - LARAVEL_START) * 1000);
        }

        return null;
    }

    /**
     * Format the given response object.
     *
     * @return array|string
     */
    protected function response(Response $response)
    {
        $content = $response->getContent();
        if (is_string($content) && is_array(json_decode($content, true)) && json_last_error() === JSON_ERROR_NONE) {
            return $this->contentWithinLimits($content) ? json_decode($content, true) : 'Purged';
        }
        if ($response instanceof RedirectResponse) {
            return 'Redirected to '.$response->getTargetUrl();
        }

        return 'HTML Response';
    }

    /**
     * Determine if the content is within the set limits.
     *
     * @param  string  $content
     * @return bool
     */
    public function contentWithinLimits($content)
    {
        $limit = $this->options['size_limit'] ?? 64;
        return mb_strlen($content) / 1000 <= $limit;
    }

    public function saveEvent($event, $payload = null)
    {
        if (class_exists($event) && isset($payload[0]) && is_object($payload[0])) {
            $info = [
                'class' => get_class($payload[0]),
                'properties' => json_decode(json_encode($payload[0]), true),
            ];
        } else {
            $info = $payload;
        }

        $this->currentData['events'][] = [
            'name' => $event,
            'payload' => $info,
            'core' => $this->isCoreEvent($event),
            'timestamp' => $this->getTimestamp(true),
        ];
    }

    protected function isCoreEvent(string $eventName): bool
    {
        $startWith = ['Illuminate\\', 'eloquent', 'bootstrapped', 'bootstrapping', 'creating', 'composing'];

        foreach ($startWith as $start) {
            if (strpos($eventName, $start) === 0) {
                return true;
            }
        }

        return false;
    }

    private function input(Request $request): ?array
    {
        $files = $request->files->all();
        array_walk_recursive($files, static function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000) . 'KB' : '0',
            ];
        });

        return array_replace_recursive($request->request->all(), $files);
    }

    public function saveData(): void
    {
//        [$msec, $sec] = explode(' ', microtime(), 2);
//        $this->currentData['timeline'] = $this->getStopwatchEvents();
//
//        $msec = (int) ($msec * 100);

        $ts = microtime(true);
        $sec = (int) $ts;
        $msec = (int) (($ts - $sec) * 10000);

        $this->currentData['timeline'] = $this->getStopwatchEvents();


        file_put_contents(
            sprintf('%s%d_%04d', $this->basePath(self::CACHE_DIR), $sec, $msec),
            json_encode($this->currentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function getFiles(): Collection
    {
        if ($this->files) {
            // Cached files
            return $this->files;
        }

        $this->files = collect(glob($this->basePath(self::CACHE_DIR) . '*', GLOB_NOSORT));
        return $this->files->sort()->reverse();
    }

    public function getFileNames(): Collection
    {
        return $this->getFiles()->map(function ($f) {
            return basename($f);
        });
    }

    protected function timestampFromFile(string $filename): float
    {
        return (float) str_replace('_', '.', $filename);
    }

    public function readInfo($timestamp)
    {
        $file = $this->basePath(self::CACHE_DIR) . basename($timestamp);
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }
        $data = file_get_contents($file);
        return json_decode($data);
    }

    /**
     * @param bool $micro
     * @return int|float
     * @throws \Exception
     */
    private function getTimestamp(bool $micro = false)
    {
        if ($micro) {
            return microtime(true);
        }
        return (new \DateTime())->getTimestamp();
    }

    public function getStopwatchEvents(): array
    {
        $inspectorData = [];
        $min = PHP_INT_MAX;
        $max = 0;

        foreach ($this->stopwatch->getSections() as $section) {
            foreach ($section->getEvents() as $eventName => $event) {
                $origin = $event->getOrigin();

                $inspectorData['timeline'][$eventName] = [
                    'sect' => $section->getId(),
                    'cat' => $event->getCategory(),
                    'mem' => $event->getMemory(),
                    'orig' => $origin,
                    'start' => $event->getStartTime(),
                    'end' => $event->getEndTime(),
                    'duration' => $event->getDuration(),
                    'periods' => count($event->getPeriods()) > 1 ? array_map(static function (StopwatchPeriod $per) {
                        return [
                            'mem' => $per->getMemory(),
                            'start' => $per->getStartTime(),
                        ];
                    }, $event->getPeriods()) : [],
                ];

                if ($origin < $min) {
                    $min = $origin;
                }

                if ($origin + $event->getDuration() > $max) {
                    $max = $origin + $event->getDuration();
                }
            }
        }

        $inspectorData['timeMax'] = $max; // total duration
        $inspectorData['timeMin'] = $min; // total duration

        return $inspectorData;
    }

    private function basePath($path = ''): string
    {
        if ($this->container->has('path.base')) {
            return $this->container->get('path.base') . '/' . $path;
        }

        if (function_exists('base_path')) {
            return base_path($path);
        }

        return '../' . $path; // From `public/`
    }
}
