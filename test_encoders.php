<?php

require __DIR__ . '/vendor/autoload.php';

foreach (glob(__DIR__ . '/vendor/intervention/image/src/Encoders/*Encoder.php') as $f) {
    echo basename($f) . "\n";
}
