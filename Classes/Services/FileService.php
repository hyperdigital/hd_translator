<?php
namespace Hyperdigital\HdTranslator\Services;

class FileService
{
    public static function rmdir(string $directory): bool
    {
        if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
            array_map(fn(string $file) => is_dir($file) ? self::rrmdir($file) : unlink($file), glob($directory . '/' . '*'));
        }
        return rmdir($directory);
    }
}
