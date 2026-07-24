<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

// HMAI-355: paratest runs each worker process with its own TEST_TOKEN (1..N).
// doctrine.yaml already isolates the database per worker via that token
// (dbname_suffix "_test{TOKEN}"). Mirror the isolation for Redis so parallel
// workers cannot collide on shared keys (e.g. the series:avg:{id} cache written
// by EpisodeRatedHandler) — point each worker at its own Redis logical database.
// A plain sequential `phpunit` run (no TEST_TOKEN) is left untouched.
$paratestToken = getenv('TEST_TOKEN');
if (is_string($paratestToken) && ctype_digit($paratestToken)) {
    $redisUrlRaw = $_SERVER['REDIS_URL'] ?? (getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
    $redisUrl = is_string($redisUrlRaw) ? $redisUrlRaw : 'redis://127.0.0.1:6379';
    // Redis ships with 16 logical databases (0-15); map the worker token onto one.
    $redisDb = (int) $paratestToken % 16;
    $redisBase = preg_replace('#/\d+$#', '', $redisUrl) ?? $redisUrl;
    $redisUrl = $redisBase.'/'.$redisDb;
    $_SERVER['REDIS_URL'] = $_ENV['REDIS_URL'] = $redisUrl;
    putenv('REDIS_URL='.$redisUrl);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}
