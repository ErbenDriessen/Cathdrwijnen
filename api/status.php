<?php
// cathdrwijnen · status van een bestelling
// GET status.php?ref=CDW-... voor bedankt.html. Vraagt de actuele stand bij
// Mollie (of de nep-betaling) op en verwerkt die op dezelfde manier als de
// webhook: komt de webhook niet aan, dan gaan de mails zo alsnog de deur uit.
// Antwoord: { ok: true, status, ref } of 404 { ok: false }. Nooit persoonsgegevens.

declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/winkel.php';
require __DIR__ . '/lib/betaling.php';
require __DIR__ . '/lib/mail.php';

alleen_methode('GET');

// Eerst de vorm controleren, pas daarna iets met het bestandssysteem doen
$ref = $_GET['ref'] ?? null;
if (!geldige_ref($ref)) {
    json_antwoord(404, ['ok' => false]);
}

try {
    $config = config();
    $bestelling = lees_bestelling($config, $ref);
} catch (Throwable $e) {
    log_fout('status', fout_omschrijving($e));
    json_antwoord(500, ['ok' => false]);
}
if ($bestelling === null) {
    json_antwoord(404, ['ok' => false]);
}

$status = (string) $bestelling['status'];
$betalingId = $bestelling['betaling']['id'] ?? null;

// Afgerond en alles verstuurd: niet nog eens bij Mollie navragen
$klaar = in_array($status, EIND_STATUSSEN, true) && ($status !== 'paid' || alles_gemaild($bestelling));

if (is_string($betalingId) && !$klaar) {
    try {
        $bijgewerkt = verwerk_betaling($config, $betalingId, $ref, true);
        if ($bijgewerkt !== null) {
            $status = (string) $bijgewerkt['status'];
        }
    } catch (Throwable $e) {
        // Mollie even niet bereikbaar: de laatst bekende status is goed genoeg
        log_fout('status', 'Status van ' . $ref . ' niet opgehaald: ' . fout_omschrijving($e));
    }
}

if (!in_array($status, BETAAL_STATUSSEN, true)) {
    $status = 'open';
}
json_antwoord(200, ['ok' => true, 'status' => $status, 'ref' => $ref]);
