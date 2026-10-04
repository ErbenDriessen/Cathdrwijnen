<?php
// cathdrwijnen · bestelling plaatsen
// POST met de bestelling als JSON:
//   { items: { cadeau: 1, salon: 2 }, levering: 'bezorgen' | 'ophalen',
//     klant: { naam, email, telefoon (optioneel),
//              straat, huisnummer, postcode, plaats (alleen bij bezorgen) },
//     opmerking (optioneel), achttienPlus: true, voorwaarden: true, website: '' }
// Controleert alles, rekent de bedragen zelf uit pakketten.json (prijzen van de
// browser tellen niet), bewaart de bestelling en start de betaling.
// Antwoord: 200 { ok: true, ref, checkoutUrl }, 422 { ok: false, melding, fouten }
// met fouten per veld ('klant.email', 'items', ...), of 500/502 { ok: false, melding }.

declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/winkel.php';
require __DIR__ . '/lib/betaling.php';

alleen_methode('POST');
$invoer = lees_json_verzoek(16384);

$nietGestart = ['ok' => false, 'melding' => 'Het betalen kon niet worden gestart. Probeer het zo nog eens.'];

try {
    $config = config();
    $catalogus = catalogus();
} catch (Throwable $e) {
    log_fout('bestelling', fout_omschrijving($e));
    json_antwoord(500, $nietGestart);
}

// Vangnet: met een live-sleutel alleen verkopen als de bedrijfsgegevens zijn
// ingevuld en de voorwaarden en privacyverklaring echt af zijn (zie api/LEESMIJ.md)
if (str_starts_with($config['mollie_api_key'], 'live_')) {
    $missend = nog_niet_klaar_voor_live($config);
    if ($missend !== []) {
        log_fout('bestelling', 'Live-sleutel, maar de winkel is nog niet klaar: ' . implode('; ', $missend));
        json_antwoord(500, $nietGestart);
    }
}

// Honeypot: een verborgen veld dat alleen spambots invullen
$website = $invoer['website'] ?? '';
if ($website !== '') {
    json_antwoord(422, [
        'ok'      => false,
        'melding' => 'Je bestelling kon niet worden verwerkt. Probeer het nog eens.',
        'fouten'  => (object) [],
    ]);
}

[$schoon, $fouten] = controleer_bestelling($invoer, $catalogus);
if ($fouten !== []) {
    json_antwoord(422, [
        'ok'      => false,
        'melding' => 'Niet alles is goed ingevuld. Kijk de velden met een melding nog even na.',
        'fouten'  => $fouten,
    ]);
}

$bedragen = bereken_bedragen($schoon['items'], $schoon['levering'], $catalogus);

// Eerst de bestelling bewaren, dan pas de betaling: zo hoort elke betaling bij een bestelling
try {
    $ref = nieuwe_ref($config);
    $nu = date('c');
    $bestelling = [
        'versie'        => 1,
        'ref'           => $ref,
        'aangemaakt'    => $nu,
        'bijgewerkt'    => $nu,
        'status'        => 'open',
        'regels'        => $bedragen['regels'],
        'subtotaal'     => $bedragen['subtotaal'],
        'verzendkosten' => $bedragen['verzendkosten'],
        'totaal'        => $bedragen['totaal'],
        'levering'      => $schoon['levering'],
        'klant'         => $schoon['klant'],
        'opmerking'     => $schoon['opmerking'],
        // De klant heeft beide vinkjes zelf gezet (Alcoholwet en voorwaarden)
        'bevestigd'     => ['achttienPlus' => true, 'voorwaarden' => true, 'tijd' => $nu],
        'betaling'      => null,
        'gemaild'       => ['cath' => null, 'klant' => null],
        'geschiedenis'  => [['tijd' => $nu, 'status' => 'open']],
    ];
    bewaar_bestelling($config, $bestelling);
} catch (Throwable $e) {
    log_fout('bestelling', 'Bestelling niet bewaard: ' . fout_omschrijving($e));
    json_antwoord(500, $nietGestart);
}

try {
    $betaling = betaling_aanmaken($config, $bestelling);
} catch (Throwable $e) {
    log_fout('bestelling', 'Betaling voor ' . $ref . ' niet gestart: ' . fout_omschrijving($e));
    try {
        $bestelling['status'] = 'failed';
        $bestelling['fout'] = 'Betaling kon niet worden gestart';
        $bestelling['geschiedenis'][] = ['tijd' => date('c'), 'status' => 'failed'];
        bewaar_bestelling($config, $bestelling);
    } catch (Throwable $e2) {
        log_fout('bestelling', fout_omschrijving($e2));
    }
    json_antwoord($e instanceof BetaalFout ? 502 : 500, $nietGestart);
}

try {
    $bestelling['betaling'] = ['id' => $betaling['id'], 'test' => $betaling['test']];
    bewaar_bestelling($config, $bestelling);
} catch (Throwable $e) {
    log_fout('bestelling', 'Betaling ' . $betaling['id'] . ' niet bij ' . $ref . ' bewaard: ' . fout_omschrijving($e));
    json_antwoord(500, $nietGestart);
}

json_antwoord(200, ['ok' => true, 'ref' => $ref, 'checkoutUrl' => $betaling['checkoutUrl']]);
