<?php
// cathdrwijnen · bestelling ontbinden (art. 6:230oa BW)
// POST met JSON: { naam, bestelnummer, email, website: '' } vanuit ontbinden.html.
// Bewaart de ontbinding als data/ontbindingen/<ref of onbekend>-<tijd>.json en
// stuurt de klant meteen een ontvangstbevestiging (met wat er is ingevuld en
// wanneer) en Cath een melding. Een onbekend bestelnummer weigeren we nooit: dat
// is aan Cath om uit te zoeken.
// Antwoord: 200 { ok: true, ontvangen: '4 oktober 2026 om 15:02', gemaild: bool },
// 422 { ok: false, melding, fouten } met fouten per veld ('naam', 'bestelnummer',
// 'email'), 429 bij te veel aanvragen, of 500 { ok: false, melding }.

declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/winkel.php';
require __DIR__ . '/lib/mail.php';

// Zoveel ontbindingen per uur; daarboven is het geen klant meer maar misbruik
// (iemand die via dit formulier mails naar willekeurige adressen wil sturen)
const MAX_PER_UUR = 30;

alleen_methode('POST');
$invoer = lees_json_verzoek(4096);

try {
    $config = config();
} catch (Throwable $e) {
    log_fout('ontbinden', fout_omschrijving($e));
    json_antwoord(500, ['ok' => false, 'melding' => 'Je ontbinding kon niet worden verwerkt. Probeer het zo nog eens.']);
}
$nietGelukt = [
    'ok'      => false,
    'melding' => 'Je ontbinding kon niet worden verwerkt. Probeer het zo nog eens, of mail Cath op ' . $config['mail_cath'] . '.',
];

// Honeypot: een verborgen veld dat alleen spambots invullen
if (($invoer['website'] ?? '') !== '') {
    json_antwoord(422, ['ok' => false, 'melding' => 'Je ontbinding kon niet worden verwerkt. Probeer het nog eens.', 'fouten' => (object) []]);
}

// ---------- Controleren ----------

$fouten = [];

$naam = tekstveld($invoer['naam'] ?? null);
if ($naam === null || $naam === '') {
    $fouten['naam'] = 'Vul je naam in.';
} elseif (tekens($naam) > MAX_LENGTE['naam']) {
    $fouten['naam'] = 'Je naam is te lang (hoogstens ' . MAX_LENGTE['naam'] . ' tekens).';
}

$bestelnummer = tekstveld($invoer['bestelnummer'] ?? null);
if ($bestelnummer === null || $bestelnummer === '') {
    $fouten['bestelnummer'] = 'Vul je bestelnummer in. Weet je het niet meer? Schrijf dan iets waaraan Cath je bestelling herkent, zoals de datum.';
} elseif (tekens($bestelnummer) > 60) {
    $fouten['bestelnummer'] = 'Dit is te lang voor een bestelnummer (hoogstens 60 tekens).';
}

$email = tekstveld($invoer['email'] ?? null);
if ($email === null || $email === '') {
    $fouten['email'] = 'Vul je e-mailadres in. Daar sturen we de bevestiging naartoe.';
} elseif (strlen($email) > MAX_LENGTE['email'] || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fouten['email'] = 'Vul een geldig e-mailadres in, bijvoorbeeld naam@voorbeeld.nl.';
}

if ($fouten !== []) {
    json_antwoord(422, [
        'ok'      => false,
        'melding' => 'Niet alles is goed ingevuld. Kijk de velden met een melding nog even na.',
        'fouten'  => $fouten,
    ]);
}

// "cdw 20261004 abc234" of "cdw-20261004-abc234" wordt CDW-20261004-ABC234
$ref = strtoupper((string) preg_replace('/[\s-]+/', '-', $bestelnummer));
$ref = geldige_ref($ref) ? $ref : null;

// ---------- Bewaren ----------

try {
    $map = data_map($config, 'ontbindingen');
    $nu = time();
    $recent = 0;
    foreach (glob($map . '/*.json') ?: [] as $bestand) {
        if ((int) @filemtime($bestand) > $nu - 3600) {
            $recent++;
        }
    }
    if ($recent >= MAX_PER_UUR) {
        log_fout('ontbinden', 'Meer dan ' . MAX_PER_UUR . ' ontbindingen in een uur: deze is niet aangenomen');
        json_antwoord(429, [
            'ok'      => false,
            'melding' => 'Er komen nu te veel aanvragen tegelijk binnen. Mail je ontbinding naar ' . $config['mail_cath'] . ', dan telt die net zo goed.',
        ]);
    }

    $bestelling = $ref !== null ? lees_bestelling($config, $ref) : null;
    $ontbinding = [
        'versie'       => 1,
        'ontvangen'    => date('c', $nu),
        'naam'         => $naam,
        'bestelnummer' => $ref ?? $bestelnummer,
        'email'        => $email,
        'ref'          => $ref,
        'gevonden'     => $bestelling !== null,
        'test'         => testmodus($config) || !empty($bestelling['betaling']['test']),
        'gemaild'      => ['klant' => null, 'cath' => null],
    ];
    $pad = $map . '/' . ($ref ?? 'onbekend') . '-' . date('Ymd-His', $nu) . '-' . bin2hex(random_bytes(3)) . '.json';
    schrijf_json($pad, $ontbinding);
} catch (Throwable $e) {
    log_fout('ontbinden', 'Ontbinding niet bewaard: ' . fout_omschrijving($e));
    json_antwoord(500, $nietGelukt);
}

// ---------- Mailen: eerst de bevestiging aan de klant, dan Cath ----------

foreach (['klant', 'cath'] as $wie) {
    try {
        mail_verstuur($config, $wie === 'klant'
            ? mail_ontbinding_klant($config, $ontbinding)
            : mail_ontbinding_cath($config, $ontbinding, $bestelling));
        $ontbinding['gemaild'][$wie] = date('c');
    } catch (Throwable $e) {
        log_fout('ontbinden', 'Mail aan ' . $wie . ' over ontbinding ' . basename($pad) . ' mislukt: ' . fout_omschrijving($e));
    }
}
try {
    schrijf_json($pad, $ontbinding);
} catch (Throwable $e) {
    // De ontbinding zelf staat al bewaard; alleen de vinkjes voor de mails ontbreken
    log_fout('ontbinden', 'Mailstand van ' . basename($pad) . ' niet bewaard: ' . fout_omschrijving($e));
}

json_antwoord(200, [
    'ok'        => true,
    'ontvangen' => datum_nl($ontbinding['ontvangen']),
    'gemaild'   => $ontbinding['gemaild']['klant'] !== null,
]);
