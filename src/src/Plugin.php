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
        include 'Functions.php';

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
            /**
             * UNMS API Token: 415711e5-29f7-4a20-9ca2-cf7451ef214f
            */

            $IPs = cidrToRange($pluginData -> ipAddresses);
            $ipAddress = array_rand($IPs, 1);

            $deviceId = randomGUID();
            $clientId = (isset($this -> mikrotikDataFactory -> getClientData($mikrotik)['id']) ? $this -> mikrotikDataFactory -> getClientData($mikrotik)['id'] : 'NOT_SET');
            $clientIdent = (isset($this -> mikrotikDataFactory -> getClientData($mikrotik)['userIdent']) ? $this -> mikrotikDataFactory -> getClientData($mikrotik)['userIdent'] : 'NOT_SET');
            $deviceName = "07NAV" . $clientIdent;
            $devicePass = generateRandPassword();
            $firstName = (isset($this -> mikrotikDataFactory -> getClientData($mikrotik)['firstName']) ? $this -> mikrotikDataFactory -> getClientData($mikrotik)['firstName'] : 'NOT_SET');
            $lastName = (isset($this -> mikrotikDataFactory -> getClientData($mikrotik)['lastName']) ? $this -> mikrotikDataFactory -> getClientData($mikrotik)['lastName'] : 'NOT_SET');
            $fullName = $lastName . ' ' . $firstName;
            $street1 = (isset($this -> mikrotikDataFactory -> getServiceData($mikrotik)['street1']) ? $this -> mikrotikDataFactory -> getServiceData($mikrotik)['street1'] : 'NOT_SET');
            $street2 = (isset($this -> mikrotikDataFactory -> getServiceData($mikrotik)['street2']) ? $this -> mikrotikDataFactory -> getServiceData($mikrotik)['street2'] : 'NOT_SET');
            $fullAddress = $street1 . ' ' . $street2;
            $clientSiteId = (isset($this -> mikrotikDataFactory -> getServiceData($mikrotik)['unmsClientSiteId']) ? $this -> mikrotikDataFactory -> getServiceData($mikrotik)['unmsClientSiteId'] : 'NOT_SET');

            if($mikrotik -> changeType === 'insert' || $mikrotik -> changeType === 'unsuspend') {
                if($this -> mikrotikDataFactory -> getServiceData($mikrotik)['servicePlanType'] === 'Internet') {                    
                    $internetPlan = curl_init();

                    curl_setopt($internetPlan, CURLOPT_URL, "https://uisp.07internet.ro/nms/api/v2.1/devices/blackboxes/config");
                    curl_setopt($internetPlan, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($internetPlan, CURLOPT_HEADER, FALSE);

                    curl_setopt($internetPlan, CURLOPT_POST, TRUE);

                    curl_setopt($internetPlan, CURLOPT_POSTFIELDS, "
                        {
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
                        }
                    ");

                    curl_setopt($internetPlan, CURLOPT_HTTPHEADER, array(
                    "Accept: application/json",
                    "x-auth-token: 415711e5-29f7-4a20-9ca2-cf7451ef214f",
                    "Content-Type: application/json"
                    ));

                    $internetPlanResponse = curl_exec($internetPlan);
                    curl_close($internetPlan);

                    if($internetPlanResponse) {

                        $mktApi = new RouterosAPI;
                        $mktApi -> debug = true;

                        if($mktApi -> connect("93.119.183.66", "admin", "stf@07internet")) {

                            $i = 0;
                            $deviceUser = $deviceUserOriginal = "07NAV" . $clientIdent;
                            do {
                                // First - check if a duplicate exists...
                                $sameNames = $mktApi -> comm("/ppp/secret/getall", array(
                                    ".proplist" => ".id",
                                    "?name" => $deviceUser
                                ));

                                // Second - update and prepare for rechecking...
                                if($sameNames) {
                                    $i ++;
                                    $deviceUser = $deviceUserOriginal . " (". $i .")";
                                }

                                // Finally, below, if check failed, cycle and check again with the new updated name...
                            }
                            while($sameNames);

                            // Finally, tidy up...
                            // If you need the original value of "Device User" you can retain it.
                            unset($i, $deviceUserOriginal);

                            $mktApi -> comm("/ppp/secret/add", array(
                                "name" => $deviceUser,
                                "remote-address" => $IPs[$ipAddress],
                                "password" => $devicePass,
                                "service" => "pppoe",
                                "comment" => $fullName . ' / ' . $fullAddress
                            ));

                            $mktApi -> disconnect();
                        }
                    }

                    // Update Custom Attribute for device password.
                    $customAttr = curl_init();

                    curl_setopt($customAttr, CURLOPT_URL, 'https://uisp.07internet.ro/crm/api/v1.0/clients/' . $clientId);
                    curl_setopt($customAttr, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($customAttr, CURLOPT_HEADER, FALSE);

                    curl_setopt($customAttr, CURLOPT_CUSTOMREQUEST, 'PATCH');

                    curl_setopt($customAttr, CURLOPT_POSTFIELDS, "{
                        \"attributes\": [
                            {
                                \"value\": \"$devicePass\",
                                \"customAttributeId\": 19
                            }
                        ]
                    }");

                    curl_setopt($customAttr, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-Auth-App-Key: HS9hdWcdsV34MXGy/VKKloywDwZeVORNGAfZlHQNQM2sAQM03bSPOodm/9eQ1qpH'
                    ));

                    $dump = curl_exec($customAttr);
                    curl_close($customAttr);

                    return;
                } elseif($this -> mikrotikDataFactory -> getServiceData($mikrotik)['servicePlanType'] === 'General') {
                    $mktApi = new RouterosAPI;
                    $mktApi -> debug = true;

                    if($mktApi -> connect("93.119.183.66", "admin", "stf@07internet")) {

                        $i = 0;
                        $deviceUser = $deviceUserOriginal = "07NAV" . $clientIdent;
                        do {
                            // First - check if a duplicate exists...
                            $sameNames = $mktApi -> comm("/ppp/secret/getall", array(
                                ".proplist" => ".id",
                                "?name" => $deviceUser
                            ));

                            // Second - update and prepare for rechecking...
                            if($sameNames) {
                                $i ++;
                                $deviceUser = $deviceUserOriginal . " (". $i .")";
                            }

                            // Finally, below, if check failed, cycle and check again with the new updated name...
                        }
                        while($sameNames);

                        // Finally, tidy up...
                        // If you need the original value of "Device User" you can retain it.
                        unset($i, $deviceUserOriginal);

                        $mktApi -> comm("/ppp/secret/add", array(
                            "name" => $deviceUser,
                            "remote-address" => "1.1.1.1",
                            "password" => $devicePass,
                            "service" => "pppoe",
                            "comment" => $fullName . ' / ' . $fullAddress
                        ));
                    }

                    $mktApi -> disconnect();
                    return;
                }
            }

            /**
             * SUSPEND - remove de la active si modifica remote-address la 1.1.1.1.
             * END - remove din active si din secret.
             * 
             * 1. Solutie pentru SMS Sending.
             * 2. Retrimitere mail-uri in functie de nume, DOB.
             */

            if($mikrotik -> changeType === 'suspend') {
                $mktApi = new RouterosAPI;
                $mktApi -> debug = true;

                if($mktApi -> connect("93.119.183.66", "admin", "stf@07internet")) {
                    $allUsers = $mktApi -> comm("/ppp/secret/getall", array(
                        ".proplist" => ".id",
                        "?comment" => $fullName . ' / ' . $fullAddress
                    ));

                    $mktApi -> comm("/ppp/secret/set", array(
                        ".id" => $allUsers[0][".id"],
                        "?remote-address" => "1.1.1.1",
                    ));

                    /*$mktApi -> comm("/ppp/active/remove", array(
                        ".id" => $allUsers[0][".id"]
                    ));*/
                }
                $mktApi -> disconnect();   
                return;
            }

            if($mikrotik -> changeType === 'end') {
                $mktApi = new RouterosAPI;
                $mktApi -> debug = true;

                if($mktApi -> connect("93.119.183.66", "admin", "stf@07internet")) {
                    $allUsers = $mktApi -> comm("/ppp/secret/getall", array(
                        ".proplist" => ".id",
                        "?comment" => $fullName . ' / ' . $fullAddress
                    ));

                    $mktApi -> comm("/ppp/active/remove", array(
                        ".id" => $allUsers[0][".id"]
                    ));

                    $mktApi -> comm("/ppp/secret/remove", array(
                        ".id" => $allUsers[0][".id"]
                    ));
                }
                $mktApi -> disconnect();
                return;
            }
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage());
            $this->logger->warning($ex->getTraceAsString());
        }
    }
}