<?php

declare(strict_types=1);

use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use FreedomPlatform\Scripts\Ci\PasarGuardLiveAcceptance;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/PasarGuardLiveAcceptance.php';

function pasarguardLiveHttpRequest(string $method, string $url, array $headers, ?array $payload): PanelHttpExchange
{
    $curl = curl_init($url);
    if ($curl === false) {
        return new PanelHttpExchange(null, null, false, true);
    }

    $httpHeaders = [];
    foreach ($headers as $name => $value) {
        if (! is_string($name) || ! is_string($value)) {
            curl_close($curl);

            return new PanelHttpExchange(null, null, false, true);
        }
        $httpHeaders[] = $name.': '.$value;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $httpHeaders,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);

    $body = curl_exec($curl);
    if ($body === false) {
        $code = curl_errno($curl);
        curl_close($curl);
        fwrite(STDERR, 'PasarGuard live transport failure: curl_'.$code.PHP_EOL);

        return new PanelHttpExchange(null, null, false, true);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $text = (string) $body;
    if ($text === '') {
        return new PanelHttpExchange($status, [], false, false);
    }

    try {
        $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return new PanelHttpExchange($status, null, true, false);
    }
    if (! is_array($decoded)) {
        return new PanelHttpExchange($status, null, true, false);
    }

    return new PanelHttpExchange($status, $decoded, false, false);
}

$groupValue = trim((string) getenv('PASARGUARD_LIVE_GROUP_ID'));
$groupId = null;
if ($groupValue !== '') {
    $parsed = filter_var($groupValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (! is_int($parsed)) {
        fwrite(STDERR, 'PasarGuard live acceptance failed: pasarguard_live_group_id_invalid'.PHP_EOL);
        exit(1);
    }
    $groupId = $parsed;
}

try {
    $runner = new PasarGuardLiveAcceptance('pasarguardLiveHttpRequest');
    $summary = $runner->run([
        'origin' => (string) getenv('PASARGUARD_TEST_ORIGIN'),
        'api_key' => (string) getenv('PASARGUARD_TEST_API_KEY'),
        'run_id' => (string) getenv('PASARGUARD_LIVE_RUN_ID'),
        'confirm' => (string) getenv('PASARGUARD_LIVE_CONFIRM'),
        'group_id' => $groupId,
    ]);

    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'PasarGuard live acceptance failed: '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
