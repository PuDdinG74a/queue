<?php
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "<pre>";
print_r($keys);
echo "</pre>";