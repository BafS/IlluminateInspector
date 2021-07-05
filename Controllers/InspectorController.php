<?php

declare(strict_types=1);

namespace Quark\Inspector\Controllers;

use DateTime;
use Quark\Inspector\Inspector;
use Quark\Inspector\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\AbstractDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

class InspectorController
{
    private const VIEWS_DIR = __DIR__ . '/../resources/views/';

    private Inspector $inspector;

    public function __construct(Inspector $inspector)
    {
        $this->inspector = $inspector;
    }

    public function indexActivity(Request $req): Response
    {
        $names = $this->inspector->getFileNames();

        $last = $req->query->getInt('last', 10);

        $forPage = static fn ($data, int $perPage) => array_slice($data, 0, $perPage);

        $last > 0 && $names = $forPage($names, $last);

        $activities = [];
        foreach ($names as $file) {
            $data = $this->inspector->readInfo($file);
            $activities[$file] = $data;
        }

        return $this->render($req, 'activity.index', [
            'meta' => ['last' => $last],
            'activities' => $activities,
        ]);
    }

    public function showActivity(Request $req, $token): Response
    {
        $panel = $req->query->get('panel', 'activity');

        try {
            $info = $this->inspector->readInfo($token);
        } catch (RuntimeException $e) {
            return $this->render($req, '404');
        }

        if (substr($panel, -1, 1) === 's') {
            $name = substr($panel, 0, -1) . '.index';
        } else {
            $name = $panel . '.show';
        }

        $extra = [];
        if ($panel === 'event' && $req->query->has('n')) {
            $n = $req->query->getInt('n');
            $extra = [
                'event' => $info->events[$n],
            ];
        }

        // Check if exists ?
        return $this->render($req, $name, [
//            'info' => $info,
            'request' => $info->request ?? null,
            'response' => $info->response ?? null,
            'events' => $info->events ?? [],
            'timeline' => $info->timeline ?? null,
            'token' => $token,
        ] + $extra);
    }

    public function showTimeline(Request $req, $token): Response
    {
        $data = $this->inspector->readInfo($token);

        return $this->render($req, 'profiler.show', [
            'id' => $token,
            'request' => $data->request ?? null,
            'timeline' => $data->timeline ?? null,
        ]);
    }

    public function showRequest(Request $req, $token): Response
    {
        $info = $this->inspector->readInfo($token);

        return $this->render($req, 'request.show', [
            'request' => $info->request ?? null,
            'response' => $info->response ?? null,
            'id' => $token,
        ]);
    }

    public function showEvent(Request $req, $token): Response
    {
        $events = $this->inspector->readInfo($token)->events ?? [];

        $event = $events[$req->query->get('n', 0)] ?? null;

        return $this->render($req, 'event.show', [
            'event' => $event,
        ]);
    }

    public function indexEnvironment(Request $req): Response
    {
        $variables = getenv();

        // Some parts are taken from
        // https://github.com/symfony/symfony/blob/5aa0967f9f0ab803ededefb040d48a0ebc7a27a6/src/Symfony/Component/HttpKernel/DataCollector/ConfigDataCollector.php
        return $this->render($req, 'env.index', [
            'variables' => $variables,
            'extensions' => get_loaded_extensions() ?? [],
            'phpTimezone' => date_default_timezone_get(),
            'phpVersion' => PHP_VERSION,
            'sapiName' => \PHP_SAPI,
            'phpArchitecture' => PHP_INT_SIZE * 8,
            'phpIntlLocale' => class_exists('Locale', false) && \Locale::getDefault() ? \Locale::getDefault() : 'n/a',
            'xdebugEnabled' => \extension_loaded('xdebug'),
            'apcuEnabled' => \extension_loaded('apcu') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN),
            'zendOpcacheEnabled' => \extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN),
            'iniFiles' => explode(',', php_ini_scanned_files() ?? ''),
        ]);
    }

    protected function since($timestamp): string
    {
        $ago = new DateTime();
        $ago->setTimestamp((int) $timestamp);
        $diff = (new DateTime())->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'min',
//            's' => 'sec',
        ];

        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    protected function render(Request $req, string $page, array $data = []): Response
    {
        $view = (new View(static::VIEWS_DIR, [
            'uri' => $req->getUriForPath(Inspector::ROUTE_PREFIX),
            'isPanel' => function ($panel, string $output = null, string $default = '') use ($req) {
                $panel = (array) $panel;

                $res = in_array($req->query->get('panel'), $panel, true);

                if ($output !== null) {
                    return $res ? $output : $default;
                }

                return $res;
            },
            'date' => function ($timestamp): string {
                if (is_string($timestamp) && strpos($timestamp, '_') !== false) {
                    $timestamp = (float) str_replace('_', '.', $timestamp);
                }
                $date = (new \DateTime())->setTimestamp((int) $timestamp);
                return $date->format('Y-m-d H:i:s');
            },
            'since' => function ($timestamp): string {
                if (strpos($timestamp, '_') !== false) {
                    $timestamp = (float) str_replace('_', '.', $timestamp);
                }
                return $this->since($timestamp);
            },
            'lastToken' => function () {
                return $this->inspector->getFileNames()[0] ?? null;
            },
            'dump' => function ($variable, bool $light = false): void {
                $var = (new VarCloner())->cloneVar($variable);
                $dumper = new HtmlDumper(null, null, AbstractDumper::DUMP_LIGHT_ARRAY);
//                    $dumper->setTheme('light');
                $dumper->dump($var, null, [
                    // 1 and 160 are the default values for these options
                    'maxDepth' => 3,
                    'maxStringLength' => 160,
                ]);
            },
        ]))->render('layout', ['page' => $page, 'token' => $data['token'] ?? null, 'data' => $data]);

        return new Response($view);
    }
}
