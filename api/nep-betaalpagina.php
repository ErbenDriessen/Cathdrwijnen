<?php
// cathdrwijnen · nep-betaalpagina (alleen in testmodus)
// Doet na wat de betaalpagina van Mollie doet, zodat de hele bestelling lokaal
// te testen is zonder Mollie-sleutel: kies gelukt, mislukt of annuleren, dan
// volgt dezelfde verwerking als bij de webhook (ook de mails, als .eml-bestand)
// en ga je door naar bedankt.html. Met een Mollie-sleutel in config.php bestaat
// deze pagina niet (404).

declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/winkel.php';
require __DIR__ . '/lib/betaling.php';
require __DIR__ . '/lib/mail.php';

const UITKOMSTEN = [
    'paid'     => 'gelukt',
    'failed'   => 'mislukt',
    'canceled' => 'geannuleerd',
    'expired'  => 'verlopen',
];

function h(string $tekst): string
{
    return htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Toont de pagina met de huisstijl van de site en stopt. */
function nep_pagina(int $code, string $titel, string $inhoud): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
    echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{$titel} · cathdrwijnen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,300..700,0..100&display=swap">
    <link rel="stylesheet" href="../css/salon-v2.css">
    <style>
        .nep { max-width: 560px; margin: 0 auto; padding: 32px 18px 64px; }
        .nep__label {
            display: inline-block;
            margin-bottom: 18px;
            padding: 6px 14px;
            border-radius: 999px;
            background: var(--inkt);
            color: var(--papier);
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.08em;
        }
        .nep__kaart {
            padding: 28px 24px;
            border: 1.5px solid var(--rand);
            border-radius: var(--radius-panel);
            background: var(--wit);
        }
        .nep h1 { font-size: clamp(32px, 7vw, 44px); line-height: 1.05; font-weight: 480; margin-bottom: 14px; }
        .nep p + p { margin-top: 12px; }
        .nep__gegevens { display: grid; grid-template-columns: auto 1fr; gap: 6px 18px; margin: 22px 0; }
        .nep__gegevens dt { color: var(--rand); }
        .nep__gegevens dd { font-weight: 600; }
        .nep__knoppen { display: grid; gap: 12px; margin-top: 8px; }
        .nep__knoppen .btn { width: 100%; }
        .nep__klein { margin-top: 18px; font-size: 16px; color: var(--rand); }
    </style>
</head>
<body>
    <main class="nep">
        <p class="nep__label">TESTMODUS</p>
        <div class="nep__kaart">
            <h1>{$titel}</h1>
            {$inhoud}
        </div>
    </main>
</body>
</html>

HTML;
    exit;
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode !== 'GET' && $methode !== 'POST') {
    header('Allow: GET, POST');
    nep_pagina(405, 'Niet toegestaan', '<p>Deze pagina werkt alleen met gewone links en knoppen.</p>');
}

try {
    $config = config();
} catch (Throwable $e) {
    log_fout('nep-betaalpagina', fout_omschrijving($e));
    nep_pagina(500, 'Er ging iets mis', '<p>De instellingen konden niet worden geladen. Kijk in data/log.txt.</p>');
}

// Met een echte (of test-)sleutel van Mollie hoort deze pagina niet te bestaan
if (!testmodus($config)) {
    nep_pagina(404, 'Niet gevonden', '<p>Deze pagina bestaat niet.</p>');
}

$id = $methode === 'POST' ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null);
$nep = is_string($id) ? lees_nep_betaling($config, $id) : null;
if ($nep === null) {
    nep_pagina(404, 'Testbetaling niet gevonden', '<p>Deze testbetaling bestaat niet (meer).</p>');
}

$bedankt = $config['site_url'] . '/bedankt.html?ref=' . rawurlencode((string) $nep['ref']);

if ($methode === 'POST') {
    $uitkomst = $_POST['uitkomst'] ?? null;
    if (!in_array($uitkomst, ['paid', 'failed', 'canceled'], true)) {
        nep_pagina(400, 'Kies een uitkomst', '<p>Ga terug en kies een van de drie knoppen.</p>');
    }
    try {
        zet_nep_status($config, $id, $uitkomst);
        // Precies wat de webhook doet als Mollie belt (dus ook de mails)
        verwerk_betaling($config, $id);
    } catch (Throwable $e) {
        log_fout('nep-betaalpagina', 'Testbetaling ' . $id . ': ' . fout_omschrijving($e));
        nep_pagina(500, 'Er ging iets mis', '<p>De testbetaling kon niet worden verwerkt. Kijk in data/log.txt.</p>');
    }
    // Net als Mollie: altijd door naar de bedankpagina, die de status zelf opvraagt
    header('Location: ' . $bedankt, true, 303);
    exit;
}

$bedrag = euro((int) str_replace('.', '', (string) $nep['bedrag']));
$gegevens = '<dl class="nep__gegevens">'
    . '<dt>Bestelling</dt><dd>' . h((string) $nep['ref']) . '</dd>'
    . '<dt>Bedrag</dt><dd>' . h($bedrag) . '</dd>'
    . '</dl>';

if ($nep['status'] !== 'open') {
    $stand = UITKOMSTEN[$nep['status']] ?? $nep['status'];
    nep_pagina(200, 'Testbetaling afgerond', $gegevens
        . '<p>Deze testbetaling is al afgerond: ' . h($stand) . '.</p>'
        . '<p class="nep__knoppen"><a class="btn btn--wine" href="' . h($bedankt) . '">Naar de bedankpagina</a></p>');
}

nep_pagina(200, 'Testbetaling', '<p>Dit is geen echte betaling en er wordt niets afgeschreven. '
    . 'Zo kun je de webshop uitproberen zonder Mollie. Kies wat er met deze betaling gebeurt.</p>'
    . $gegevens
    . '<form class="nep__knoppen" method="post" action="nep-betaalpagina.php">'
    . '<input type="hidden" name="id" value="' . h($id) . '">'
    . '<button class="btn btn--wine" type="submit" name="uitkomst" value="paid">Betaling gelukt</button>'
    . '<button class="btn btn--ghost" type="submit" name="uitkomst" value="failed">Betaling mislukt</button>'
    . '<button class="btn btn--ghost" type="submit" name="uitkomst" value="canceled">Annuleren</button>'
    . '</form>'
    . '<p class="nep__klein">Daarna ga je, net als bij Mollie, door naar de bedankpagina. '
    . 'Bij "Betaling gelukt" komen de mails als bestand in data/mails.</p>');
