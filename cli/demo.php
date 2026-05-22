<?php

declare(strict_types=1);

// ─── Load .env ──────────────────────────────────────────────────────────────

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        putenv(trim($line));
    }
}

$bridgeUrl = rtrim(getenv('BRIDGE_URL') ?: '', '/');
if (!$bridgeUrl) {
    echo "Error: Set BRIDGE_URL in .env (see .env.example)\n";
    exit(1);
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function bridge_get(string $baseUrl, string $path): array {
    $ch = curl_init("$baseUrl$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("HTTP request failed: $err");

    $data = json_decode($body, true);
    if ($code >= 400) {
        $msg = $data['error'] ?? "HTTP $code";
        throw new RuntimeException($msg);
    }
    if (!is_array($data)) throw new RuntimeException("Invalid JSON response from bridge");
    return $data;
}

// ─── Connect and query ──────────────────────────────────────────────────────

echo "rest-nwc-bridge demo\n";
echo "====================\n\n";
echo "Bridge URL: $bridgeUrl\n\n";

try {
    // 1. Get info
    echo "→ GET /info\n";
    $info = bridge_get($bridgeUrl, '/info');
    if (!empty($info['alias']))   echo "  Alias   : {$info['alias']}\n";
    if (!empty($info['network'])) echo "  Network : {$info['network']}\n";
    if (!empty($info['methods'])) echo "  Methods : " . implode(', ', $info['methods']) . "\n";

    // 2. Get balance
    echo "\n→ GET /balance\n";
    $bal = bridge_get($bridgeUrl, '/balance');
    echo "  Balance : " . number_format($bal['balance_sats'] ?? 0) . " sats\n";
    echo "          : " . number_format($bal['balance_msats'] ?? 0) . " msats\n";

    echo "\nDone.\n";

} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
