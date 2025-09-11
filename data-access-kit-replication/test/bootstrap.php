<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Check if extension is loaded
if (!extension_loaded('data_access_kit_replication')) {
    throw new Exception(
        'DataAccessKit Replication extension is not loaded. ' .
        'Please install and enable the extension before running tests.'
    );
}