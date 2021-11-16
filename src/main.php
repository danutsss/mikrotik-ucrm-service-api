<?php

chdir(__DIR__);

require 'vendor/autoload.php';

(static function() {
    $pluginBuilder = new \DI\ContainerBuilder();
    $pluginBuilder -> setDefinitionCache(new \Doctrine\Common\Cache\ApcuCache());
    $pluginContainer = $pluginBuilder -> build();
    $plugin = $pluginContainer -> get(\MikrotikService\Plugin::class);

    try {
        $plugin -> run();
    } catch(Exception $e) {
        $pluginLogger = new \MikrotikService\Service\Logger();
        $pluginLogger -> error($e -> getMessage());
        $pluginLogger -> debug($e -> getTraceAsString());
    }
})();