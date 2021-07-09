<?php

declare(strict_types=1);

namespace Quark\Inspector;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class Inspector
{
    public const ROUTE_PREFIX = '/_inspector';

    private const CACHE_DIR = 'cache/inspector/';

    private ContainerInterface $container;

    /** @var string[] */
    private array $files = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        if (!is_dir($this->basePath(Inspector::CACHE_DIR))) {
            if (!mkdir($concurrentDirectory = $this->basePath(Inspector::CACHE_DIR), 0755, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }
    }

    /**
     * Determine if the content is within the set limits.
     */
    public function contentWithinLimits(string $content): bool
    {
        $limit = $this->options['size_limit'] ?? 64;

        return mb_strlen($content) / 1000 <= $limit;
    }

    public function saveData(Collector $collector): void
    {
//        [$msec, $sec] = explode(' ', microtime(), 2);
//        $this->currentData['timeline'] = $this->getStopwatchEvents();
//
//        $msec = (int) ($msec * 100);

        $ts = microtime(true);
        $sec = (int) $ts;
        $msec = (int) (($ts - $sec) * 10000);

        file_put_contents(
            sprintf('%s%d_%04d', $this->basePath(self::CACHE_DIR), $sec, $msec),
            json_encode($collector->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function getFiles(): array
    {
        if (count($this->files) !== 0) {
            // Cached files
            return $this->files;
        }

        $this->files = glob($this->basePath(self::CACHE_DIR) . '*', GLOB_NOSORT);
        rsort($this->files);

        return $this->files;
    }

    /**
     * @return string[]
     */
    public function getFileNames(): array
    {
        return array_map(static fn ($f) => basename($f), $this->getFiles());
    }

    public function readInfo($timestamp): object
    {
        if ($timestamp === 'latest') {
            $timestamp = $this->getLatestToken();
        }

        $file = $this->basePath(self::CACHE_DIR) . basename($timestamp);
        if (!is_file($file)) {
            throw new FileNotFoundException($file);
        }
        $data = file_get_contents($file);

        $dataObj = json_decode($data, false, 256, JSON_THROW_ON_ERROR);
        $dataObj->timestamp = $timestamp;

        return $dataObj;
    }

    public function getLatestToken(): ?string
    {
        return $this->getFileNames()[0] ?? null;
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
