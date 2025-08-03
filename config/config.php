<?php

$configFile = __DIR__ . '/config.json';

if (!file_exists($configFile)) {
    throw new RuntimeException("Missing config file: {$configFile}");
}

$config = json_decode(file_get_contents($configFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException("Error parsing config file: " . json_last_error_msg());
}

return $config;