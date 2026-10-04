<?php
// cathdrwijnen · betalen
// Betalingen aanmaken en ophalen bij Mollie (kale cURL, geen SDK), de
// nep-betalingen van de testmodus, en het verwerken van een betaling: status
// in het bestelbestand bijwerken en bij 'paid' elke mail precies één keer sturen.

declare(strict_types=1);

// Adres van de Mollie-API. Kan alleen voor tests vooraf worden gedefinieerd
// (via auto_prepend_file), nooit via een verzoek.
if (!defined('MOLLIE_API')) {
    define('MOLLIE_API', 'https://api.mollie.com/v2');
}

const BETAAL_STATUSSEN = ['open', 'pending', 'authorized', 'paid', 'canceled', 'expired', 'failed'];
// Statussen die daarna niet meer veranderen
const EIND_STATUSSEN = ['paid', 'canceled', 'expired', 'failed'];
const MOLLIE_ID_PATROON = '/^tr_[A-Za-z0-9]{4,64}\z/';
const NEP_ID_PATROON = '/^nep_[0-9a-f]{16}\z/';

// ---------- Mollie ----------

/**
 * Eén verzoek aan de Mollie-API. Geeft het JSON-antwoord als array terug.
 * Gooit BetaalFout (met de HTTP-status als code) bij elke fout.
 */
function mollie_verzoek(array $config, string $methode, string $pad, ?array $body = null): array
{
    $omschrijving = 'Mollie ' . $methode . ' ' . $pad;
    if (!function_exists('curl_init')) {
        throw new BetaalFout('cURL ontbreekt op deze server');
    }

    $opties = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $methode,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'cathdrwijnen-webshop/1.0',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $config['mollie_api_key'],
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ];
    if ($body !== null) {
        $opties[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    $ch = curl_init(MOLLIE_API . $pad);
    curl_setopt_array($ch, $opties);
    $antwoord = curl_exec($ch);
    if ($antwoord === false) {
        throw new BetaalFout($omschrijving . ': geen verbinding (cURL ' . curl_errno($ch) . ': ' . curl_error($ch) . ')');
    }

    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data = json_decode((string) $antwoord, true);
    if ($code < 200 || $code >= 300) {
        $uitleg = 'geen uitleg';
        if (is_array($data)) {
            $uitleg = trim(mollie_tekst($data['title'] ?? '') . ': ' . mollie_tekst($data['detail'] ?? ''), ' :');
            if (isset($data['field'])) {
                $uitleg .= ' (veld ' . mollie_tekst($data['field']) . ')';
            }
        }
        throw new BetaalFout($omschrijving . ': HTTP ' . $code . ' ' . substr($uitleg, 0, 300), $code);
    }
    if (!is_array($data)) {
        throw new BetaalFout($omschrijving . ': antwoord is geen JSON', $code);
    }
    return $data;
}

function mollie_tekst(mixed $waarde): string
{
    return is_scalar($waarde) ? schoon_regel((string) $waarde) : '';
}

/**
 * Webhook-adres voor Mollie, of null als de site lokaal draait:
 * Mollie kan localhost of een thuisnetwerk niet bereiken (en weigert dan de betaling).
 */
function webhook_url(array $config): ?string
{
    $host = trim(strtolower((string) parse_url($config['site_url'], PHP_URL_HOST)), '[]');
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)
        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }
    return $config['site_url'] . '/api/webhook.php';
}

// ---------- Betaling aanmaken en ophalen ----------

/**
 * Maakt de betaling voor een (al bewaarde) bestelling.
 * Geeft ['id' => ..., 'checkoutUrl' => ..., 'test' => bool] terug.
 */
function betaling_aanmaken(array $config, array $bestelling): array
{
    if (testmodus($config)) {
        return nep_betaling_aanmaken($config, $bestelling);
    }

    $verzoek = [
        'amount'      => ['currency' => 'EUR', 'value' => mollie_bedrag($bestelling['totaal'])],
        'description' => 'Bestelling ' . $bestelling['ref'] . ' ' . $config['winkel_naam'],
        'redirectUrl' => $config['site_url'] . '/bedankt.html?ref=' . rawurlencode($bestelling['ref']),
        'metadata'    => ['ref' => $bestelling['ref']],
        'locale'      => 'nl_NL',
    ];
    $webhook = webhook_url($config);
    if ($webhook !== null) {
        $verzoek['webhookUrl'] = $webhook;
    }

    $data = mollie_verzoek($config, 'POST', '/payments', $verzoek);
    $id = $data['id'] ?? null;
    $checkout = $data['_links']['checkout']['href'] ?? null;
    if (!is_string($id) || !preg_match(MOLLIE_ID_PATROON, $id)
        || !is_string($checkout) || !preg_match('#^https://#', $checkout)) {
        throw new BetaalFout('Mollie POST /payments: onverwacht antwoord (geen id of checkout-link)');
    }

    return [
        'id'          => $id,
        'checkoutUrl' => $checkout,
        'test'        => str_starts_with($config['mollie_api_key'], 'test_'),
    ];
}

/**
 * De actuele stand van een betaling, rechtstreeks bij Mollie (of uit de
 * nep-betalingen in testmodus). Geeft ['id', 'status', 'ref', 'bedrag', 'test']
 * terug, null als de betaling onbekend is, en gooit BetaalFout als Mollie niet
 * bereikbaar is.
 */
function betaling_ophalen(array $config, string $id): ?array
{
    if (preg_match(NEP_ID_PATROON, $id)) {
        if (!testmodus($config)) {
            return null;
        }
        $nep = lees_nep_betaling($config, $id);
        if ($nep === null) {
            return null;
        }
        return [
            'id'     => $id,
            'status' => (string) $nep['status'],
            'ref'    => $nep['ref'] ?? null,
            'bedrag' => (string) $nep['bedrag'],
            'test'   => true,
        ];
    }

    if (!preg_match(MOLLIE_ID_PATROON, $id) || testmodus($config)) {
        return null;
    }
    try {
        $data = mollie_verzoek($config, 'GET', '/payments/' . $id);
    } catch (BetaalFout $e) {
        if ($e->getCode() === 404 || $e->getCode() === 410) {
            return null;
        }
        throw $e;
    }

    $status = $data['status'] ?? null;
    if (($data['id'] ?? null) !== $id || !in_array($status, BETAAL_STATUSSEN, true)) {
        throw new BetaalFout('Mollie GET /payments/' . $id . ': onverwacht antwoord');
    }
    $bedrag = $data['amount'] ?? [];
    return [
        'id'     => $id,
        'status' => $status,
        'ref'    => $data['metadata']['ref'] ?? null,
        'bedrag' => ($bedrag['currency'] ?? null) === 'EUR' ? (string) ($bedrag['value'] ?? '') : '',
        'test'   => ($data['mode'] ?? '') !== 'live',
    ];
}

// ---------- Nep-betalingen (testmodus) ----------

function nep_betaling_pad(array $config, string $id): string
{
    if (!preg_match(NEP_ID_PATROON, $id)) {
        throw new InvalidArgumentException('Ongeldig id voor een nep-betaling');
    }
    return data_map($config, 'nep-betalingen') . '/' . $id . '.json';
}

function nep_betaling_aanmaken(array $config, array $bestelling): array
{
    $id = 'nep_' . bin2hex(random_bytes(8));
    schrijf_json(nep_betaling_pad($config, $id), [
        'id'         => $id,
        'status'     => 'open',
        'ref'        => $bestelling['ref'],
        'bedrag'     => mollie_bedrag($bestelling['totaal']),
        'aangemaakt' => date('c'),
    ]);
    return [
        'id'          => $id,
        'checkoutUrl' => $config['site_url'] . '/api/nep-betaalpagina.php?id=' . $id,
        'test'        => true,
    ];
}

function lees_nep_betaling(array $config, string $id): ?array
{
    if (!preg_match(NEP_ID_PATROON, $id)) {
        return null;
    }
    $nep = lees_json(nep_betaling_pad($config, $id));
    if ($nep === null || ($nep['id'] ?? null) !== $id || !in_array($nep['status'] ?? null, BETAAL_STATUSSEN, true)) {
        return null;
    }
    return $nep;
}

/**
 * Zet de uitkomst van een nep-betaling, net als bij Mollie alleen vanuit 'open'.
 * Geeft de nep-betaling terug zoals die daarna is (of null als die onbekend is).
 */
function zet_nep_status(array $config, string $id, string $status): ?array
{
    return met_slot($config, function () use ($config, $id, $status): ?array {
        $nep = lees_nep_betaling($config, $id);
        if ($nep === null) {
            return null;
        }
        if ($nep['status'] === 'open') {
            $nep['status'] = $status;
            $nep['bijgewerkt'] = date('c');
            schrijf_json(nep_betaling_pad($config, $id), $nep);
        }
        return $nep;
    });
}

// ---------- Verwerken ----------

/**
 * Haalt de betaling zelf op (de aanroeper levert alleen het id), werkt de
 * bestelling bij en stuurt bij 'paid' de mails, elk precies één keer.
 * Geeft de bijgewerkte bestelling terug, of null als betaling of bestelling
 * onbekend is. Gooit BetaalFout als Mollie niet bereikbaar is.
 *
 * $ref        als die al bekend is (status.php), moet de betaling daarbij horen.
 * $mailPauze  na een mislukte mail twee minuten wachten met opnieuw proberen;
 *             status.php wordt elke paar seconden aangeroepen.
 */
function verwerk_betaling(array $config, string $betalingId, ?string $ref = null, bool $mailPauze = false): ?array
{
    $betaling = betaling_ophalen($config, $betalingId);
    if ($betaling === null) {
        return null;
    }

    $betaalRef = $betaling['ref'];
    if (!geldige_ref($betaalRef) || ($ref !== null && $betaalRef !== $ref)) {
        log_fout('verwerken', 'Betaling ' . $betalingId . ' hoort niet bij een geldige bestelling');
        return null;
    }

    return met_slot($config, function () use ($config, $betaling, $betaalRef, $mailPauze): ?array {
        $bestelling = lees_bestelling($config, $betaalRef);
        if ($bestelling === null || ($bestelling['betaling']['id'] ?? null) !== $betaling['id']) {
            log_fout('verwerken', 'Geen bestelling ' . $betaalRef . ' bij betaling ' . $betaling['id']);
            return null;
        }
        if ($betaling['bedrag'] !== mollie_bedrag((int) $bestelling['totaal'])) {
            log_fout('verwerken', 'Bedrag van betaling ' . $betaling['id'] . ' wijkt af van bestelling ' . $betaalRef);
            return null;
        }

        // De stand bij Mollie is buiten het slot opgehaald. Een trage statusvraag
        // kan dus pas binnenkomen nadat de webhook 'paid' al heeft bewaard; die
        // oude stand ('open') mag een afgeronde bestelling nooit terugzetten.
        if (in_array($bestelling['status'], EIND_STATUSSEN, true) && $bestelling['status'] !== $betaling['status']) {
            log_fout('verwerken', 'Verouderde stand ' . $betaling['status'] . ' van betaling ' . $betaling['id']
                . ' genegeerd: bestelling ' . $betaalRef . ' staat al op ' . $bestelling['status']);
        } elseif ($bestelling['status'] !== $betaling['status']) {
            $bestelling['status'] = $betaling['status'];
            $bestelling['geschiedenis'][] = ['tijd' => date('c'), 'status' => $betaling['status']];
            if ($betaling['status'] === 'paid') {
                $bestelling['betaald'] = date('c');
            }
            bewaar_bestelling($config, $bestelling);
        }

        if ($bestelling['status'] === 'paid' && !alles_gemaild($bestelling)) {
            $vorigeFout = isset($bestelling['mailfout']) ? (int) strtotime((string) $bestelling['mailfout']) : 0;
            if (!$mailPauze || time() - $vorigeFout >= 120) {
                $bestelling = verstuur_bestelmails($config, $bestelling);
            }
        }
        return $bestelling;
    });
}

function alles_gemaild(array $bestelling): bool
{
    return !empty($bestelling['gemaild']['cath']) && !empty($bestelling['gemaild']['klant']);
}

/**
 * Stuurt de mails die nog niet verstuurd zijn en bewaart na elke mail meteen
 * dat die weg is. Een mislukte mail komt in het logboek; de volgende aanroep
 * (Mollie roept de webhook opnieuw aan) probeert alleen die mail nog eens.
 */
function verstuur_bestelmails(array $config, array $bestelling): array
{
    foreach (['cath', 'klant'] as $wie) {
        if (!empty($bestelling['gemaild'][$wie])) {
            continue;
        }
        try {
            verstuur_bestelmail($config, $bestelling, $wie);
            $bestelling['gemaild'][$wie] = date('c');
        } catch (Throwable $e) {
            log_fout('mail', 'Mail aan ' . $wie . ' voor ' . $bestelling['ref'] . ' mislukt: ' . fout_omschrijving($e));
            $bestelling['mailfout'] = date('c');
        }
        if (alles_gemaild($bestelling)) {
            unset($bestelling['mailfout']);
        }
        bewaar_bestelling($config, $bestelling);
    }
    return $bestelling;
}
