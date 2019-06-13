<?php

declare(strict_types=1);

namespace Quark\Profiler;

use Quark\Profiler\Controllers\ProfilerController;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\Stopwatch\Stopwatch;

class ProfilerServiceProvider extends ServiceProvider
{
    protected $routeName = 'profiler';

    protected $isProfilerRoute = false;

    /** @var string[] */
    protected $eventNames = [
        'handled' => RequestHandled::class,
        'matched' => RouteMatched::class,
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerRoutes();

        $this->app->singleton(Profiler::class);

        // Register Stopwatch
        $this->app->singleton(Stopwatch::class, function () {
            return new Stopwatch(true);
        });
    }

    public function boot()
    {
        if ($this->app->has('events')) {
            $events = $this->app->get('events');
        } else {
            $events = $this->app->get(Dispatcher::class);
        }
        $this->app->singleton(Profiler::class);

        $profiler = $this->app->get(Profiler::class);

        // Listen events
        $events->listen($this->eventNames['handled'], function (RequestHandled $event) use ($profiler) {
            Str::startsWith($event->request->getRequestUri(), '/_profiler') && $this->isProfilerRoute = true;

            $profiler->saveRequest($event->request);
            $profiler->saveResponse($event->response);

            $this->app->get(Stopwatch::class)->start('request.handled', 'routing')->stop('request.handled');
        });

        $events->listen($this->eventNames['matched'], function (RouteMatched $event) use ($profiler) {
            Str::startsWith($event->request->getRequestUri(), '/_profiler') && $this->isProfilerRoute = true;

            $profiler->saveRequest($event->request);

            $this->app->get(Stopwatch::class)->start('route.matched', 'routing')->stop('route.marched');
        });

        $events->listen('*', function ($eventName, array $data) use ($profiler) {
            if (in_array($eventName, [RouteMatched::class, RequestHandled::class], true)) {
                return;
            }

            $this->app->get(Stopwatch::class)->start($eventName, 'events')->stop($eventName);
            $profiler->saveEvent($eventName, $data);
        });

        $this->app->terminating(function (Profiler $profiler) {
            // Don't save data if we are on the profiler
            if ($this->isProfilerRoute) {
                return;
            }

            $profiler->saveData();
        });
    }

    private function registerRoutes()
    {
        // Register a special route for the profiler
        $router = $this->app->get('router');
        $router
            ->get(Profiler::ROUTE_PREFIX, [ProfilerController::class, 'indexActivity'])
            ->name($this->routeName);
        $router
            ->get(Profiler::ROUTE_PREFIX . '/env', [ProfilerController::class, 'indexEnvironment'])
            ->name($this->routeName);
        $router
            ->get(Profiler::ROUTE_PREFIX . '/api', [ProfilerController::class, 'indexActivityRest'])
            ->name($this->routeName);
        $router
            ->get(Profiler::ROUTE_PREFIX . '/{token}', [ProfilerController::class, 'showActivity'])
            ->name($this->routeName);
    }
}
