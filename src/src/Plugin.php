<?php

declare(strict_types=1);


namespace MikrotikService;

use Psr\Log\LogLevel;
use MikrotikService\Service\UcrmApi;
use MikrotikService\Factory\MikrotikDataFactory;
use MikrotikService\Service\OptionsManager;
use MikrotikService\Service\PluginDataValidator;
use MikrotikService\Service\Logger;
use MikrotikService\Service\RouterosAPI;
use MikrotikService\Service\UCRMAPIAccess;

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

    /**
     * @var UCRMAPIAccess
     */
    private $ucrmApi;

    public function __construct(
        Logger $logger,
        OptionsManager $optionsManager,
        PluginDataValidator $pluginDataValidator,
        MikrotikDataFactory $mikrotikDataFactory,
        UCRMAPIAccess $ucrmApi
    )
    {
        $this->logger = $logger;
        $this->optionsManager = $optionsManager;
        $this->pluginDataValidator = $pluginDataValidator;
        $this->mikrotikDataFactory = $mikrotikDataFactory;
        $this -> ucrmApi = $ucrmApi;
    }

    public function run(): void
    {
        if (PHP_SAPI === 'fpm-fcgi') {
            $this->logger->info('Mikrotik Service API over HTTP started');
            $this->processHttpRequest();
            $this->logger->info('HTTP request processing ended.');
        } elseif (PHP_SAPI === 'cli') {
            $this->logger->info('Mikrotik Service API over CLI started');
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
            /**
             * Daca un serviciu este creat si este setat un Remote Address deja existent pentru alt serviciu deja creat,
             * serviciul sa nu poata fi creat sau sa se seteze automat un remote addres + 1.
             * 
             * Pentru moment, in cazul service.end / service.suspend, in Mikrotik sa se schimbe remote-address cu 1.1.1.1,
             * eliberandu-se IP-ul setat la crearea serviciului.
            */           

            $mktApi -> disconnect();
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $this->logger->warning($ex->getTraceAsString());
        }
    }
}