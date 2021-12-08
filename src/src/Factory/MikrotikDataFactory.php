<?php
/*
 * @copyright Copyright (c) 2018 Ubiquiti Networks, Inc.
 * @see https://www.ubnt.com/
 */


declare(strict_types=1);


namespace MikrotikService\Factory;


use MikrotikService\Data\MikrotikData;
use MikrotikService\Service\UcrmApi;
use MikrotikService\Service\RouterosAPI;

class MikrotikDataFactory
{
    /**
     * @var UcrmApi
     */
    private $ucrmApi;

    public function __construct(
        UcrmApi $ucrmApi
    ) {
        $this->ucrmApi = $ucrmApi;
    }

    public function getObject($jsonData): MikrotikData {
        $mikrotikData = new MikrotikData();
        $mikrotikData->uuid = $jsonData['uuid'];
        $mikrotikData->changeType = $jsonData['changeType'];
        $mikrotikData->entity = $jsonData['entity'];
        $mikrotikData->entityId = $jsonData['entityId'] ? (int) $jsonData['entityId'] : null;
        $mikrotikData->eventName = $jsonData['eventName'];
        $this->resolveUcrmData($mikrotikData);

        return $mikrotikData;
    }

    private function resolveUcrmData(MikrotikData $mikrotikData): void
    {
        switch($mikrotikData->entity) {
            case 'client':
                $mikrotikData->clientId = $mikrotikData->entityId;
                break;
            case 'service':
                $mikrotikData->clientId = $this->getServiceData($mikrotikData)['clientId'] ?? null;
                break;
        }
        if ($mikrotikData->clientId) {
            $this->getClientData($mikrotikData);
        }
    }

    public function getClientData(MikrotikData $mikrotikData) {
        if (empty($mikrotikData->clientData) && $mikrotikData->clientId) {
            $mikrotikData->clientData = $this->ucrmApi->query('clients/' . $mikrotikData->clientId);
        }
        return $mikrotikData->clientData;
    }

    public function getServiceData(MikrotikData $mikrotikData) {
        if (empty($mikrotikData->serviceData) && $mikrotikData->entityId) {
            $mikrotikData->serviceData = $this->ucrmApi->query('clients/services/' . $mikrotikData->entityId);
        }
        return $mikrotikData->serviceData;
    }
}
