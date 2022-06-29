<?php

declare(strict_types=1);

namespace MikrotikService\Service;

class PluginDataValidator
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
     * @var array
     */
    private $errors = [];

    public function __construct(Logger $logger, OptionsManager $optionsManager)
    {
        $this->logger = $logger;
        $this->optionsManager = $optionsManager;
    }

    public function validate(): bool
    {
        $pluginData = $this->optionsManager->load();
        $valid = true;
        if (empty($pluginData->ipAddresses)) {
            $this->errors[] = 'Not valid configuration: IP Addresses must be configured';
            $valid = false;
        }

        $this->logErrors();
        return $valid;
    }

    private function logErrors(): void
    {
        $pluginData = $this->optionsManager->load();
        if ($this->errors) {
            $errorString = implode(PHP_EOL, $this->errors);
            if ($this->errors && $errorString !== $pluginData->displayedErrors) {
                $this->logger->error($errorString);
                $pluginData->displayedErrors = $errorString;
                $this->optionsManager->update();
            }
        } else {
            $pluginData->displayedErrors = null;
            $this->optionsManager->update();
        }
    }
}
