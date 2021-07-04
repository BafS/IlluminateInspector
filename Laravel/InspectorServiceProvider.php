<?php

declare(strict_types=1);

namespace Quark\Inspector\Laravel;

use Quark\Inspector\Collector;
use Quark\Inspector\Controllers\InspectorController;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Quark\Inspector\Inspector;
use Symfony\Component\Stopwatch\Stopwatch;

class InspectorServiceProvider extends ServiceProvider
{
    protected string $routeName = 'inspector';

    protected bool $isExcludedRoute = false;

    /** @var string[] */
    protected array $eventNames = [
        'handled' => RequestHandled::class,
        'matched' => RouteMatched::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerRoutes();

        $this->app->singleton(Inspector::class);

        $this->app->singleton(Collector::class);

        $this->app->singleton(Stopwatch::class, fn () => new Stopwatch(true));
    }

    public function boot(): void
    {
        if ($this->app->has('events')) {
            $events = $this->app->get('events');
        } else {
            $events = $this->app->get(Dispatcher::class);
        }

        $collector = $this->app->get(Collector::class);

        // Listen events
        $events->listen($this->eventNames['handled'], function (RequestHandled $event) use ($collector) {
            $this->isExcludedRoute = $this->isExcludedRequest($event->request);

            $collector->saveRequest($event->request);
            $collector->saveResponse($event->response);

            $this->app->get(Stopwatch::class)->start('request.handled', 'routing')->stop();
        });

        $events->listen($this->eventNames['matched'], function (RouteMatched $event) use ($collector) {
            $this->isExcludedRoute = $this->isExcludedRequest($event->request);

            $collector->saveRequest($event->request);

            $this->app->get(Stopwatch::class)->start('route.matched', 'routing')->stop();
        });

        $events->listen('*', function ($eventName, array $data) use ($collector) {
            if (in_array($eventName, $this->eventNames, true)) {
                return;
            }

            $this->app->get(Stopwatch::class)->start($eventName, 'events')->stop($eventName);
            $collector->saveEvent($eventName, $data);
        });

        $this->app->terminating(function (Inspector $inspector, Collector $collector) {
            // Don't save data if we are on the inspector
            if ($this->isExcludedRoute) {
                return;
            }

            $inspector->saveData($collector);
        });
    }

    private function isExcludedRequest(Request $request): bool
    {
        $name = $request->route() ? $request->route()->getName() : null;
        $uri = $request->getRequestUri();

        return Str::startsWith($name, $this->routeName)
            || Str::startsWith($uri, Inspector::ROUTE_PREFIX)
            || Str::contains($uri, config('inspector.routes_to_exclude', []));
    }

    private function registerRoutes(): void
    {
        // Register a special route for the inspector
        $router = $this->app->get('router');

        $router
            ->get(Inspector::ROUTE_PREFIX, [InspectorController::class, 'indexActivity'])
            ->name("{$this->routeName}.activity.index");
        $router
            ->get(Inspector::ROUTE_PREFIX . '/env', [InspectorController::class, 'indexEnvironment'])
            ->name("{$this->routeName}.environment.index");
        $router
            ->get(Inspector::ROUTE_PREFIX . '/api', [InspectorController::class, 'indexActivityRest'])
            ->name("{$this->routeName}.api.activity.index");
        $router
            ->get(Inspector::ROUTE_PREFIX . '/{token}', [InspectorController::class, 'showActivity'])
            ->name("{$this->routeName}.activity.show");
    }
}
