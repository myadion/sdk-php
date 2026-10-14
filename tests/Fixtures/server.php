<?php

/*
 * A fake Adion Cloud API for the test suite, served by `php -S` on
 * 127.0.0.1. It mimics the contract of the real API (paths, status codes,
 * error bodies, idempotency, pagination) with in-file state, and never
 * talks to the network.
 *
 * Test hooks: POST /__reset, POST /__scenario (failures to inject),
 * GET /__log (the requests received).
 */

declare(strict_types=1);

const CLIENT_ID = 'test_client';
const CLIENT_SECRET = 'test_secret';

$stateFile = getenv('MYADION_FAKE_STATE') ?: sys_get_temp_dir().'/myadion-fake-state.json';

function load_state(string $file): array
{
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    $state = $raw === '' ? null : json_decode($raw, true);

    return is_array($state) ? $state : fresh_state();
}

function fresh_state(): array
{
    $payments = [];
    for ($i = 1; $i <= 5; $i++) {
        $payments[] = payment('pay_pmt_Test0000000'.$i, 1000 * $i);
    }

    return [
        'tokens' => [],
        'token_count' => 0,
        'token_expires_in' => 3600,
        'sessions' => [],
        'idempotency' => [],
        'payments' => $payments,
        'webhooks' => [],
        'fail' => [],
        'log' => [],
    ];
}

function payment(string $id, int $amount): array
{
    return [
        'id' => $id, 'object' => 'pay.payment', 'status' => 'succeeded', 'amount_cents' => $amount,
        'fee_cents' => 50, 'refunded_cents' => 0, 'net_cents' => $amount - 50, 'currency' => 'eur',
        'reference' => 'ORDER-'.$amount, 'metadata' => ['order_id' => (string) $amount], 'session_id' => null,
        'link_id' => null, 'pay_account_id' => 'pay_acct_TestAccount01', 'customer_email' => 'buyer@example.com',
        'customer_name' => 'Test Buyer', 'payment_method' => 'Visa •••• 4242', 'disputed' => false,
        'failure_message' => null, 'livemode' => false, 'created_at' => '2026-10-14T10:00:00+00:00',
        'paid_at' => '2026-10-14T10:00:05+00:00', 'refunds' => [],
    ];
}

function respond(int $status, mixed $body = null, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }
    if ($body !== null) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

function error_body(int $status, string $message, array $details = []): array
{
    $labels = [400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'validation_error', 429 => 'too_many_requests'];
    $body = ['message' => $message, 'status' => $status, 'error' => $labels[$status] ?? ($status >= 500 ? 'server_error' : 'request_error')];
    if ($details !== []) {
        $body['details'] = $details;
    }

    return $body;
}

function random_id(string $prefix): string
{
    return $prefix.substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
}

$fp = fopen($stateFile.'.lock', 'c');
flock($fp, LOCK_EX);
$state = load_state($stateFile);

$method = $_SERVER['REQUEST_METHOD'];
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$json = json_decode($raw, true);
$body = is_array($json) ? $json : [];

if ($uri === '/__reset') {
    $state = fresh_state();
    respond(200, ['ok' => true]);
} elseif ($uri === '/__scenario') {
    foreach ($body as $key => $value) {
        $state[$key] = $value;
    }
    respond(200, ['ok' => true]);
} elseif ($uri === '/__log') {
    respond(200, $state['log']);
} else {
    $state['log'][] = ['method' => $method, 'path' => $uri, 'query' => $_GET, 'headers' => $headers, 'body' => $raw];
    handle($state, $method, $uri, $headers, $body, $raw);
}

file_put_contents($stateFile, json_encode($state));
flock($fp, LOCK_UN);

function handle(array &$state, string $method, string $uri, array $headers, array $body, string $raw): void
{
    // Injected failures, consumed in order.
    foreach ($state['fail'] as $i => $fail) {
        if ($fail['path'] === $uri && ($fail['method'] ?? $method) === $method && $fail['times'] > 0) {
            $state['fail'][$i]['times']--;
            if ($fail['status'] === 0) {
                // Hang up without an answer: the client sees a timeout.
                usleep(1_500_000);
                respond(504, error_body(504, 'Gateway timeout.'));

                return;
            }
            respond($fail['status'], $fail['body'] ?? error_body($fail['status'], 'Injected failure.'), $fail['headers'] ?? []);

            return;
        }
    }

    if ($method === 'POST' && $uri === '/api/auth/token') {
        parse_str($raw, $form);
        if (($form['grant_type'] ?? '') !== 'client_credentials') {
            respond(422, error_body(422, 'Le champ grant type est invalide.', ['grant_type' => ['Le champ grant type est invalide.']]));

            return;
        }
        if (($form['client_id'] ?? '') !== CLIENT_ID || ($form['client_secret'] ?? '') !== CLIENT_SECRET) {
            respond(401, error_body(401, 'Le secret client est invalide.'));

            return;
        }
        $state['token_count']++;
        $token = 'tok_'.$state['token_count'].'_'.bin2hex(random_bytes(8));
        $state['tokens'][$token] = true;
        respond(200, ['token_type' => 'Bearer', 'access_token' => $token, 'expires_in' => $state['token_expires_in'], 'scope' => (string) ($form['scope'] ?? '')]);

        return;
    }

    $auth = (string) ($headers['authorization'] ?? '');
    $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if ($token === '' || ! ($state['tokens'][$token] ?? false)) {
        respond(401, error_body(401, 'Access token invalide.'));

        return;
    }

    $project = $headers['x-api-project'] ?? null;
    if ($project !== null && ! preg_match('/^project_[A-Za-z0-9]{12}$/', $project)) {
        respond(422, error_body(422, 'Header x-api-project invalide.'));

        return;
    }

    if ($uri === '/api/forbidden') {
        respond(403, error_body(403, 'Accès refusé.'));

        return;
    }
    if ($uri === '/api/echo') {
        respond(200, ['method' => $method, 'query' => $_GET, 'body' => $body === [] ? new stdClass : $body, 'project' => $project]);

        return;
    }

    if ($method === 'POST' && $uri === '/api/v1/pay/sessions') {
        $errors = [];
        if (! is_int($body['amount_cents'] ?? null) || $body['amount_cents'] < 100 || $body['amount_cents'] > 99_999_900) {
            $errors['amount_cents'] = ['Le montant va d’un euro à 999 999 €.'];
        }
        if (! is_string($body['description'] ?? null) || $body['description'] === '') {
            $errors['description'] = ['Le champ description est obligatoire.'];
        }
        $embedded = ($body['ui_mode'] ?? 'hosted') === 'embedded';
        if ($embedded && isset($body['success_url'])) {
            $errors['success_url'] = ['Le champ success url est interdit quand ui mode vaut embedded.'];
        } elseif (! $embedded && (! is_string($body['success_url'] ?? null) || ! str_starts_with($body['success_url'], 'https://'))) {
            $errors['success_url'] = ['Le champ success url doit être une adresse https.'];
        }
        if (! $embedded && isset($body['return_url'])) {
            $errors['return_url'] = ['Le champ return url est interdit sauf si ui mode vaut embedded.'];
        }
        if ($errors !== []) {
            respond(422, error_body(422, reset($errors)[0], $errors));

            return;
        }
        $key = trim((string) ($headers['idempotency-key'] ?? ''));
        $hash = hash('sha256', json_encode($body));
        if ($key !== '' && isset($state['idempotency'][$key])) {
            $previous = $state['idempotency'][$key];
            if ($previous['hash'] !== $hash) {
                respond(409, error_body(409, 'Cette clé d’idempotence a déjà servi pour une autre demande.'));

                return;
            }
            respond(200, $state['sessions'][$previous['id']]);

            return;
        }
        $id = random_id('pay_sess_');
        $session = [
            'id' => $id, 'object' => 'pay.session', 'url' => $embedded ? null : 'https://pay.example.test/'.substr($id, 9),
            'ui_mode' => $embedded ? 'embedded' : 'hosted', 'status' => 'open', 'amount_cents' => $body['amount_cents'], 'currency' => 'eur',
            'description' => $body['description'], 'reference' => $body['reference'] ?? null,
            'metadata' => $body['metadata'] ?? new stdClass, 'customer_email' => $body['customer_email'] ?? null,
            'success_url' => $body['success_url'] ?? null, 'cancel_url' => $body['cancel_url'] ?? null,
            'return_url' => $body['return_url'] ?? null,
            'pay_account_id' => 'pay_acct_TestAccount01', 'payment_id' => null, 'payment_status' => null,
            'livemode' => false, 'expires_at' => '2026-10-15T10:00:00+00:00', 'completed_at' => null,
            'created_at' => '2026-10-14T10:00:00+00:00',
        ] + ($embedded ? ['client_secret' => substr($id, 9, 10).'_secret_'.str_repeat('A', 32)] : []);
        $state['sessions'][$id] = $session;
        if ($key !== '') {
            $state['idempotency'][$key] = ['hash' => $hash, 'id' => $id];
        }
        respond(201, $session);

        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/pay/sessions/([^/]+)$#', $uri, $m)) {
        $session = $state['sessions'][$m[1]] ?? null;
        $session === null ? respond(404, error_body(404, 'Cette session de paiement est introuvable.')) : respond(200, $session);

        return;
    }

    if ($method === 'GET' && $uri === '/api/v1/pay/payments') {
        $perPage = (int) ($_GET['per_page'] ?? 25);
        if ($perPage > 100) {
            respond(422, error_body(422, 'Le champ per page ne peut pas être supérieur à 100.', ['per_page' => ['Le champ per page ne peut pas être supérieur à 100.']]));

            return;
        }
        $items = $state['payments'];
        if (isset($_GET['status'])) {
            $items = array_values(array_filter($items, fn ($p) => $p['status'] === $_GET['status']));
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $last = max(1, (int) ceil(count($items) / $perPage));
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        $slice = array_map(function ($p) {
            unset($p['refunds']);

            return $p;
        }, $slice);
        respond(200, ['data' => $slice, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => count($items), 'last_page' => $last]]);

        return;
    }

    if (preg_match('#^/api/v1/pay/payments/([^/]+)(/refunds)?$#', $uri, $m)) {
        $index = null;
        foreach ($state['payments'] as $i => $p) {
            if ($p['id'] === $m[1]) {
                $index = $i;
            }
        }
        if ($index === null) {
            respond(404, error_body(404, 'Ce paiement est introuvable.'));

            return;
        }
        if ($method === 'GET' && ! isset($m[2])) {
            respond(200, $state['payments'][$index]);

            return;
        }
        if ($method === 'POST' && isset($m[2])) {
            $p = &$state['payments'][$index];
            $left = $p['amount_cents'] - $p['refunded_cents'];
            $amount = $body['amount_cents'] ?? $left;
            if ($amount < 1 || $amount > $left) {
                respond(422, error_body(422, 'Le montant est hors limites.', ['amount_cents' => ['Le montant est hors limites.']]));

                return;
            }
            $p['refunded_cents'] += $amount;
            $p['status'] = $p['refunded_cents'] === $p['amount_cents'] ? 'refunded' : 'partially_refunded';
            $refund = ['id' => random_id('pay_rfd_'), 'object' => 'pay.refund', 'payment_id' => $p['id'], 'amount_cents' => $amount,
                'currency' => 'eur', 'status' => 'succeeded', 'reason' => $body['reason'] ?? null, 'created_at' => '2026-10-14T11:00:00+00:00'];
            $p['refunds'][] = $refund;
            respond(201, $refund + ['payment' => ['id' => $p['id'], 'object' => 'pay.payment', 'status' => $p['status'], 'amount_cents' => $p['amount_cents'], 'refunded_cents' => $p['refunded_cents']]]);

            return;
        }
    }

    if (preg_match('#^/api/account/pay/(pay_acct_[A-Za-z0-9]+)/webhook(?:/([^/]+))?(/secret)?$#', $uri, $m)) {
        $id = $m[2] ?? '';
        if ($id === '' && $method === 'GET') {
            respond(200, ['data' => array_values($state['webhooks']), 'event_types' => [['code' => 'payment.succeeded', 'label' => 'Paiement réussi']]]);

            return;
        }
        if ($id === '' && $method === 'POST') {
            $wid = random_id('pay_whk_');
            $state['webhooks'][$wid] = ['id' => $wid, 'url' => $body['url'] ?? '', 'description' => $body['description'] ?? null,
                'events' => $body['events'] ?? [], 'enabled' => $body['enabled'] ?? true, 'secret_hint' => 'whsec_…test',
                'secret_rotated_at' => null, 'created_at' => '2026-10-14T10:00:00+00:00'];
            respond(201, $state['webhooks'][$wid] + ['secret' => 'whsec_'.bin2hex(random_bytes(16))]);

            return;
        }
        if (! isset($state['webhooks'][$id])) {
            respond(404, error_body(404, 'Cette adresse de webhook est introuvable.'));

            return;
        }
        if (isset($m[3]) && $method === 'POST') {
            respond(200, $state['webhooks'][$id] + ['secret' => 'whsec_'.bin2hex(random_bytes(16))]);
        } elseif ($method === 'PUT') {
            $state['webhooks'][$id] = array_merge($state['webhooks'][$id], array_intersect_key($body, array_flip(['url', 'description', 'events', 'enabled'])));
            respond(200, $state['webhooks'][$id]);
        } elseif ($method === 'DELETE') {
            unset($state['webhooks'][$id]);
            respond(200, ['deleted' => true]);
        } else {
            respond(200, $state['webhooks'][$id] + ['deliveries' => []]);
        }

        return;
    }

    if ($method === 'POST' && $uri === '/api/v1/sms') {
        $to = is_array($body['to'] ?? null) ? $body['to'] : [(string) ($body['to'] ?? '')];
        $messages = array_map(fn ($n) => ['id' => random_id('sms_msg_'), 'service_id' => $body['service_id'] ?? null, 'to' => $n,
            'type' => $body['type'] ?? null, 'status' => 'queued', 'segments' => 1, 'credits' => 1], $to);
        respond(202, ['batch_id' => random_id('sms_batch_'), 'credits' => count($messages), 'balance' => 100, 'messages' => $messages, 'rejected' => []]);

        return;
    }

    if ($method === 'GET' && preg_match('#^/api/v1/sms/([^/]+)$#', $uri, $m)) {
        respond(200, ['id' => $m[1], 'status' => 'delivered']);

        return;
    }

    respond(404, error_body(404, 'Route introuvable.'));
}
