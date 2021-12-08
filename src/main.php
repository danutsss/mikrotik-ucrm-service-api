<?php

use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;

chdir(__DIR__);

require 'vendor/autoload.php';

(static function () {
    $builder = new \DI\ContainerBuilder();
    $builder->enableCompilation(__DIR__);
    $container = $builder->build();
    $plugin = $container->get(\MikrotikService\Plugin::class);
    try {
        $plugin->run();
    } catch (Exception $e) {
        $logger = new \MikrotikService\Service\Logger();
        $logger->error($e->getMessage());
        $logger->debug($e->getTraceAsString());
    }
})();