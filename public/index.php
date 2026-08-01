<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Where the application lives, relative to this file.
 *
 * Normally the parent directory. On cPanel shared hosting there is often no
 * shell to make `public_html` a symlink with, so the contents of this folder
 * get copied into `public_html` instead and the application sits beside it as
 * `~/opes360`. Detecting that here means the copy needs no hand-editing — the
 * step most likely to be got wrong, and the one that fails with a blank page.
 */
$root = null;

foreach ([__DIR__.'/..', __DIR__.'/../opes360'] as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $root = $candidate;
        break;
    }
}

if ($root === null) {
    http_response_code(500);
    exit('OPES360 could not find its application files. They belong either in the parent '
        .'directory of this one, or in a folder named "opes360" beside it.');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $root.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

/*
 * This file always sits in the public folder, whichever layout is in use, so it
 * is the authority on where that folder is. Saying so matters when the folder
 * has been copied to `public_html` beside the application rather than symlinked
 * into it: without this, Laravel looks for the compiled assets under the old
 * path and every page dies on a missing Vite manifest.
 */
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
