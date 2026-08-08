<?php

namespace Tests\Guards\Concerns;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shared file-scanning helper for guard tests. Guards assert PRD §7.1
 * decisions hold across the codebase by grepping source, not by unit-testing
 * a single class — see PRD §9: "written prose is a request, not a guarantee."
 */
trait ScansSourceFiles
{
    /**
     * @return array<string, string> relative path => file contents
     */
    protected function phpFilesIn(string $absoluteDir): array
    {
        if (! is_dir($absoluteDir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $files[str_replace('\\', '/', $relative)] = file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
