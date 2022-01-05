<?php

declare(strict_types=1);


namespace MikrotikService\Service;


use MikrotikService\Data\PluginData;
use MikrotikService\Exception\CurlException;

class UcrmApi
{
    /**
     * @var CurlExecutor
     */
    private $curlExecutor;

    /**
     * @var OptionsManager
     */
    private $optionsManager;

    /**
     * @var bool
     */
    private $verifyUcrmApiConnection;

    const API_URL = 'https://uisp.07internet.ro/nms/api/v2.1';
    const APP_KEY = '415711e5-29f7-4a20-9ca2-cf7451ef214f';

    public function __construct(CurlExecutor $curlExecutor, OptionsManager $optionsManager)
    {
        $this->curlExecutor = $curlExecutor;
        $this->optionsManager = $optionsManager;

        $urlData = parse_url(
            $this->getApiUrl($this->optionsManager->load())
        );
        $this->verifyUcrmApiConnection = ! ($urlData
            && strtolower($urlData['host']) === 'localhost'
            && strtolower($urlData['scheme']) === 'https'
        );
    }

    /**
     * @throws CurlException
     * @throws \ReflectionException
     */
    public function command(string $endpoint, string $method, array $data): void
    {
        $optionsData = $this->optionsManager->load();

        $this->curlExecutor->curlCommand(
            sprintf('%sapi/v1.0/%s', $this->getApiUrl($optionsData), $endpoint),
            $method,
            [
                'Content-Type: application/json',
                'X-Auth-App-Key: ' . $optionsData->pluginAppKey,
            ],
            json_encode((object)$data),
            $this->verifyUcrmApiConnection
        );
    }

    /**
     * @throws CurlException
     * @throws \ReflectionException
     */
    public function query(string $endpoint, array $parameters = []): array
    {
        $optionsData = $this->optionsManager->load();

        return $this->curlExecutor->curlQuery(
            sprintf('%sapi/v1.0/%s', $this->getApiUrl($optionsData), $endpoint),
            [
                'Content-Type: application/json',
                'X-Auth-App-Key: ' . $optionsData->pluginAppKey,
            ],
            $parameters,
            $this->verifyUcrmApiConnection
        );
    }

    public static function doRequest($url, $method = 'GET', $post = [])
    {
        $method = strtoupper($method);

        $ch = curl_init();

        curl_setopt(
            $ch,
            CURLOPT_URL,
            sprintf(
                '%s/%s',
                self::API_URL,
                $url
            )
        );
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                sprintf('x-auth-token: %s', self::APP_KEY),
                'accept: application/json'
            ]
        );

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (! empty($post)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        }

        $response = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            echo sprintf('Curl error: %s', curl_error($ch)) . PHP_EOL;
        }

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
            echo sprintf('API error: %s', $response) . PHP_EOL;
            $response = false;
        }

        curl_close($ch);

        return $response !== false ? json_decode($response, true) : null;
    }

    private function getApiUrl(PluginData $optionsData): string
    {
        return ($optionsData->ucrmLocalUrl ?? false) ?: $optionsData->ucrmPublicUrl;
    }
}