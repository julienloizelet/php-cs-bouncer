<?php

declare(strict_types=1);

namespace CrowdSecBouncer\RestClient;

use CrowdSecBouncer\BouncerException;
use Psr\Log\LoggerInterface;

class FileGetContents extends ClientAbstract
{
    /** @var string|null */
    private $headerString;

    public function __construct(array $configs, LoggerInterface $logger)
    {
        parent::__construct($configs, $logger);
        $this->headerString = $this->convertHeadersToString($this->headers);
    }

    /**
     * Convert a key-value array of headers to the official HTTP header string.
     */
    private function convertHeadersToString(array $headers): string
    {
        $builtHeaderString = '';
        foreach ($headers as $key => $value) {
            $builtHeaderString .= "$key: $value\r\n";
        }

        return $builtHeaderString;
    }

    /**
     * Send an HTTP request using the file_get_contents and parse its JSON result if any.
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
        if ($queryParams) {
            $endpoint .= '?' . http_build_query($queryParams);
        }
        $header = $headers ? $this->convertHeadersToString($headers) : $this->headerString;
        $config = [
            'http' => [
                'method' => $method,
                'header' => $header,
                'timeout' => $timeout ?: $this->timeout,
                'ignore_errors' => true,
            ],
        ];
        if ($bodyParams) {
            $config['http']['content'] = json_encode($bodyParams);
        }
        $context = stream_context_create($config);

        $this->logger->debug('', [
            'type' => 'HTTP CALL',
            'method' => $method,
            'uri' => $this->baseUri . $endpoint,
            'content' => 'POST' === $method ? $config['http']['content'] ?? null : null,
            // 'header' => $header, # Do not display header to avoid logging sensible data
        ]);

        $response = file_get_contents($this->baseUri . $endpoint, false, $context);
        if (false === $response) {
            throw new BouncerException('Unexpected HTTP call failure.');
        }
        $parts = explode(' ', $http_response_header[0]);
        $status = 0;
        if (\count($parts) > 1) {
            $status = (int) $parts[1];
        }

        if ($status < 200 || $status >= 300) {
            $message = "Unexpected response status from $this->baseUri$endpoint: $status\n" . $response;
            throw new BouncerException($message);
        }

        return json_decode($response, true);
    }
}
