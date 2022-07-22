<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace CrowdSecBouncer\RestClient;

use CrowdSecBouncer\BouncerException;

class Curl extends ClientAbstract
{


    /**
     * Send an HTTP request using cURL and parse its JSON result if any.
     *
     * @throws BouncerException
     */
    public function request(
        string $endpoint,
        array $queryParams = null,
        array $bodyParams = null,
        string $method = 'GET',
        array $headers = null,
        int $timeout = null
    ): ?array {
        if (!$this->baseUri) {
            throw new BouncerException('Base URI is required.');
        }

        $handle = curl_init();

        $curlOptions = $this->createOptions($endpoint, $queryParams, $bodyParams, $method, $headers ?: $this->headers);

        curl_setopt_array($handle, $curlOptions);

        $response = $this->exec($handle);

        if (false === $response) {
            throw new BouncerException('Unexpected CURL call failure: ' . curl_error($handle));
        }

        $statusCode = $this->getResponseHttpCode($handle);
        if (empty($statusCode)) {
            throw new BouncerException('Unexpected empty response http code');
        }

        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = "Unexpected response status from $this->baseUri$endpoint: $statusCode\n" . $response;
            throw new BouncerException($message);
        }

        return json_decode($response, true);
    }

    /**
     * Retrieve Curl options.
     *
     * @param $endpoint
     *
     * @throws BouncerException
     */
    private function createOptions(
        $endpoint,
        ?array $queryParams,
        ?array $bodyParams,
        string $method,
        ?array $headers
    ): array {
        $url = $this->baseUri . $endpoint;
        if (!isset($headers['User-Agent'])) {
            throw new BouncerException('User agent is required');
        }
        $options = [
            \CURLOPT_HEADER => false,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_USERAGENT => $headers['User-Agent'],
        ];

        $options[\CURLOPT_HTTPHEADER] = [];
        foreach ($headers as $key => $values) {
            foreach (\is_array($values) ? $values : [$values] as $value) {
                $options[\CURLOPT_HTTPHEADER][] = sprintf('%s:%s', $key, $value);
            }
        }

        if ('POST' === strtoupper($method)) {
            $parameters = $bodyParams;
            $options[\CURLOPT_POST] = true;
            $options[\CURLOPT_CUSTOMREQUEST] = 'POST';
            $options[\CURLOPT_POSTFIELDS] = json_encode($parameters);
        } elseif ('GET' === strtoupper($method)) {
            $parameters = $queryParams;
            $options[\CURLOPT_POST] = false;
            $options[\CURLOPT_CUSTOMREQUEST] = 'GET';
            $options[\CURLOPT_HTTPGET] = true;

            if (!empty($parameters)) {
                $url .= strpos($url, '?') ? '&' : '?';
                $url .= http_build_query($parameters);
            }
        } elseif ('DELETE' === strtoupper($method)) {
            $options[\CURLOPT_POST] = false;
            $options[\CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        $options[\CURLOPT_URL] = $url;
        if ($this->timeout > 0) {
            $options[\CURLOPT_TIMEOUT] = $this->timeout;
        }

        return $options;
    }

    protected function getResponseHttpCode($handle)
    {
        return curl_getinfo($handle, \CURLINFO_HTTP_CODE);
    }

    /**
     * @param $handle
     *
     * @return bool|string
     */
    protected function exec($handle)
    {
        return curl_exec($handle);
    }
}
