<?php

declare(strict_types=1);

use FreedomPlatform\Scripts\Ci\MarzbanReadinessProbe;

require __DIR__.'/MarzbanReadinessProbe.php';

/**
 * @param array<string, string> $headers
 * @param array<string, string>|null $payload
 * @return array{
 *     status: int|null,
 *     json: array<array-key, mixed>|null,
 *     malformed: bool,
 *     transport_failure: bool
 * }
 */
function marzbanReadinessHttpRequest(
    string $method,
    string $url,
    array $headers,
    ?array $payload,
    bool $form,
): array {
    $curl = curl_init($url);
    if ($curl === false) {
        return ['status' => null, 'json' => null, 'malformed' => false, 'transport_failure' => true];
    }

    $httpHeaders = [];
    foreach ($headers as $name => $value) {
        if (! is_string($name) || ! is_string($value)) {
            curl_close($curl);

            return ['status' => null, 'json' => null, 'malformed' => false, 'transport_failure' => true];
        }
        $httpHeaders[] = $name.': '.$value;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $httpHeaders,
    ];
    if ($payload !== null) {
        if ($form) {
            $httpHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $httpHeaders;
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        } else {
            $httpHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $httpHeaders;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
    }
    curl_setopt_array($curl, $options);

    $body = curl_exec($curl);
    if ($body === false) {
        $errorNumber = curl_errno($curl);
        curl_close($curl);
        fwrite(STDERR, 'Marzban readiness transport error: curl_'.$errorNumber.PHP_EOL);

        return ['status' => null, 'json' => null, 'malformed' => false, 'transport_failure' => true];
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $text = trim((string) $body);
    if ($text === '') {
        return ['status' => $status, 'json' => [], 'malformed' => false, 'transport_failure' => false];
    }

    try {
        $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['status' => $status, 'json' => null, 'malformed' => true, 'transport_failure' => false];
    }
    if (! is_array($decoded)) {
        return ['status' => $status, 'json' => null, 'malformed' => true, 'transport_failure' => false];
    }

    return ['status' => $status, 'json' => $decoded, 'malformed' => false, 'transport_failure' => false];
}

try {
    $probe = new MarzbanReadinessProbe('marzbanReadinessHttpRequest');
    $summary = $probe->run([
        'origin' => (string) getenv('MARZBAN_TEST_ORIGIN'),
        'username' => (string) getenv('MARZBAN_TEST_USERNAME'),
        'password' => (string) getenv('MARZBAN_TEST_PASSWORD'),
    ]);

    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Marzban readiness failed: '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
