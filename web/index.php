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

// ─── Helpers ────────────────────────────────────────────────────────────────

function bridge_request(string $method, string $path, ?array $json = null): array {
    global $bridgeUrl;
    if (!$bridgeUrl) throw new RuntimeException('BRIDGE_URL not configured in .env');

    $ch = curl_init("$bridgeUrl$path");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        $opts[CURLOPT_POSTFIELDS] = json_encode($json ?? []);
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("Bridge request failed: $err");

    $data = json_decode($body, true);
    if ($code >= 400) {
        $msg = $data['error'] ?? "HTTP $code";
        throw new RuntimeException($msg);
    }
    return $data;
}

// ─── AJAX: get balance ──────────────────────────────────────────────────────

if (isset($_GET['get_balance'])) {
    header('Content-Type: application/json');
    try {
        $bal = bridge_request('GET', '/balance');
        echo json_encode(['balance' => $bal['balance_sats'] ?? 0]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── AJAX: check invoice status ─────────────────────────────────────────────

if (isset($_GET['check_invoice'])) {
    header('Content-Type: application/json');
    try {
        $paymentHash = $_GET['payment_hash'] ?? '';
        if (!$paymentHash) throw new RuntimeException('payment_hash required');

        $inv = bridge_request('GET', '/invoice/' . urlencode($paymentHash));
        $paid = ($inv['state'] ?? '') === 'settled';
        echo json_encode([
            'paid'     => $paid,
            'amount'   => $inv['amount'] ?? null,
            'preimage' => $inv['preimage'] ?? null,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['paid' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── Handle form submissions ────────────────────────────────────────────────

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'receive') {
            $amountSats  = (int) ($_POST['amount'] ?? 0);
            $description = $_POST['description'] ?? 'Bridge demo';
            if ($amountSats <= 0) throw new RuntimeException('Amount must be positive');

            $inv = bridge_request('POST', '/invoice', [
                'amount_sats' => $amountSats,
                'description' => $description,
            ]);
            $result = [
                'type'         => 'invoice_created',
                'invoice'      => $inv['invoice'],
                'payment_hash' => $inv['payment_hash'],
                'amount'       => $amountSats,
                'description'  => $description,
            ];

        } elseif ($action === 'send') {
            $invoice = trim($_POST['invoice'] ?? '');
            if (!$invoice) throw new RuntimeException('Invoice is required');

            $pay = bridge_request('POST', '/pay', ['invoice' => $invoice]);
            $result = [
                'type'     => 'payment_sent',
                'preimage' => $pay['preimage'] ?? '',
                'fees'     => $pay['fees_paid'] ?? 0,
            ];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ─── Get balance ────────────────────────────────────────────────────────────

$balance = null;
try {
    $bal = bridge_request('GET', '/balance');
    $balance = $bal['balance_sats'] ?? null;
} catch (Throwable $e) {
    // ignore
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REST NWC Bridge Demo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 2rem; }
        h1 { color: #f5a623; margin-bottom: 0.5rem; }
        .subtitle { color: #888; margin-bottom: 2rem; font-size: 0.9rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; max-width: 900px; margin: 0 auto; }
        .card { background: #16213e; border-radius: 12px; padding: 1.5rem; border: 1px solid #0f3460; }
        .card h2 { color: #f5a623; margin-bottom: 1rem; font-size: 1.2rem; }
        .balance-bar { max-width: 900px; margin: 0 auto 2rem; background: #16213e; border-radius: 12px; padding: 1rem 1.5rem; border: 1px solid #0f3460; display: flex; justify-content: space-between; align-items: center; }
        .balance-bar .label { color: #888; font-size: 0.9rem; }
        .balance-bar .value { font-size: 1.5rem; font-weight: bold; color: #f5a623; }
        .balance-bar .na { color: #666; }
        label { display: block; margin-bottom: 0.3rem; font-size: 0.9rem; color: #aaa; }
        input, textarea { width: 100%; padding: 0.6rem; border-radius: 6px; border: 1px solid #0f3460; background: #0a0a1a; color: #e0e0e0; font-size: 0.95rem; margin-bottom: 1rem; }
        textarea { resize: vertical; min-height: 80px; font-family: monospace; font-size: 0.85rem; }
        button { background: #f5a623; color: #1a1a2e; border: none; padding: 0.7rem 1.5rem; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: bold; width: 100%; }
        button:hover { background: #e6951a; }
        .result { margin-top: 2rem; max-width: 900px; margin: 2rem auto 0; }
        .success { background: #0a2e1a; border: 1px solid #1a5a3a; border-radius: 12px; padding: 1.5rem; }
        .error { background: #2e0a0a; border: 1px solid #5a1a1a; border-radius: 12px; padding: 1.5rem; }
        .paid { background: #0a2e1a; border: 2px solid #4caf50; border-radius: 12px; padding: 1.5rem; }
        .success h3 { color: #4caf50; margin-bottom: 0.5rem; }
        .error h3 { color: #f44336; margin-bottom: 0.5rem; }
        .paid h3 { color: #4caf50; margin-bottom: 0.5rem; }
        .mono { font-family: monospace; word-break: break-all; font-size: 0.85rem; background: #0a0a1a; padding: 0.5rem; border-radius: 4px; margin-top: 0.5rem; }
        .header { text-align: center; max-width: 900px; margin: 0 auto; }
        .waiting { color: #f5a623; font-weight: bold; }
        .poll-status { margin-top: 0.8rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>REST NWC Bridge Demo</h1>
        <p class="subtitle">Lightning wallet via rest-nwc-bridge HTTP API</p>
    </div>

    <div class="balance-bar">
        <span class="label">Wallet Balance</span>
        <?php if ($balance !== null): ?>
            <span class="value" id="balance-value"><?= number_format($balance) ?> sats</span>
        <?php else: ?>
            <span class="na" id="balance-value">BRIDGE_URL not configured</span>
        <?php endif; ?>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Receive</h2>
            <form method="POST">
                <input type="hidden" name="action" value="receive">
                <label for="amount">Amount (sats)</label>
                <input type="number" name="amount" id="amount" value="100" min="1" required>
                <label for="description">Description</label>
                <input type="text" name="description" id="description" value="Bridge demo" placeholder="Payment description">
                <button type="submit">Create Invoice</button>
            </form>
        </div>

        <div class="card">
            <h2>Send</h2>
            <form method="POST">
                <input type="hidden" name="action" value="send">
                <label for="invoice">Bolt11 Invoice</label>
                <textarea name="invoice" id="invoice" placeholder="lntbs1..." required></textarea>
                <button type="submit">Pay Invoice</button>
            </form>
        </div>
    </div>

    <?php if ($result): ?>
    <div class="result">
        <?php if ($result['type'] === 'invoice_created'): ?>
            <div class="success" id="invoice-result">
                <h3>Invoice Created (<?= $result['amount'] ?> sats)</h3>
                <p><?= htmlspecialchars($result['description']) ?></p>
                <div class="mono"><?= htmlspecialchars($result['invoice']) ?></div>
                <div class="poll-status">
                    <span class="waiting" id="poll-status">Waiting for payment...</span>
                </div>
            </div>
            <script>
                const paymentHash = <?= json_encode($result['payment_hash']) ?>;
                let pollCount = 0;
                const maxPolls = 120;

                function refreshBalance() {
                    fetch('?get_balance=1')
                        .then(r => r.json())
                        .then(data => {
                            if (data.balance !== undefined) {
                                const el = document.getElementById('balance-value');
                                el.className = 'value';
                                el.textContent = Number(data.balance).toLocaleString() + ' sats';
                            }
                        })
                        .catch(() => {});
                }

                function checkPayment() {
                    pollCount++;
                    if (pollCount > maxPolls) {
                        document.getElementById('poll-status').textContent = 'Invoice expired or timed out.';
                        return;
                    }

                    fetch('?check_invoice=1&payment_hash=' + encodeURIComponent(paymentHash))
                        .then(r => r.json())
                        .then(data => {
                            if (data.paid) {
                                const el = document.getElementById('invoice-result');
                                el.className = 'paid';
                                document.getElementById('poll-status').innerHTML =
                                    '<h3>Paid!</h3>' +
                                    (data.preimage ? '<p>Preimage:</p><div class="mono">' + data.preimage + '</div>' : '');
                                refreshBalance();
                            } else {
                                setTimeout(checkPayment, 1000);
                            }
                        })
                        .catch(() => setTimeout(checkPayment, 2000));
                }

                checkPayment();
            </script>
        <?php elseif ($result['type'] === 'payment_sent'): ?>
            <div class="success">
                <h3>Payment Sent</h3>
                <p>Fees: <?= $result['fees'] ?> msats</p>
                <p>Preimage:</p>
                <div class="mono"><?= htmlspecialchars($result['preimage']) ?></div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="result">
        <div class="error">
            <h3>Error</h3>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
