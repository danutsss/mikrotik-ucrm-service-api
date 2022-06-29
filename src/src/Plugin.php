<?php

declare(strict_types=1);


namespace MikrotikService;

use Psr\Log\LogLevel;
use MikrotikService\Factory\MikrotikDataFactory;
use MikrotikService\Service\OptionsManager;
use MikrotikService\Service\PluginDataValidator;
use MikrotikService\Service\Logger;
use MikrotikService\Service\RouterosAPI;
use MikrotikService\Service\UcrmApi;

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
    ) {
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
        include 'Functions.php';

        $pluginData = $this->optionsManager->load();
        if ($pluginData->logging_level) {
            $this->logger->setLogLevelThreshold(LogLevel::DEBUG);
        }

        $userInput = file_get_contents('php://input');
        if (!$userInput) {
            $this->logger->warning('no input');

            return;
        }

        $jsonData = @json_decode($userInput, true, 10);
        if (!isset($jsonData['uuid'])) {
            $this->logger->error('JSON error: ' . json_last_error_msg());

            return;
        }

        $mikrotik = $this->mikrotikDataFactory->getObject($jsonData);
        if ($mikrotik->changeType === 'test') {
            $this->logger->info('Webhook test successful.');

            return;
        }


        if (!$mikrotik->clientId) {
            $this->logger->warning('No client specified, cannot notify them.');

            return;
        }

        try {
            $IPs = cidrToRange($pluginData->ipAddresses);
            $ipAddress = array_rand($IPs, 1);

            $deviceId = randomGUID();
            $clientId = (isset($this->mikrotikDataFactory->getClientData($mikrotik)['id']) ? $this->mikrotikDataFactory->getClientData($mikrotik)['id'] : 'NOT_SET');
            $clientIdent = (isset($this->mikrotikDataFactory->getClientData($mikrotik)['userIdent']) ? $this->mikrotikDataFactory->getClientData($mikrotik)['userIdent'] : 'NOT_SET');
            $deviceName = "07NAV" . $clientIdent;
            $devicePass = generateRandPassword();

            $fullName = (isset($this->mikrotikDataFactory->getClientData($mikrotik)['lastName']) ?
                $this->mikrotikDataFactory->getClientData($mikrotik)['lastName'] : null) . ' ' .
                (isset($this->mikrotikDataFactory->getClientData($mikrotik)['firstName']) ?
                    $this->mikrotikDataFactory->getClientData($mikrotik)['firstName'] : null);

            $fullAddress = (isset($this->mikrotikDataFactory->getServiceData($mikrotik)['street1']) ?
                $this->mikrotikDataFactory->getServiceData($mikrotik)['street1'] : null) . ' ' .
                (isset($this->mikrotikDataFactory->getServiceData($mikrotik)['street2']) ?
                    $this->mikrotikDataFactory->getServiceData($mikrotik)['street2'] : null);

            $clientSiteId = (isset($this->mikrotikDataFactory->getServiceData($mikrotik)['unmsClientSiteId']) ? $this->mikrotikDataFactory->getServiceData($mikrotik)['unmsClientSiteId'] : 'NOT_SET');
            $serviceId = (isset($this->mikrotikDataFactory->getServiceData($mikrotik)['id']) ? $this->mikrotikDataFactory->getServiceData($mikrotik)['id'] : null);

            switch ($mikrotik->changeType) {
                case "insert":
                    switch ($this->mikrotikDataFactory->getServiceData($mikrotik)['servicePlanType']) {
                        case "Internet":
                            $internetPlan = UcrmApi::doRequest(
                                'devices/blackboxes/config',
                                'POST',
                                "{
                                \"deviceId\": \"$deviceId\",
                                \"hostname\": \"$deviceName\",
                                \"modelName\": \"Router\",
                                \"macAddress\": \"00:00:0a:00:00:aa\",
                                \"deviceRole\": \"router\",
                                \"siteId\": \"$clientSiteId\",
                                \"pingEnabled\": true,
                                \"ipAddress\": \"$IPs[$ipAddress]\",
                                \"ubntDevice\": false,
                                \"note\": \"$fullAddress\",
                                \"interfaces\": [
                                    {
                                        \"id\": \"$deviceName\",
                                        \"position\": 0,
                                        \"name\": \"$deviceName\",
                                        \"mac\": \"00:00:0a:00:00:aa\",
                                        \"type\": \"eth\",
                                        \"addresses\": [
                                            \"$IPs[$ipAddress]/32\"
                                        ]
                                    }
                                ]
                            }"
                            );

                            if ($internetPlan) {
                                $mktApi = new RouterosAPI;
                                $mktApi->debug = true;

                                if ($mktApi->connect("93.119.183.66", "admin", "stf@07internet")) {

                                    $i = 0;
                                    $deviceUser = $deviceUserOriginal = "07NAV" . $clientIdent;
                                    do {
                                        // First - check if a duplicate exists...
                                        $sameNames = $mktApi->comm("/ppp/secret/getall", array(
                                            ".proplist" => ".id",
                                            "?name" => $deviceUser
                                        ));

                                        // Second - update and prepare for rechecking...
                                        if ($sameNames) {
                                            $i++;
                                            $deviceUser = $deviceUserOriginal . "-" . $i . "";
                                        }

                                        // Finally, below, if check failed, cycle and check again with the new updated name...
                                    } while ($sameNames);

                                    // Finally, tidy up...
                                    // If you need the original value of "Device User" you can retain it.
                                    unset($i, $deviceUserOriginal);

                                    $mktApi->comm("/ppp/secret/add", array(
                                        "name" => $deviceUser,
                                        "remote-address" => $IPs[$ipAddress],
                                        "password" => $devicePass,
                                        "service" => "pppoe",
                                        "comment" => $fullName . ' / ' . $fullAddress
                                    ));

                                    $mktApi->disconnect();

                                    /** UPDATE Custom Attribute for: Service IP, Service PPPoE Username, Password and Service Address */
                                    $updateServiceIP = curl_init();

                                    curl_setopt($updateServiceIP, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                                    curl_setopt($updateServiceIP, CURLOPT_RETURNTRANSFER, TRUE);
                                    curl_setopt($updateServiceIP, CURLOPT_HEADER, FALSE);

                                    curl_setopt($updateServiceIP, CURLOPT_CUSTOMREQUEST, "PATCH");

                                    curl_setopt($updateServiceIP, CURLOPT_POSTFIELDS, "{
                                        \"attributes\": [
                                            {
                                                \"value\": \"$IPs[$ipAddress]\",
                                                \"customAttributeId\": 45
                                            }
                                        ]
                                    }");

                                    curl_setopt($updateServiceIP, CURLOPT_HTTPHEADER, array(
                                        "Content-Type: application/json",
                                        "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                                    ));

                                    $responseServiceIP = curl_exec($updateServiceIP);
                                    curl_close($updateServiceIP);

                                    //var_dump($responseServiceIP);
                                    $updateServiceUser = curl_init();

                                    curl_setopt($updateServiceUser, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                                    curl_setopt($updateServiceUser, CURLOPT_RETURNTRANSFER, TRUE);
                                    curl_setopt($updateServiceUser, CURLOPT_HEADER, FALSE);

                                    curl_setopt($updateServiceUser, CURLOPT_CUSTOMREQUEST, "PATCH");

                                    curl_setopt($updateServiceUser, CURLOPT_POSTFIELDS, "{
                                        \"attributes\": [
                                            {
                                                \"value\": \"$deviceUser\",
                                                \"customAttributeId\": 46
                                            }
                                        ]
                                    }");

                                    curl_setopt($updateServiceUser, CURLOPT_HTTPHEADER, array(
                                        "Content-Type: application/json",
                                        "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                                    ));

                                    $responseServiceUser = curl_exec($updateServiceUser);
                                    curl_close($updateServiceUser);

                                    //var_dump($responseServiceUser);

                                    $updateServicePass = curl_init();

                                    curl_setopt($updateServicePass, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                                    curl_setopt($updateServicePass, CURLOPT_RETURNTRANSFER, TRUE);
                                    curl_setopt($updateServicePass, CURLOPT_HEADER, FALSE);

                                    curl_setopt($updateServicePass, CURLOPT_CUSTOMREQUEST, "PATCH");

                                    curl_setopt($updateServicePass, CURLOPT_POSTFIELDS, "{
                                        \"attributes\": [
                                            {
                                                \"value\": \"$devicePass\",
                                                \"customAttributeId\": 47
                                            }
                                        ]
                                    }");

                                    curl_setopt($updateServicePass, CURLOPT_HTTPHEADER, array(
                                        "Content-Type: application/json",
                                        "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                                    ));

                                    $responseServicePass = curl_exec($updateServicePass);
                                    curl_close($updateServicePass);

                                    //var_dump($responseServicePass);
                                    $updateServiceAddr = curl_init();

                                    curl_setopt($updateServiceAddr, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                                    curl_setopt($updateServiceAddr, CURLOPT_RETURNTRANSFER, TRUE);
                                    curl_setopt($updateServiceAddr, CURLOPT_HEADER, FALSE);

                                    curl_setopt($updateServiceAddr, CURLOPT_CUSTOMREQUEST, "PATCH");

                                    curl_setopt($updateServiceAddr, CURLOPT_POSTFIELDS, "{
                                        \"attributes\": [
                                            {
                                                \"value\": \"$fullAddress\",
                                                \"customAttributeId\": 48
                                            }
                                        ]
                                    }");

                                    curl_setopt($updateServiceAddr, CURLOPT_HTTPHEADER, array(
                                        "Content-Type: application/json",
                                        "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                                    ));

                                    $responseServiceAddr = curl_exec($updateServiceAddr);
                                    curl_close($updateServiceAddr);

                                    //var_dump($responseServiceAddr);
                                }
                            }
                            break;

                        case "General":
                            $mktApi = new RouterosAPI;
                            $mktApi->debug = true;

                            if ($mktApi->connect("93.119.183.66", "admin", "stf@07internet")) {

                                $i = 0;
                                $deviceUser = $deviceUserOriginal = "07NAV" . $clientIdent;
                                do {
                                    // First - check if a duplicate exists...
                                    $sameNames = $mktApi->comm("/ppp/secret/getall", array(
                                        ".proplist" => ".id",
                                        "?name" => $deviceUser
                                    ));

                                    // Second - update and prepare for rechecking...
                                    if ($sameNames) {
                                        $i++;
                                        $deviceUser = $deviceUserOriginal . "-" . $i . "";
                                    }

                                    // Finally, below, if check failed, cycle and check again with the new updated name...
                                } while ($sameNames);

                                // Finally, tidy up...
                                // If you need the original value of "Device User" you can retain it.
                                unset($i, $deviceUserOriginal);

                                $mktApi->comm("/ppp/secret/add", array(
                                    "name" => $deviceUser,
                                    "remote-address" => "1.1.1.1",
                                    "password" => $devicePass,
                                    "service" => "pppoe",
                                    "comment" => $fullName . ' / ' . $fullAddress
                                ));
                            }

                            $mktApi->disconnect();

                            /** UPDATE Custom Attribute for: Service IP, Service PPPoE Username, Password and Service Address */
                            $updateServiceIP = curl_init();

                            curl_setopt($updateServiceIP, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                            curl_setopt($updateServiceIP, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($updateServiceIP, CURLOPT_HEADER, FALSE);

                            curl_setopt($updateServiceIP, CURLOPT_CUSTOMREQUEST, "PATCH");

                            curl_setopt($updateServiceIP, CURLOPT_POSTFIELDS, "{
                                \"attributes\": [
                                    {
                                        \"value\": \"1.1.1.1\",
                                        \"customAttributeId\": 45
                                    }
                                ]
                            }");

                            curl_setopt($updateServiceIP, CURLOPT_HTTPHEADER, array(
                                "Content-Type: application/json",
                                "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                            ));

                            $responseServiceIP = curl_exec($updateServiceIP);
                            curl_close($updateServiceIP);

                            //var_dump($responseServiceIP);
                            $updateServiceUser = curl_init();

                            curl_setopt($updateServiceUser, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                            curl_setopt($updateServiceUser, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($updateServiceUser, CURLOPT_HEADER, FALSE);

                            curl_setopt($updateServiceUser, CURLOPT_CUSTOMREQUEST, "PATCH");

                            curl_setopt($updateServiceUser, CURLOPT_POSTFIELDS, "{
                                \"attributes\": [
                                    {
                                        \"value\": \"$deviceUser\",
                                        \"customAttributeId\": 46
                                    }
                                ]
                            }");

                            curl_setopt($updateServiceUser, CURLOPT_HTTPHEADER, array(
                                "Content-Type: application/json",
                                "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                            ));

                            $responseServiceUser = curl_exec($updateServiceUser);
                            curl_close($updateServiceUser);

                            //var_dump($responseServiceUser);

                            $updateServicePass = curl_init();

                            curl_setopt($updateServicePass, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                            curl_setopt($updateServicePass, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($updateServicePass, CURLOPT_HEADER, FALSE);

                            curl_setopt($updateServicePass, CURLOPT_CUSTOMREQUEST, "PATCH");

                            curl_setopt($updateServicePass, CURLOPT_POSTFIELDS, "{
                                \"attributes\": [
                                    {
                                        \"value\": \"$devicePass\",
                                        \"customAttributeId\": 47
                                    }
                                ]
                            }");

                            curl_setopt($updateServicePass, CURLOPT_HTTPHEADER, array(
                                "Content-Type: application/json",
                                "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                            ));

                            $responseServicePass = curl_exec($updateServicePass);
                            curl_close($updateServicePass);

                            //var_dump($responseServicePass);
                            $updateServiceAddr = curl_init();

                            curl_setopt($updateServiceAddr, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/services/' . $serviceId);
                            curl_setopt($updateServiceAddr, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($updateServiceAddr, CURLOPT_HEADER, FALSE);

                            curl_setopt($updateServiceAddr, CURLOPT_CUSTOMREQUEST, "PATCH");

                            curl_setopt($updateServiceAddr, CURLOPT_POSTFIELDS, "{
                                \"attributes\": [
                                    {
                                        \"value\": \"$fullAddress\",
                                        \"customAttributeId\": 48
                                    }
                                ]
                            }");

                            curl_setopt($updateServiceAddr, CURLOPT_HTTPHEADER, array(
                                "Content-Type: application/json",
                                "X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH"
                            ));

                            $responseServiceAddr = curl_exec($updateServiceAddr);
                            curl_close($updateServiceAddr);

                            //var_dump($responseServiceAddr);
                    }
                    break;

                case "suspend":
                    $mktApi = new RouterosAPI;
                    $mktApi->debug = true;

                    if ($mktApi->connect("93.119.183.66", "admin", "stf@07internet")) {
                        $allUsers = $mktApi->comm("/ppp/secret/getall", array(
                            ".proplist" => ".id",
                            "?name" => $this->mikrotikDataFactory->getServiceData($mikrotik)['attributes'][1]['value']
                        ));

                        $mktApi->comm("/ppp/secret/set", array(
                            ".id" => $allUsers[0][".id"],
                            "remote-address" => "1.1.1.1",
                        ));

                        $mktApi->comm("/ppp/active/remove", array(
                            ".id" => $allUsers[0][".id"]
                        ));
                    }
                    break;

                case "unsuspend":
                    $mktApi = new RouterosAPI;
                    $mktApi->debug = true;

                    if ($mktApi->connect("93.119.183.66", "admin", "stf@07internet")) {
                        $allUsers = $mktApi->comm("/ppp/secret/getall", array(
                            ".proplist" => ".id",
                            "?name" => $this->mikrotikDataFactory->getServiceData($mikrotik)['attributes'][1]['value']
                        ));

                        $mktApi->comm("/ppp/secret/set", array(
                            ".id" => $allUsers[0][".id"],
                            "remote-address" => $this->mikrotikDataFactory->getServiceData($mikrotik)['attributes'][0]['value']
                        ));

                        $mktApi->comm("/ppp/active/remove", array(
                            ".id" => $allUsers[0][".id"]
                        ));
                    }
                    break;

                case "edit":
                    break;

                case "end":
                    $mktApi = new RouterosAPI;
                    $mktApi->debug = true;

                    if ($mktApi->connect("93.119.183.66", "admin", "stf@07internet")) {
                        $allUsers = $mktApi->comm("/ppp/secret/getall", array(
                            ".proplist" => ".id",
                            "?name" => $this->mikrotikDataFactory->getServiceData($mikrotik)['attributes'][1]['value']
                        ));

                        $mktApi->comm("/ppp/active/remove", array(
                            ".id" => $allUsers[0][".id"]
                        ));

                        $mktApi->comm("/ppp/secret/remove", array(
                            ".id" => $allUsers[0][".id"]
                        ));
                    }
                    break;

                default:
                    die("Unsupported.");
            }

            /** 
             * 1. Solutie pentru SMS Sending.
             * 2. Retrimitere mail-uri in functie de nume, DOB.
             */
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $this->logger->warning($ex->getTraceAsString());
        }
    }
}
