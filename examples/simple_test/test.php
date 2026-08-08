<?php

declare(strict_types=1);

require_once __DIR__ . '/../../inc/b4b31/string_monger.php';

use b4b31\string_monger;

$monger = new string_monger(__DIR__ . '/1032');

var_dump($monger(0));
var_dump($monger(1));
var_dump($monger(0x12));
var_dump($monger(0x9999));
