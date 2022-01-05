<?php

declare(strict_types = 1);

use MikrotikService\Service\TemplateRenderer;
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

// Render  form
$optionsManager = UcrmOptionsManager::create();
$renderer = new TemplateRenderer();

// Set the default template and data.
$template = "seeips.php";
$data = [   'ucrmPublicUrl' => $optionsManager -> loadOptions() -> ucrmPublicUrl    ];

if(isset($_GET["hook"])) {
    // Handle the possible "hooK" parameters...
    switch($_GET["hook"]) {
        case "seeips":
            $template = "seeips.php";
            break;
            
        default:
            // Maybe die here or use above default?!
            die("Unsupported 'hook' parameter.");
    }
}

$renderer -> render(__DIR__ . "/views/$template", $data);