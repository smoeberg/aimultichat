<?php
declare(strict_types=1);

namespace Services;

use Core\Logger;

final class HttpJsonClient
{
    private const PROVIDER_HOSTS = [
        'openai' => ['api.openai.com'],
        'claude' => ['api.anthropic.com'],
        'anthropic' => ['api.anthropic.com'],
        'mistral' => ['api.mistral.ai'],
        'gemini' => ['generativelanguage.googleapis.com'],
        'deepseek' => ['api.deepseek.com'],
        'rool' => ['api.rool.dev'],
        'gpai' => ['api.gpai.dk'],
        'librechat' => [],
    ];

    public static function validateEndpoint(string $endpoint, string $provider): string
    {
        $endpoint = trim($endpoint);
        if (strlen($endpoint) > 500) {
            throw new \InvalidArgumentException('Provider-endpoint er for lang.');
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('Provider-endpoint skal bruge HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Provider-endpoint må ikke indeholde legitimationsoplysninger.');
        }

        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Provider-endpoint skal bruge et godkendt DNS-værtsnavn.');
        }

        $provider = strtolower(trim($provider));
        $allowedHosts = self::PROVIDER_HOSTS[$provider] ?? [];
        $customHosts = array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', (string)(\configValue('PROVIDER_ALLOWED_HOSTS', '') ?? ''))
        ));
        $allowedHosts = array_values(array_unique(array_merge($allowedHosts, $customHosts)));
        if (!in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException(
                "Provider-værten '{$host}' er ikke godkendt. Tilføj den eksplicit i PROVIDER_ALLOWED_HOSTS."
            );
        }

        $port = (int)($parts['port'] ?? 443);
        $allowedPorts = array_map(
            'intval',
            array_filter(explode(',', (string)(\configValue('PROVIDER_ALLOWED_PORTS', '443') ?? '443')))
        );
        if (!in_array($port, $allowedPorts, true)) {
            throw new \InvalidArgumentException("Provider-porten '{$port}' er ikke godkendt.");
        }

        if (!\configBool('PROVIDER_ALLOW_PRIVATE_NETWORKS', false)) {
            self::publicIpv4Addresses($host);
        }

        return $endpoint;
    }

    /** @return array{status:int,body:string} */
    public function post(string $endpoint, string $provider, array $headers, array $payload): array
    {
        $endpoint = self::validateEndpoint($endpoint, $provider);
        foreach ($headers as $header) {
            if (!is_string($header) || str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new ProviderException('Provider-headeren indeholder ugyldige tegn.', 400);
            }
        }
        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new ProviderException('Provider-requesten kunne ikke serialiseres.', 500, null, $exception);
        }

        $maxBytes = max(4096, min(
            10485760,
            (int)(\configValue('PROVIDER_MAX_RESPONSE_BYTES', '1048576') ?? 1048576)
        ));
        $responseBody = '';
        $tooLarge = false;
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new ProviderException('Provider-forbindelsen kunne ikke initialiseres.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => max(1, min(30, (int)(\configValue('PROVIDER_CONNECT_TIMEOUT', '10') ?? 10))),
            CURLOPT_TIMEOUT => max(1, min(120, (int)(\configValue('PROVIDER_TIMEOUT', '45') ?? 45))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maxBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (!\configBool('PROVIDER_ALLOW_PRIVATE_NETWORKS', false)) {
            $parts = parse_url($endpoint);
            $host = strtolower((string)($parts['host'] ?? ''));
            $port = (int)($parts['port'] ?? 443);
            $addresses = self::publicIpv4Addresses($host);
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$addresses[0]}"];
            if (defined('CURL_IPRESOLVE_V4')) {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
        }
        curl_setopt_array($curl, $options);

        $ok = curl_exec($curl);
        $errorNumber = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($tooLarge) {
            throw new ProviderException('Provider-responsen overskred den tilladte størrelse.', 502, $httpStatus ?: null);
        }
        if ($ok === false || $errorNumber !== 0) {
            Logger::warning('Provider connection failed', [
                'provider' => $provider,
                'curl_error_number' => $errorNumber,
            ]);
            throw new ProviderException('Kunne ikke forbinde sikkert til AI-provider.');
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            Logger::warning('Provider returned HTTP error', [
                'provider' => $provider,
                'provider_status' => $httpStatus,
            ]);
            throw new ProviderException('AI-provideren afviste anmodningen.', 502, $httpStatus);
        }

        return ['status' => $httpStatus, 'body' => $responseBody];
    }

    /** @return string[] */
    private static function publicIpv4Addresses(string $host): array
    {
        $addresses = gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            throw new \InvalidArgumentException('Provider-værten kunne ikke DNS-valideres.');
        }
        foreach ($addresses as $address) {
            if (!filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                throw new \InvalidArgumentException('Provider-værten peger på et privat eller reserveret netværk.');
            }
        }
        return array_values(array_unique($addresses));
    }
}
