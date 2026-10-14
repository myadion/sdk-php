# MyAdion PHP library

The official PHP library for the [Adion Cloud](https://myadion.com) API: take card payments with **Adion Pay**, send **SMS**, and call any other route of the API.

[Version française](README.fr.md)

- PHP 8.1 or later
- Gets and renews its access token by itself (OAuth `client_credentials`)
- Retries rate-limited and failed calls safely, with an automatic `Idempotency-Key` where the API accepts one
- Typed errors, with per-field validation messages
- Webhook signature verification
- Lazy pagination
- Works with any PSR-18 HTTP client: Guzzle when installed, a small cURL client otherwise
- Optional Laravel integration, discovered automatically

## Installation

```bash
composer require myadion/sdk
```

## Credentials

In the customer area, open **My account › Applications** and create a **server** application with the scopes you need, for instance:

| Scope | For |
| --- | --- |
| `pay.session.write` | creating payment sessions |
| `pay.payment.read` | reading sessions and payments |
| `pay.refund.write` | refunding |
| `pay.webhook.manage` | managing webhook endpoints |
| `sms.message.send` | sending SMS |
| `sms.service.read` | reading the status of an SMS |

Keep the client id and the secret on your server, in environment variables. Never ship them to a browser or a mobile app.

## Usage

```php
$client = new \MyAdion\Client([
    'client_id' => getenv('MYADION_CLIENT_ID'),
    'client_secret' => getenv('MYADION_CLIENT_SECRET'),
    // 'project' => 'project_AbCdEf123456', // when the application spans several projects
]);

$session = $client->pay->sessions->create([
    'amount_cents' => 4990,                 // €49.90, VAT included
    'description' => 'Order 4521',
    'reference' => 'ORDER-4521',
    'metadata' => ['order_id' => '4521'],
    'customer_email' => 'buyer@example.com',
    'success_url' => 'https://shop.example.com/orders/4521/thanks',
    'cancel_url' => 'https://shop.example.com/cart',
], ['idempotency_key' => 'order-4521']);

header('Location: '.$session->url);
```

Paid, the buyer comes back to `success_url?session_id=pay_sess_…` (or `{SESSION_ID}` in your URL is replaced). That return proves nothing: wait for the `payment.succeeded` webhook, or read the session again.

Objects read as properties or as array keys: `$session->url`, `$session['url']`, `$payment->metadata->order_id`, `$payment->toArray()`.

### Adion Pay

```php
// Sessions
$session = $client->pay->sessions->create([...]);
$session = $client->pay->sessions->retrieve('pay_sess_AbCdEf123456');

// Payments, most recent first
$page = $client->pay->payments->all(['status' => 'succeeded', 'per_page' => 50]);
foreach ($page->autoPagingIterator() as $payment) {
    echo $payment->id, ' ', $payment->amount_cents, PHP_EOL;
}
$payment = $client->pay->payments->retrieve('pay_pmt_AbCdEf123456'); // with its refunds

// Refunds: without amount_cents, everything left is refunded
$refund = $client->pay->refunds->create('pay_pmt_AbCdEf123456', ['amount_cents' => 1000, 'reason' => 'Missing item']);

// Webhook endpoints of a Pay account
$endpoint = $client->pay->webhookEndpoints->create('pay_acct_AbCdEf123456', [
    'url' => 'https://shop.example.com/webhooks/adion-pay',
    'events' => ['payment.succeeded', 'payment.failed', 'refund.succeeded'],
]);
$endpoint->secret; // whsec_…, shown only once: store it now
$client->pay->webhookEndpoints->all('pay_acct_AbCdEf123456');
$client->pay->webhookEndpoints->update('pay_acct_AbCdEf123456', $endpoint->id, ['enabled' => false]);
$client->pay->webhookEndpoints->rotateSecret('pay_acct_AbCdEf123456', $endpoint->id);
$client->pay->webhookEndpoints->deliveries('pay_acct_AbCdEf123456', $endpoint->id, ['detail' => true]);
$client->pay->webhookEndpoints->resendDelivery('pay_acct_AbCdEf123456', $endpoint->id, 'pay_dlv_AbCdEf123456');
$client->pay->webhookEndpoints->delete('pay_acct_AbCdEf123456', $endpoint->id);
```

### SMS

```php
$batch = $client->sms->send([
    'service_id' => 'service_AbCdEf123456',
    'to' => ['+33612345678', '+33698765432'],
    'message' => 'Your code: 482913',
    'type' => 'transactional',
]);
$message = $client->sms->retrieve($batch->messages[0]->id);
$message->status; // queued, sent, delivered or failed
```

### Any other route

Every route of the [API reference](https://adion-api.eu) can be called with the same authentication, retries and errors:

```php
$accounts = $client->request('GET', '/account/pay');
$result = $client->request('POST', '/some/route', ['field' => 'value'], ['project' => 'project_AbCdEf123456']);

// A list route, with its pages
foreach ($client->requestCollection('GET', '/v1/pay/payments', ['per_page' => 100])->autoPagingIterator() as $item) {
    // …
}
```

`$client->getLastResponse()` gives the status, headers and body of the last call (201 for a new session, 200 when an idempotency key returned an existing one).

## Webhooks

Each delivery is signed in the `Adion-Signature` header. Verify it on the **raw** body:

```php
$event = \MyAdion\Webhook::constructEvent(
    file_get_contents('php://input'),
    $_SERVER['HTTP_ADION_SIGNATURE'] ?? '',
    getenv('MYADION_WEBHOOK_SECRET'),
);

if ($event->type === \MyAdion\Event::PAYMENT_SUCCEEDED) {
    $payment = $event->data->object;
    // mark the order $payment->reference as paid
}
http_response_code(204);
```

`constructEvent()` compares signatures in constant time and refuses a signature older (or newer) than five minutes; the fourth argument changes that tolerance, in seconds. On failure it throws `MyAdion\Exception\SignatureVerificationError`: answer 400.

Answer 2xx quickly and do the long work afterwards. A delivery that fails is retried for three days, so the same event can arrive twice: use `$event->id` to handle it once.

Events: `payment.succeeded`, `payment.failed`, `refund.succeeded`, `dispute.opened`, `dispute.closed`, `session.expired`.

To test your handler, `\MyAdion\WebhookSignature::generateTestHeaderString($payload, $secret)` builds a valid header.

## Errors

| Exception | When |
| --- | --- |
| `MyAdion\Exception\AuthenticationError` | 401: wrong credentials, revoked application |
| `MyAdion\Exception\PermissionError` | 403: missing scope, project outside the application |
| `MyAdion\Exception\InvalidRequestError` | 400, 402, 404, 409, 422: the request as written is refused |
| `MyAdion\Exception\RateLimitError` | 429, after the retries |
| `MyAdion\Exception\ApiError` | 5xx, or an answer that cannot be read |
| `MyAdion\Exception\ApiConnectionError` | the API could not be reached |
| `MyAdion\Exception\SignatureVerificationError` | a webhook signature is wrong |

They all extend `MyAdion\Exception\MyAdionException`, which gives `getHttpStatus()`, `getErrorCode()` (`validation_error`, `not_found`…), `getDetails()`, `getHttpHeaders()` and `getHttpBody()`.

```php
try {
    $client->pay->sessions->create([...]);
} catch (\MyAdion\Exception\InvalidRequestError $e) {
    $e->getErrors();                    // ['success_url' => ['…must be an https address.']]
    $e->getFieldErrors('success_url');
}
```

## Retries and idempotency

The library retries a call up to `max_retries` times (2 by default), with exponential backoff and jitter, honouring `Retry-After` when the API sends it:

- on **429**, always: the API refused the call before doing anything;
- on **5xx** and network errors, only when a retry is harmless: `GET`, `PUT`, `DELETE`, and `POST /v1/pay/sessions`, which carries an `Idempotency-Key` (generated if you give none, identical across retries).

A refund or an SMS is never retried after a server error or a lost connection: read the payment, or the message, to know where it stands.

Give your own key, one per order, to make **your** retries safe too: `['idempotency_key' => 'order-4521']`. The same key with the same body returns the same session; with another body, the API answers 409.

The API accepts 120 Adion Pay calls per minute and per application.

## Configuration

| Option | Default | |
| --- | --- | --- |
| `client_id`, `client_secret` | | the server application |
| `access_token` | | a token you obtained yourself, instead of the credentials (never renewed) |
| `scopes` | all of the application's | list or space-separated string |
| `project` | | sent as `x-api-project`; required when the application spans several projects |
| `organization` | | sent as `x-api-org` |
| `base_url` | `https://adion-api.eu/api` | |
| `timeout`, `connect_timeout` | 30, 10 | seconds |
| `max_retries` | 2 | |
| `http_client` | Guzzle, else cURL | any PSR-18 client; set its timeouts yourself |
| `request_factory`, `stream_factory` | nyholm/psr7 | PSR-17 factories |
| `token_store` | | a `MyAdion\TokenStore` to share the token between PHP processes |
| `app_info` | | `['name' => 'MyPlugin', 'version' => '1.2.0', 'url' => '…']`, added to the User-Agent |

Every method also takes a last `$options` array: `project`, `organization`, `idempotency_key`, `headers`, `max_retries`.

## Test mode

There is no test key and no test switch. Use the credentials of an application created in a **test organization**: the library works the same, and objects and events carry `livemode: false`. Going live means swapping the credentials (and the webhook secret), nothing else.

## Laravel

The service provider and the `MyAdion` facade are discovered automatically. Add to `.env`:

```dotenv
MYADION_CLIENT_ID=…
MYADION_CLIENT_SECRET=…
MYADION_PROJECT=project_AbCdEf123456   # optional
MYADION_WEBHOOK_SECRET=whsec_…
```

Publish the configuration if you want to change it: `php artisan vendor:publish --tag=myadion-config`.

```php
use MyAdion\Client;

public function checkout(Order $order, Client $myadion)
{
    $session = $myadion->pay->sessions->create([
        'amount_cents' => $order->total_cents,
        'description' => 'Order '.$order->id,
        'reference' => (string) $order->id,
        'success_url' => route('orders.thanks', $order),
    ], ['idempotency_key' => 'order-'.$order->id]);

    return redirect()->away($session->url);
}

// routes/web.php, outside the CSRF protection
Route::post('/webhooks/adion-pay', function (Request $request) {
    try {
        $event = \MyAdion\Webhook::constructEvent(
            $request->getContent(),
            (string) $request->header('Adion-Signature'),
            config('myadion.webhook_secret'),
        );
    } catch (\MyAdion\Exception\SignatureVerificationError) {
        abort(400);
    }
    // …
    return response()->noContent();
});
```

In Laravel, the access token is kept in the application cache and shared by every worker (`token_cache_store`).

## Development

```bash
composer install
composer test      # Pint, PHPStan (level 8), PHPUnit
```

The tests run against a fake API served locally by `php -S` (`tests/Fixtures/server.php`); they never call the real API.

## Links

- Guides: [docs.myadion.com](https://docs.myadion.com/api/bibliotheques)
- API reference: [adion-api.eu](https://adion-api.eu)
- Node.js library: [myadion/sdk-node](https://github.com/myadion/sdk-node)
- Security: see [SECURITY.md](SECURITY.md)

## License

MIT, see [LICENSE](LICENSE).
