<?php

declare(strict_types=1);

const EXPECTED_PASARGUARD_VERSION = '5.2.1';
const REQUEST_TIMEOUT_SECONDS = 15;
const CONNECT_TIMEOUT_SECONDS = 5;

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, 'PasarGuard readiness failed: '.$message.PHP_EOL);
    exit(1);
}

/** @return array{status: int, body: string}|array{status: 0, body: ''} */
function requestJson(string $origin, string $basePath, string $path, string $apiKey): array
{
    $url = $origin.$basePath.$path;
    $curl = curl_init($url);
    if ($curl === false) {
        fail('unable to initialize HTTP client');
    }

    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Api-Key: '.$apiKey,
            'User-Agent: FreedomPlatform-PasarGuard-Readiness/1.0',
        ],
    ]);

    $body = curl_exec($curl);
    if ($body === false) {
        $errorNumber = curl_errno($curl);
        curl_close($curl);
        fwrite(STDERR, 'PasarGuard readiness transport error: curl_'.$errorNumber.PHP_EOL);

        return ['status' => 0, 'body' => ''];
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'body' => (string) $body];
}

/** @return array<string, mixed> */
function decodeObject(string $body, string $label): array
{
    try {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail($label.' returned malformed JSON');
    }

    if (! is_array($decoded) || array_is_list($decoded)) {
        fail($label.' returned an unexpected JSON shape');
    }

    return $decoded;
}

/** @return list<mixed> */
function decodeList(string $body, string $label): array
{
    try {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail($label.' returned malformed JSON');
    }

    if (! is_array($decoded) || ! array_is_list($decoded)) {
        fail($label.' returned an unexpected JSON shape');
    }

    return $decoded;
}

$configuredUrl = trim((string) getenv('PASARGUARD_TEST_ORIGIN'));
$apiKey = trim((string) getenv('PASARGUARD_TEST_API_KEY'));

if ($configuredUrl === '') {
    fail('PASARGUARD_TEST_ORIGIN is not configured');
}
if ($apiKey === '') {
    fail('PASARGUARD_TEST_API_KEY is not configured');
}
if (! str_starts_with($apiKey, 'pg_key_')) {
    fail('configured API key has an unexpected format');
}

$parts = parse_url($configuredUrl);
if (! is_array($parts)
    || ($parts['scheme'] ?? null) !== 'https'
    || ! isset($parts['host'])
    || isset($parts['user'])
    || isset($parts['pass'])
    || isset($parts['query'])
    || isset($parts['fragment'])
) {
    fail('configured origin must be a credential-free HTTPS URL');
}

$host = strtolower((string) $parts['host']);
if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
    fail('configured origin must use a DNS hostname');
}

$port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
$origin = 'https://'.$host.$port;
$configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
if ($configuredPath === '/') {
    $configuredPath = '';
}
if ($configuredPath !== '' && preg_match('#\A/[A-Za-z0-9._~/-]+\z#', $configuredPath) !== 1) {
    fail('configured base path is invalid');
}

$baseCandidates = array_values(array_unique([$configuredPath, '', '/hpanel']));
$selectedBasePath = null;
$system = null;
$statusSummary = [];

foreach ($baseCandidates as $basePath) {
    $response = requestJson($origin, $basePath, '/api/system', $apiKey);
    $statusSummary[] = ($basePath === '' ? '/' : $basePath).':'.$response['status'];

    if ($response['status'] !== 200) {
        continue;
    }

    $candidate = decodeObject($response['body'], 'system endpoint');
    if (! isset($candidate['version']) || ! is_string($candidate['version']) || trim($candidate['version']) === '') {
        fail('system endpoint did not return a version');
    }

    $selectedBasePath = $basePath;
    $system = $candidate;
    break;
}

if ($selectedBasePath === null || $system === null) {
    fail('no candidate API base returned HTTP 200 ('.implode(', ', $statusSummary).')');
}

$reportedVersion = ltrim(trim((string) $system['version']), 'vV');
if ($reportedVersion !== EXPECTED_PASARGUARD_VERSION) {
    fail('provider version mismatch; expected '.EXPECTED_PASARGUARD_VERSION.' but received a different version');
}

$inboundsResponse = requestJson($origin, $selectedBasePath, '/api/inbounds/details', $apiKey);
if ($inboundsResponse['status'] !== 200) {
    fail('inbound discovery returned HTTP '.$inboundsResponse['status']);
}
$inbounds = decodeList($inboundsResponse['body'], 'inbound discovery');
foreach ($inbounds as $inbound) {
    if (! is_array($inbound)
        || ! isset($inbound['tag'], $inbound['protocol'])
        || ! is_string($inbound['tag'])
        || ! is_string($inbound['protocol'])
    ) {
        fail('inbound discovery returned an invalid entry');
    }
}

$groupsResponse = requestJson($origin, $selectedBasePath, '/api/groups?limit=1', $apiKey);
if ($groupsResponse['status'] !== 200) {
    fail('group discovery returned HTTP '.$groupsResponse['status']);
}
$groups = decodeObject($groupsResponse['body'], 'group discovery');
if (! isset($groups['groups'], $groups['total']) || ! is_array($groups['groups']) || ! is_int($groups['total'])) {
    fail('group discovery returned an unexpected response');
}

$displayBasePath = $selectedBasePath === '' ? '/' : $selectedBasePath;

fwrite(STDOUT, 'PasarGuard read-only probe passed.'.PHP_EOL);
fwrite(STDOUT, '- contract: v'.EXPECTED_PASARGUARD_VERSION.PHP_EOL);
fwrite(STDOUT, '- detected base path: '.$displayBasePath.PHP_EOL);
fwrite(STDOUT, '- authentication: API key'.PHP_EOL);
fwrite(STDOUT, '- system.read: ok'.PHP_EOL);
fwrite(STDOUT, '- inbound discovery: ok ('.count($inbounds).' entries)'.PHP_EOL);
fwrite(STDOUT, '- group discovery: ok ('.(int) $groups['total'].' total)'.PHP_EOL);
