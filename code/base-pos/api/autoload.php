<?php
// Simple autoloader
spl_autoload_register(function ($className) {
    // Convert namespace separators to directory separators
    $className = str_replace('\\', DIRECTORY_SEPARATOR, $className);

    // Define base directories to search for classes
    $directories = [
        __DIR__.'/Core/',
        __DIR__.'/Controllers/',
        __DIR__.'/Models/',
        __DIR__.'/Services/',
        __DIR__.'/'
    ];

    // Check each directory
    foreach ($directories as $directory) {
        $file = $directory.$className.'.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
