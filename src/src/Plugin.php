<?php

declare(strict_types=1);


namespace MikrotikService;

use Psr\Log\LogLevel;
use MikrotikService\Factory\MikrotikDataFactory;
use MikrotikService\Service\OptionsManager;
use MikrotikService\Service\PluginDataValidator;
use MikrotikService\Service\Logger;
use MikrotikService\Service\RouterosAPI;
use Ubnt\UcrmPluginSdk\Service\UnmsApi;

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
            // IP RANGE 93.119.183.0 - 93.119.183.255
            /**
             * 
             * 
             * UNMS API Token: x-auth-token
            */

            include 'Functions.php';

            $rangeStart = '93.119.183.0';
            $rangeEnd = '93.119.183.255';

            $ipAddress = randomIPFromRange($rangeStart, $rangeEnd);
            $fullName = $this -> mikrotikDataFactory -> getClientData($mikrotik)['lastName'] . ' ' . $this -> mikrotikDataFactory -> getClientData($mikrotik)['firstName'];
            $clientId = (isset($this -> mikrotikDataFactory -> getClientData($mikrotik)['userIdent']) ? $this -> mikrotikDataFactory -> getClientData($mikrotik)['userIdent'] : 'NOT_SET');
            $deviceUser = "07NAV" . $clientId;
            $devicePass = generateRandPassword();
            $fullAddress = (isset($this -> mikrotikDataFactory -> getServiceData($mikrotik)['fullAddress']) ? $this -> mikrotikDataFactory -> getServiceData($mikrotik)['fullAddress'] : 'NOT_SET');

            if($mikrotik -> changeType === 'insert') {
                $this -> logger -> info("Webhook 'insert' successfull.");

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, "X");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HEADER, FALSE);

                curl_setopt($ch, CURLOPT_POST, TRUE);

                curl_setopt($ch, CURLOPT_POSTFIELDS, "
                    [
                        {
                            \"ip\": \"$ipAddress\",
                            \"ubntDevice\": true,
                            \"deviceRole\": \"router\",
                            \"username\": \"$deviceUser\",
                            \"password\": \"$devicePass\",
                            \"httpsPort\": 449,
                            \"sshPort\": 22,
                            \"hostname\": \"$deviceUser\",
                            \"model\": \"Unknwon\",
                            \"interfaces\": [
                                {
                                    \"index\": 0,
                                    \"name\": \"$deviceUser\",
                                    \"type\": \"eth\",
                                    \"addresses\": [
                                        \"$ipAddress/32\"
                                    ] 
                                }
                            ],
                            \"note\": \"$fullAddress\"
                        }
                    ]
                ");

                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Accept: application/json",
                "x-auth-token: x-auth-token",
                "Content-Type: application/json"
                ));

                $response = curl_exec($ch);
                curl_close($ch);

                if($response) {
                    $mktApi = new RouterosAPI;
                    $mktApi -> debug = true;

                    if($mktApi -> connect("mktIp", "mktUser", "mktPass")) {
                        $mktApi -> comm("/ppp/secret/add", array(
                            "name" => $deviceUser,
                            "remote-address" => $ipAddress,
                            "password" => $devicePass,
                            "service" => "pppoe",
                            "comment" => $fullAddress
                        ));

                        $this -> logger -> info("Instance has been created into Winbox with user: " . $deviceUser . " and password: " . $devicePass);
                    }

                    $this -> logger -> info("Device was created successfully in NMS for: " . $fullName);
                } else {
                    $this -> logger -> error($response);
                }

                $mktApi -> disconnect();
                return;
            } 

            if($mikrotik->changeType === 'end') {

                $this -> logger -> info("webhook end successfull.");
                
                $mktApi = new RouterosAPI;
                $mktApi -> debug = true;

                if($mktApi -> connect("mktIp", "mktUser", "mktPass")) {
                    $allUsers = $mktApi -> comm("/ppp/secret/getall", array(
                        ".proplist" => ".id",
                        "?name" => $deviceUser,
                    ));

                    $mktApi -> comm("/ppp/secret/remove", array(
                        ".id" => $allUsers[0][".id"]
                    ));

                    $this -> logger -> info("Instance deleted for user: " . $deviceUser);
                }
                
                return;
            }
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $this->logger->warning($ex->getTraceAsString());
        }
    }
}