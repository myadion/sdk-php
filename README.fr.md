# Bibliothèque PHP MyAdion

La bibliothèque PHP officielle de l'API [Adion Cloud](https://myadion.com) : encaisser par carte avec **Adion Pay**, envoyer des **SMS**, et appeler toute autre route de l'API.

[English version](README.md)

- PHP 8.1 ou plus récent
- Obtient et renouvelle seule son jeton d'accès (OAuth `client_credentials`)
- Réessaie sans risque les appels limités ou en échec, avec un `Idempotency-Key` automatique là où l'API l'accepte
- Erreurs typées, avec les messages de validation champ par champ
- Vérification de la signature des webhooks
- Pagination à la demande
- Fonctionne avec tout client HTTP PSR-18 : Guzzle s'il est installé, sinon un petit client cURL
- Intégration Laravel facultative, découverte automatiquement

## Installation

```bash
composer require myadion/sdk
```

## Identifiants

Dans l'espace client, ouvrez **Mon compte › Applications** et créez une application de type **serveur** avec les scopes utiles :

| Scope | Pour |
| --- | --- |
| `pay.session.write` | créer des sessions de paiement |
| `pay.payment.read` | relire sessions et paiements |
| `pay.refund.write` | rembourser |
| `pay.webhook.manage` | gérer les adresses de webhook |
| `sms.message.send` | envoyer des SMS |
| `sms.service.read` | relire l'état d'un SMS |

L'identifiant et le secret restent sur votre serveur, dans des variables d'environnement. Jamais dans un navigateur ni dans une application mobile.

## Utilisation

```php
$client = new \MyAdion\Client([
    'client_id' => getenv('MYADION_CLIENT_ID'),
    'client_secret' => getenv('MYADION_CLIENT_SECRET'),
    // 'project' => 'project_AbCdEf123456', // si l'application couvre plusieurs projets
]);

$session = $client->pay->sessions->create([
    'amount_cents' => 4990,                 // 49,90 € TTC
    'description' => 'Commande n° 4521',
    'reference' => 'CMD-4521',
    'metadata' => ['order_id' => '4521'],
    'customer_email' => 'client@exemple.fr',
    'success_url' => 'https://boutique.exemple.fr/commande/4521/merci',
    'cancel_url' => 'https://boutique.exemple.fr/panier',
], ['idempotency_key' => 'commande-4521']);

header('Location: '.$session->url);
```

Payé, l'acheteur revient sur `success_url?session_id=pay_sess_…` (ou `{SESSION_ID}` est remplacé dans votre adresse). Ce retour ne prouve rien : attendez le webhook `payment.succeeded`, ou relisez la session.

#### Formulaire de paiement intégré

Pour garder l'acheteur sur votre page, créez la session avec `ui_mode` à `embedded`. Elle n'a pas d'`url`, mais un `client_secret` à elle que votre page passe au script d'Adion ; le formulaire de carte vit dans une iframe servie par `pay.adioncloud.com`, les données de carte ne passent jamais par votre page. `return_url` remplace `success_url` et `cancel_url`. Déclarez d'abord l'origine de votre page (espace client › compte Pay › onglet Intégration).

```php
$session = $client->pay->sessions->create([
    'amount_cents' => 4990,
    'description' => 'Commande 4521',
    'reference' => 'CMD-4521',
    'ui_mode' => 'embedded',
    'return_url' => 'https://boutique.example.com/commande/4521/merci',
], ['idempotency_key' => 'commande-4521']);

echo json_encode(['clientSecret' => $session->client_secret]);
```

```html
<div id="paiement"></div>
<script src="https://pay.adioncloud.com/v1/adion-pay.js"></script>
<script type="module">
  const { clientSecret } = await (await fetch('/paiement/session', { method: 'POST' })).json()
  const checkout = await AdionPay().initEmbeddedCheckout({ clientSecret, onComplete: () => location.assign('/commande/4521/merci') })
  checkout.mount('#paiement')
</script>
```

Le `client_secret` n'ouvre que cette session, et expire avec elle. Comme pour la redirection, `onComplete` ne prouve rien : le webhook `payment.succeeded` fait foi.

Les objets se lisent en propriétés ou en clés : `$session->url`, `$session['url']`, `$payment->metadata->order_id`, `$payment->toArray()`.

### Adion Pay

```php
// Sessions
$session = $client->pay->sessions->create([...]);
$session = $client->pay->sessions->retrieve('pay_sess_AbCdEf123456');

// Paiements, du plus récent au plus ancien
foreach ($client->pay->payments->all(['status' => 'succeeded'])->autoPagingIterator() as $payment) {
    echo $payment->id, ' ', $payment->amount_cents, PHP_EOL;
}
$payment = $client->pay->payments->retrieve('pay_pmt_AbCdEf123456'); // avec ses remboursements

// Remboursements : sans amount_cents, tout le reste est remboursé
$refund = $client->pay->refunds->create('pay_pmt_AbCdEf123456', ['amount_cents' => 1000, 'reason' => 'Article manquant']);

// Adresses de webhook d'un compte Pay
$endpoint = $client->pay->webhookEndpoints->create('pay_acct_AbCdEf123456', [
    'url' => 'https://boutique.exemple.fr/webhooks/adion-pay',
    'events' => ['payment.succeeded', 'payment.failed', 'refund.succeeded'],
]);
$endpoint->secret; // whsec_…, montré une seule fois : rangez-le tout de suite
```

`all`, `retrieve`, `update`, `delete`, `rotateSecret`, `deliveries` et `resendDelivery` complètent les adresses de webhook (voir le [README anglais](README.md#adion-pay)).

### SMS

```php
$batch = $client->sms->send([
    'service_id' => 'service_AbCdEf123456',
    'to' => '+33612345678',
    'message' => 'Votre code : 482913',
    'type' => 'transactional',
]);
$client->sms->retrieve($batch->messages[0]->id)->status; // queued, sent, delivered ou failed
```

### Toute autre route

Chaque route de la [référence](https://adion-api.eu) s'appelle avec la même authentification, les mêmes nouvelles tentatives et les mêmes erreurs :

```php
$comptes = $client->request('GET', '/account/pay');
$page = $client->requestCollection('GET', '/v1/pay/payments', ['per_page' => 100]);
```

## Webhooks

Chaque envoi est signé dans l'en-tête `Adion-Signature`. Vérifiez-le sur le corps **brut** :

```php
$event = \MyAdion\Webhook::constructEvent(
    file_get_contents('php://input'),
    $_SERVER['HTTP_ADION_SIGNATURE'] ?? '',
    getenv('MYADION_WEBHOOK_SECRET'),
);

if ($event->type === \MyAdion\Event::PAYMENT_SUCCEEDED) {
    $payment = $event->data->object;
    // marquer la commande $payment->reference comme payée
}
http_response_code(204);
```

La comparaison se fait en temps constant, et une signature de plus de cinq minutes (dans un sens ou dans l'autre) est refusée ; le quatrième argument change cette tolérance, en secondes. En cas d'échec, `MyAdion\Exception\SignatureVerificationError` : répondez 400.

Répondez 2xx vite. Un même événement peut arriver deux fois : servez-vous de `$event->id` pour ne le traiter qu'une fois.

## Erreurs

`AuthenticationError` (401), `PermissionError` (403), `InvalidRequestError` (400, 402, 404, 409, 422, avec `getErrors()` champ par champ), `RateLimitError` (429 après les nouvelles tentatives), `ApiError` (5xx), `ApiConnectionError` (API injoignable), `SignatureVerificationError` (webhook). Toutes héritent de `MyAdion\Exception\MyAdionException`.

## Nouvelles tentatives et idempotence

Jusqu'à `max_retries` nouvelles tentatives (2 par défaut), espacées de plus en plus, en suivant `Retry-After` s'il est présent :

- sur un **429**, toujours : l'API a refusé l'appel avant de faire quoi que ce soit ;
- sur un **5xx** ou une coupure réseau, seulement quand c'est sans risque : `GET`, `PUT`, `DELETE`, et `POST /v1/pay/sessions`, qui porte un `Idempotency-Key` (créé s'il n'est pas fourni, identique d'un essai à l'autre).

Un remboursement ou un SMS n'est jamais renvoyé après une erreur serveur ou une coupure : relisez le paiement ou le message pour savoir où il en est.

## Mode test

Pas de clé de test ni d'interrupteur : utilisez les identifiants d'une application créée dans une **organisation de test**. La bibliothèque fonctionne pareil, et les objets comme les événements portent `livemode: false`. Passer en production, c'est changer d'identifiants (et de secret de webhook), rien d'autre.

## Laravel

Le fournisseur de services et la façade `MyAdion` sont découverts automatiquement. Dans `.env` :

```dotenv
MYADION_CLIENT_ID=…
MYADION_CLIENT_SECRET=…
MYADION_PROJECT=project_AbCdEf123456   # facultatif
MYADION_WEBHOOK_SECRET=whsec_…
```

Injectez `MyAdion\Client` là où vous en avez besoin. La configuration se publie avec `php artisan vendor:publish --tag=myadion-config`. Le jeton est gardé dans le cache de l'application, partagé par tous les processus.

## Développement

```bash
composer install
composer test      # Pint, PHPStan (niveau 8), PHPUnit
```

Les tests tournent contre une fausse API servie en local par `php -S` ; ils n'appellent jamais la vraie.

## Liens

- Guides : [docs.myadion.com](https://docs.myadion.com/api/bibliotheques)
- Référence de l'API : [adion-api.eu](https://adion-api.eu)
- Bibliothèque Node.js : [myadion/sdk-node](https://github.com/myadion/sdk-node)

## Licence

MIT, voir [LICENSE](LICENSE).
