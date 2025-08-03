<?php
/**
 * Entry point for running the web scraper application.
 *
 * Loads dependencies, configuration, and initializes the ScraperApp class.
 * This script should be triggered from the command line or a scheduler.
 */

require_once 'vendor/autoload.php'; // Load Composer dependencies
require_once __DIR__ . '/src/ScraperApp.php'; // Load main application class

$config = require 'config/config.php'; // Load configuration from config.json
$app = new ScraperApp($config);
$app->run();
