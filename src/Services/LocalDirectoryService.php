<?php

namespace Services;

class LocalDirectoryService
{
    /**
     * Læser og scanner filer fra en angivet lokal mappe på serveren
     */
    public static function readDirectoryContext(string $dirPath, array $allowedExtensions = ['md', 'txt', 'php', 'js', 'json', 'sql', 'css', 'html']): string
    {
        if (!is_dir($dirPath) || !is_readable($dirPath)) {
            return "Fejl: Mappen '{$dirPath}' findes ikke eller er ikke læsbar.";
        }

        $contextOutput = "";
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $allowedExtensions)) {
                    $relativePath = str_replace($dirPath, '', $file->getPathname());
                    
                    if (preg_match('/(\\/|^)(\.git|node_modules|vendor)\\/', $relativePath)) {
                        continue;
                    }

                    $content = @file_get_contents($file->getPathname());
                    if ($content === false) {
                        continue;
                    }

                    if (strlen($content) > 20000) {
                        $content = substr($content, 0, 20000) . "\n...[stækket pga. størrelse]";
                    }

                    $contextOutput .= "--- FIL: {$relativePath} ---\n";
                    $contextOutput .= $content . "\n\n";
                }
            }
        }

        return $contextOutput;
    }
}
