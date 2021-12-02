<?php

declare(strict_types=1);


namespace MikrotikService;

use Psr\Log\LogLevel;
use MikrotikService\Factory\MikrotikDataFactory;
use MikrotikService\Service\OptionsManager;
use MikrotikService\Service\PluginDataValidator;
use MikrotikService\Service\Logger;
use MikrotikService\Service\RouterosAPI;

class Plugin
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var OptionsManager
     */
    private $optionsManager;

    /**
     * @var PluginDataValidator
     */
    private $pluginDataValidator;

    /**
     * @var MikrotikDataFactory
     */
    private $mikrotikDataFactory;

    public function __construct(
        Logger $logger,
        OptionsManager $optionsManager,
        PluginDataValidator $pluginDataValidator,
        MikrotikDataFactory $mikrotikDataFactory
    )
    {
        $this->logger = $logger;
        $this->optionsManager = $optionsManager;
        $this->pluginDataValidator = $pluginDataValidator;
        $this->mikrotikDataFactory = $mikrotikDataFactory;
    }

    public function run(): void
    {
        if (PHP_SAPI === 'fpm-fcgi') {
            $this->logger->info('Mikrotik Service API over HTTP started');
            $this->processHttpRequest();
            $this->logger->info('HTTP request processing ended.');
        } elseif (PHP_SAPI === 'cli') {
            $this->logger->info('TMikrotik Service API over CLI started');
            $this->processCli();
            $this->logger->info('CLI process ended.');
        } else {
            throw new \UnexpectedValueException('Unknown PHP_SAPI type: ' . PHP_SAPI);
        }
    }

    private function processCli(): void
    {
        if ($this->pluginDataValidator->validate()) {
            $this->logger->info('Validating config');
            $this->optionsManager->load();
        }
    }

    private function processHttpRequest(): void
    {
        $pluginData = $this->optionsManager->load();
        if ($pluginData->logging_level) {
            $this->logger->setLogLevelThreshold(LogLevel::DEBUG);
        }

        $userInput = file_get_contents('php://input');
        if (! $userInput) {
            $this->logger->warning('no input');

            return;
        }

        $jsonData = @json_decode($userInput, true, 10);
        if (! isset($jsonData['uuid'])) {
            $this->logger->error('JSON error: ' . json_last_error_msg());

            return;
        }


        $mikrotik = $this->mikrotikDataFactory->getObject($jsonData);
        if ($mikrotik->changeType === 'test') {
            $this->logger->info('Webhook test successful.');

            return;
        }
        if (! $mikrotik->clientId) {
            $this->logger->warning('No client specified, cannot notify them.');

            return;
        }

        try {
            $mktApi = new RouterosAPI;
            $mktApi -> debug = true;

            // IP RANGE 93.119.183.0 - 93.119.183.255

            if(!$mktApi -> connect('mktIP', 'mktUser', 'mktPass')) {
                $this -> logger -> warning('Could not connec tto RouterOS API.');
            } else {
                $mktApi -> write("/ppp/secret/getall", true);
                $mktRead = $mktApi -> read(false);
                $mktArray = $mktApi -> parseResponse($mktRead);

                $mktApi -> write("/ppp/secret/print", true);
                $mktRead = $mktApi -> read(false);
                $mktArray = $mktApi -> parseResponse($mktRead);

                foreach($mktArray as $mktAccounting) {
                    $remoteAddr = $mktAccounting['remote-address'];

                    // Check if IP already is set as remote-address.
                    if($remoteAddr === $this -> mikrotikDataFactory -> getServiceData($mikrotik)['attributes'][0]['value']) {
                        $this -> logger -> warning('An instance with this remote-address already exists.');
                    } else {
                        $mktApi -> comm("/ppp/secret/add", array(
                            "name" => "07NAV" . $this -> mikrotikDataFactory -> getClientData($mikrotik)['id'],
                            "password" => $this -> mikrotikDataFactory -> getClientData($mikrotik)['attributes'][1]['value'],
                            "remote-address" => $this -> mikrotikDataFactory -> getServiceData($mikrotik)['attributes'][0]['value'],
                            "comment" => $this -> mikrotikDataFactory -> getClientData($mikrotik)['fullAddress'],
                            "service" => "pppoe",
                        ));
                    }
                }
            }

            $mktApi -> disconnect();
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $this->logger->warning($ex->getTraceAsString());
        }
    }
}