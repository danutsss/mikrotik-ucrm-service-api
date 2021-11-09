<?php

require_once __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;

$API = new RouterosAPI();

$API -> debug = true;

if($API -> connect('93.119.183.66', 'admin', 'stf@07internet')) {
    $API -> comm("/ppp/secret/add", array(
        "name" => "user-test",
        "password" => "password-test",
        "remote-address" => "192.33.54.221",
        "comment" => "str. x, nr. y {test-comment}",
        "service" => "pppoe",
    ));
    $API -> comm("/ppp/active-connections/add", array(
        "name" => "user-test",
        "service" => "pppoe",
        "address" => "192.33.54.221",
        "comment" => "str. x, nr. y {test-comment}",
    ));

    $logger = PluginLogManager::create();
    $logger -> appendLog('PPP Instance created successfully.');

    $API -> disconnect();
}