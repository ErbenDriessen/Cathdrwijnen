<?php
// cathdrwijnen · webhook voor Mollie
// Mollie stuurt hier (form-encoded) alleen id=tr_... naartoe als er iets met een
// betaling verandert. We vertrouwen niets anders uit het verzoek: de status
// halen we zelf bij Mollie op. Bij 'paid' gaan de mails naar Cath en de klant,
// elk precies één keer, ook als Mollie vaker belt.
// Antwoord altijd 200, behalve bij een interne fout (dan 500 en probeert Mollie het later opnieuw).

declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/winkel.php';
require __DIR__ . '/lib/betaling.php';
require __DIR__ . '/lib/mail.php';

alleen_methode('POST');

$id = $_POST['id'] ?? null;
if (!is_string($id) || (!preg_match(MOLLIE_ID_PATROON, $id) && !preg_match(NEP_ID_PATROON, $id))) {
    // Onbekend of onzin: niets te doen, en niets prijsgeven
    json_antwoord(200, ['ok' => true]);
}

try {
    $config = config();
    $bestelling = verwerk_betaling($config, $id);
} catch (Throwable $e) {
    log_fout('webhook', 'Betaling ' . $id . ': ' . fout_omschrijving($e));
    json_antwoord(500, ['ok' => false]);
}

if ($bestelling !== null && $bestelling['status'] === 'paid' && !alles_gemaild($bestelling)) {
    // Een mail is mislukt (zie data/log.txt): Mollie probeert het later opnieuw
    json_antwoord(500, ['ok' => false]);
}

json_antwoord(200, ['ok' => true]);
