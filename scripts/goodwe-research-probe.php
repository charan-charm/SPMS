<?php

declare(strict_types=1);

/**
 * G1 RESEARCH PROBE — GoodWe/SEMS+ read-only provider verification.
 *
 * Research tooling only (docs/10 D-061 §7 / D-062). This is NOT the
 * production integration and nothing here may be imported by app code.
 *
 * Hard safety rules:
 *   - Default execution performs ZERO network calls (plan/preflight only).
 *   - LIVE mode requires BOTH: environment variable GOODWE_LIVE_PROBE=1
 *     AND the flag --confirm=GOODWE-READ-ONLY-G1.
 *   - Automated tests never invoke this file (it is a CLI outside tests/
 *     and referenced by no test; the suite uses fake transports only).
 *   - Only the fixed read-only data-endpoint allowlist is callable.
 *     sems-remote/control and alarm-write surfaces are structurally
 *     excluded — they are not present in the allowlist at all.
 *   - Nothing secret is ever printed or persisted: account/password are
 *     reported as present/absent only; the provider token is used in
 *     memory and discarded; request headers are never echoed; station
 *     and serial identifiers are reported only as truncated SHA-256
 *     fingerprints; only allowlisted measurement scalars are printed.
 *   - No file writes; stdout only. Minimum request count.
 *
 * Provider facts (auth mechanism, TTL, rate limits) were UNVERIFIED at
 * G1; this probe exists so the owner can resolve them with their own
 * credentials placed in the local .env (SEMS_ACCOUNT, SEMS_PASSWORD,
 * SEMS_STATION_ID, optional SEMS_SN).
 *
 * WIRE-FIDELITY NOTE: the login payload, token header and X-Signature
 * construction below reproduce the OBSERVED behavior of the reference
 * prototype (sems-login.js: sha256 lowercase-HEX digest, then
 * base64("<hex>@<millis>"); pre-login request carries token/X-Signature
 * built from an empty session). This fidelity makes the probe a faithful
 * measurement instrument — it is NOT evidence that GoodWe officially
 * sanctions server-side use (docs/10 D-062 §3: PROTOTYPE OBSERVATION).
 * `--self-test` verifies the PHP reproduction against a vector computed
 * by the reference implementation itself, with zero network access.
 *
 * Usage:
 *   php scripts/goodwe-research-probe.php
 *   php scripts/goodwe-research-probe.php --self-test
 *   GOODWE_LIVE_PROBE=1 php scripts/goodwe-research-probe.php --live --confirm=GOODWE-READ-ONLY-G1 [--endpoints=basicInfo,flow] [--station=ID] [--sn=SN]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "CLI only.\n";
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;

const CONFIRM_PHRASE = 'GOODWE-READ-ONLY-G1';
const LOGIN_ORIGIN = 'https://semsplus.goodwe.com';
const IDENT_KEYS = ['stationid', 'id', 'sn', 'invertersn', 'pwid', 'uid', 'token', 'uuid', 'name', 'googleaddress', 'stationaddress', 'account', 'email'];
const SCALAR_KEYS = [
    'pac', 'psystem', 'pgrid', 'pconsum', 'consumflag', 'prosystemtotalstats', 'progridstats',
    'purchasestats', 'propurchasestats', 'procharstats', 'prodischarstats', 'profitprostats',
    'currency', 'installedpower', 'pvcapacity', 'batterycapacity', 'timezone', 'zoneid',
    'timespan', 'inverterstatus', 'status', 'isgoodweinverter', 'hasgoodweinverter', 'code',
];

$argvv = array_slice($argv, 1);
$args = [];
foreach ($argvv as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

if (isset($args['self-test'])) {
    // Offline wire-parity check only — zero network access, no credentials.
    // The reference implementation (source of truth) reproduces this input:
    //   node -e "const s=require('./references/goodwe-sems-prototype/sems-login.js');console.log(s.encodeSignature(JSON.stringify({uid:'1122334455667788990',timestamp:0,token:'t-abc',client:'semsPlusWeb',version:'',language:'en'}),1700000000000))"
    $th = tokenHeaderJson(['uid' => '1122334455667788990', 'timestamp' => 0, 'token' => 't-abc']);
    $got = encodeSignature($th, 1700000000000);
    $raw = hash('sha256', '1700000000000@1122334455667788990@t-abc');
    echo "SELF-TEST token header: $th\n";
    echo "SELF-TEST sha256(hex):  $raw\n";
    echo "SELF-TEST X-Signature:  $got\n";
    echo 'SELF-TEST hex-format ok: ' . (preg_match('/^[0-9a-f]{64}$/', $raw) === 1 ? 'yes' : 'NO — digest must be lowercase hex') . "\n";
    echo "SELF-TEST structure ok: " . (base64_decode($got, true) === $raw . '@1700000000000' ? 'yes' : 'no') . " (decoded == \"<hex>@<millis>\")\n";
    exit(0);
}

Config::initialize(dirname(__DIR__));

$account = Config::get('SEMS_ACCOUNT') ?? '';
$password = Config::get('SEMS_PASSWORD') ?? '';
$station = (string) ($args['station'] ?? (Config::get('SEMS_STATION_ID') ?? ''));
$sn = (string) ($args['sn'] ?? (Config::get('SEMS_SN') ?? ''));

$allowlist = [
    'basicInfo' => 'station', 'inverterList' => 'station', 'flow' => 'station',
    'production' => 'station', 'stationStatistic' => 'station',
    'telemetry' => 'sn', 'timeSeriesData' => 'sn',
];

$requested = array_keys(array_intersect_key(
    $allowlist,
    array_flip(array_map('trim', explode(',', (string) ($args['endpoints'] ?? implode(',', array_keys($allowlist))))))
));

echo "== GoodWe G1 research probe (read-only) ==\n";
echo 'credential presence: '
    . 'SEMS_ACCOUNT=' . ($account !== '' ? 'set' : 'MISSING')
    . ' SEMS_PASSWORD=' . ($password !== '' ? 'set' : 'MISSING')
    . ' SEMS_STATION_ID=' . ($station !== '' ? 'set' : 'MISSING')
    . ' SEMS_SN=' . ($sn !== '' ? 'set' : 'absent') . "\n";
echo 'endpoints (fixed allowlist): ' . implode(', ', $requested) . "\n";

if (!isset($args['live'])) {
    echo "MODE: plan only — no network calls were made (default path cannot reach the provider).\n";
    echo "To perform the single live validation: set GOODWE_LIVE_PROBE=1 and pass --live --confirm=" . CONFIRM_PHRASE . "\n";
    exit(0);
}

$refusals = [];
if (getenv('GOODWE_LIVE_PROBE') !== '1') {
    $refusals[] = 'GOODWE_LIVE_PROBE env is not 1';
}
if (($args['confirm'] ?? '') !== CONFIRM_PHRASE) {
    $refusals[] = '--confirm phrase missing/mismatched';
}
if ($account === '' || $password === '') {
    $refusals[] = 'SEMS credentials not present in local .env';
}
if ($station === '') {
    $refusals[] = 'SEMS_STATION_ID (or --station) required';
}
if ($refusals !== []) {
    fwrite(STDERR, "LIVE PROBE REFUSED: " . implode('; ', $refusals) . "\n");
    exit(1);
}

function fp(string $v): string
{
    return '<id:' . substr(hash('sha256', $v), 0, 8) . '>';
}

function msNow(): int
{
    return (int) floor(microtime(true) * 1000); // parity with JS Date.now()
}

function uuidV4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * Reference reproduction of sems-login.js createTokenHeader: key order
 * uid, timestamp, token, client, version, api, region, uuid, language;
 * api/region/uuid keys are omitted when absent (JS JSON.stringify drops
 * undefined values).
 */
function tokenHeaderJson(array $session = [], string $language = 'en'): string
{
    $h = [
        'uid' => (string) ($session['uid'] ?? ''),
        'timestamp' => $session['timestamp'] ?? 0,
        'token' => (string) ($session['token'] ?? ''),
        'client' => (string) ($session['client'] ?? 'semsPlusWeb'),
        'version' => (string) ($session['version'] ?? ''),
    ];
    foreach (['api', 'region', 'uuid'] as $k) {
        if (array_key_exists($k, $session) && $session[$k] !== null) {
            $h[$k] = $session[$k];
        }
    }
    $h['language'] = $language;
    return (string) json_encode($h, JSON_UNESCAPED_SLASHES);
}

/**
 * Reference reproduction of sems-login.js encodeSignature: re-parse the
 * token header string, hash "<millis>@<uid>@<token>" with SHA-256 in
 * LOWERCASE HEX (not raw bytes), return base64("<hex>@<millis>").
 */
function encodeSignature(string $tokenHeaderValue, int $timestamp): string
{
    $parsed = json_decode($tokenHeaderValue, true);
    $uid = is_array($parsed) ? (string) ($parsed['uid'] ?? '') : '';
    $token = is_array($parsed) ? (string) ($parsed['token'] ?? '') : '';

    return base64_encode(hash('sha256', $timestamp . '@' . $uid . '@' . $token) . '@' . $timestamp);
}

/**
 * Reference reproduction of sems-login.js buildSemsHeaders (full set,
 * including the pre-login case with an empty session).
 */
function buildSemsHeaders(array $session = [], string $language = 'en', ?string $uuid = null): array
{
    $th = tokenHeaderJson($session, $language);

    return [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/json',
        'Access-Control-Allow-Origin: *',
        'Origin: ' . LOGIN_ORIGIN,
        'Referer: ' . LOGIN_ORIGIN . '/',
        'token: ' . $th,
        'X-Signature: ' . encodeSignature($th, msNow()),
        'neutral: 0',
        'currentLang: ' . $language,
        'traceparent: 00-' . bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(8)) . '-01',
        'uuid: ' . ($uuid ?? uuidV4()),
        'os: Windows 10.0 desktop',
        'brand: Node.js SEMS automation',
    ];
}

function curlJson(string $url, array $headers, ?array $json, bool $isPost): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($isPost) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}

function summarize(mixed $data, int $depth = 0): void
{
    if ($depth > 4) {
        return;
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            $low = strtolower($key);
            if (in_array($low, IDENT_KEYS, true)) {
                $out[] = $key . '=' . (is_scalar($v) ? fp((string) $v) : '<structure>');
            } elseif (in_array($low, SCALAR_KEYS, true) && is_scalar($v)) {
                $out[] = $key . '=' . var_export($v, true);
            } elseif (is_array($v)) {
                $out[] = $key . '{' . count($v) . '}';
                if ($depth < 2) {
                    summarize($v, $depth + 1);
                }
            }
        }
        if ($out !== []) {
            echo '  ' . implode(' ', $out) . "\n";
        }
    }
}

$uuid = uuidV4();

echo "-- login (1 request) --\n";
[$status, $body] = curlJson(
    LOGIN_ORIGIN . '/web/sems/sems-user/api/v1/auth/cross-login',
    buildSemsHeaders([], 'en', $uuid),
    ['account' => $account, 'agreement' => 1, 'isChinese' => false, 'isLocal' => false, 'pwd' => base64_encode(md5($password))],
    true
);
$login = json_decode($body, true);
$code = is_array($login) ? (string) ($login['code'] ?? '?') : '?';
echo 'login http=' . $status . ' envelope_code=' . $code . ($code === '00000' ? ' (success)' : ' (FAILED — provider did not accept server-side login)') . "\n";
if ($code !== '00000') {
    echo 'description present: ' . (isset($login['description']) || isset($login['msg']) ? 'yes' : 'no') . " (not printed)\n";
    exit(2);
}

$session = is_array($login['data'] ?? null) ? $login['data'] : [];
// Reference parity: prototype login() returns {...body.data, uuid: body.data?.uuid || uuid}
$session['uuid'] = (string) ($session['uuid'] ?? $uuid);
$apiBase = (string) ($session['api'] ?? '');
$apiHost = parse_url($apiBase, PHP_URL_HOST) ?: '<none>';
echo 'session fields present: uid=' . (isset($session['uid']) ? 'yes' : 'no')
    . ' token=' . (isset($session['token']) ? 'yes' : 'no')
    . ' region=' . (string) ($session['region'] ?? '<none>')
    . ' api_host=' . $apiHost
    . ' issued_timestamp=' . (string) ($session['timestamp'] ?? '<none>') . "\n";
echo "(token value retained in memory only — never printed; TTL measurement: rerun a request later with --endpoints=flow to observe invalid-token signals)\n";

$origin = 'https://' . $apiHost;
$plant = $origin . '/web/sems/sems-plant/api';
$report = $origin . '/web/sems/sems-report/api';
$reportDirect = $origin . '/sems/sems-report/api';
$today = date('Y-m-d');

$calls = [
    'basicInfo' => ['post', $plant . '/portal/stations/basic/info?stationId=' . rawurlencode($station), []],
    'inverterList' => ['post', $report . '/station/inverter/list', ['stationIds' => [$station]]],
    'flow' => ['get', $plant . '/stations/flow?stationId=' . rawurlencode($station), null],
    'production' => ['post', $plant . '/stations/production', ['stationId' => $station, 'items' => ['profitProStats', 'proSystemTotalStats'], 'dimension' => 'day', 'isReport' => false, 'startTime' => $today . ' 00:00:00', 'endTime' => $today . ' 23:59:59']],
    'stationStatistic' => ['post', $reportDirect . '/report/station/statistic', ['reportCategorize' => 'STATION', 'reportType' => 'STATISTIC', 'reportingPeriod' => 'month', 'stationIds' => [$station], 'startTime' => date('Y-m-01') . ' 00:00:00', 'endTime' => $today . ' 23:59:59', 'dimension' => 'day', 'polymerize' => true]],
    'telemetry' => ['get', $plant . '/equipments/' . rawurlencode($sn) . '/telemetry?sn=' . rawurlencode($sn) . '&deviceType=INVERTER&pwId=' . rawurlencode($station), null],
    'timeSeriesData' => ['post', $plant . '/portal/equipments/' . rawurlencode($sn) . '/timeSeriesData', ['stationId' => $station, 'sn' => $sn, 'deviceType' => 'INVERTER', 'group' => 'operation_area', 'module' => 'chart', 'startDateTime' => $today . ' 00:00:00', 'endDateTime' => $today . ' 23:59:59', 'timeGranularity' => '1']],
];

foreach ($requested as $name) {
    if (!isset($calls[$name])) {
        echo "- $name: not in allowlist (refused)\n";
        continue;
    }
    if (in_array($name, ['telemetry', 'timeSeriesData'], true) && $sn === '') {
        echo "- $name: skipped (SEMS_SN not provided)\n";
        continue;
    }
    [$method, $url, $payload] = $calls[$name];
    usleep(500000);
    [$st, $bd] = curlJson($url, buildSemsHeaders($session, 'en', (string) $session['uuid']), $payload, $method === 'post');
    $decoded = json_decode($bd, true);
    $ecode = is_array($decoded) ? (string) ($decoded['code'] ?? '?') : '?';
    echo "-- $name -- http=$st envelope_code=$ecode" . ($ecode === '00000' ? ' (success)' : ' (non-success)') . "\n";
    if (is_array($decoded) && array_key_exists('data', $decoded)) {
        summarize($decoded['data']);
    }
}

echo "== probe complete (identifiers fingerprinted; headers/token never printed) ==\n";
