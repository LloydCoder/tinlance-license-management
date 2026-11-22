<?php

declare(strict_types=1);

if (! is_string(getenv('APP_KEY')) || getenv('APP_KEY') === '') {
    $analysisKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    putenv('APP_KEY='.$analysisKey);
    $_ENV['APP_KEY'] = $analysisKey;
    $_SERVER['APP_KEY'] = $analysisKey;
}
