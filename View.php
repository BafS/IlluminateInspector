<?php

declare(strict_types=1);

namespace Quark\Profiler;

class View
{
    /** @var string */
    private $dir;

    /** @var array */
    private $data;

    public function __construct(string $dir, array $data = [])
    {
        $this->dir = rtrim($dir, '/') . '/';

        $this->data = $data;
    }

    public function render(string $template, array $data = []): string
    {
        extract($data);
        ob_start();
        include $this->dir . str_replace('..', '', $template) . '.html';
        return ob_get_clean();
    }

    public function __get($name)
    {
        return $this->data[$name] ?? null;
    }

    public function __call($method, $args)
    {
        return ($this->data[$method] ?? function () {})(...$args);
    }
}
