<?php
// cathdrwijnen · mails
// De bevestigingsmails bij een bestelling en bij een ontbinding (platte tekst,
// UTF-8, Nederlands) en drie manieren om
// ze te versturen: als .eml-bestand in de data-map, met PHP mail() of via een
// eigen kleine SMTP-verbinding (ssl of starttls, AUTH LOGIN).
// Alles wat in een kopregel komt gaat door schoon_regel(): geen regeleinden,
// dus geen kans om er extra kopregels (zoals Bcc) in te smokkelen.

declare(strict_types=1);

// ---------- De twee mails bij een bestelling ----------

/** Stuurt de mail aan Cath ('cath') of aan de klant ('klant'). Gooit bij een fout. */
function verstuur_bestelmail(array $config, array $bestelling, string $wie): void
{
    $bericht = $wie === 'cath' ? mail_aan_cath($config, $bestelling) : mail_aan_klant($config, $bestelling);
    mail_verstuur($config, $bericht);
}

function mail_aan_cath(array $config, array $b): array
{
    $klant = $b['klant'];
    $t = [];
    if (!empty($b['betaling']['test'])) {
        $t[] = 'Dit is een testbestelling: er is niet echt betaald.';
        $t[] = '';
    }
    $t[] = 'Er is een nieuwe bestelling betaald.';
    $t[] = '';
    $t[] = 'Bestelnummer: ' . $b['ref'];
    $t[] = 'Besteld op: ' . datum_nl((string) $b['aangemaakt']);
    $t[] = 'Mollie-betaling: ' . ($b['betaling']['id'] ?? '');
    $t[] = '';
    $t[] = 'Pakketten';
    array_push($t, ...overzicht_regels($b));
    $t[] = '';
    if ($b['levering'] === 'bezorgen') {
        $t[] = 'Levering: bezorgen op dit adres';
        array_push($t, ...adres_regels($klant));
    } else {
        $t[] = 'Levering: de klant haalt het pakket op.';
        $t[] = 'Stuur het adres en spreek een moment af.';
    }
    $t[] = '';
    $t[] = 'Klant';
    $t[] = 'Naam: ' . $klant['naam'];
    $t[] = 'E-mail: ' . $klant['email'];
    $t[] = 'Telefoon: ' . ($klant['telefoon'] !== '' ? $klant['telefoon'] : 'niet ingevuld');
    $t[] = '';
    $t[] = 'Opmerking';
    $t[] = $b['opmerking'] !== '' ? $b['opmerking'] : '(geen opmerking)';
    $t[] = '';
    // De regels uit de geborgde werkwijze (Alcoholwet), bij elke bestelling opnieuw
    $t[] = 'De klant heeft bij het bestellen zelf aangevinkt 18 jaar of ouder te zijn en akkoord te gaan met de algemene voorwaarden.';
    if ($b['levering'] === 'bezorgen') {
        $t[] = 'Lever alleen af op het adres hierboven, niet bij de buren, en alleen aan iemand die 18 jaar of ouder is.'
            . ' Vraag om een ID, tenzij het onmiskenbaar is. Geen geldig ID? Neem het pakket weer mee.';
    } else {
        $t[] = 'Geef het pakket alleen mee aan iemand die 18 jaar of ouder is.'
            . ' Vraag om een ID, tenzij het onmiskenbaar is. Geen geldig ID? Geef het pakket dan niet mee.';
    }
    $t[] = '';
    $t[] = 'Met beantwoorden mail je de klant rechtstreeks.';

    return [
        'ref'       => $b['ref'],
        'wie'       => 'cath',
        'van'       => ['adres' => $config['mail_van'], 'naam' => $config['winkel_naam']],
        'aan'       => ['adres' => $config['mail_cath'], 'naam' => $config['winkel_naam']],
        'antwoord'  => ['adres' => $klant['email'], 'naam' => $klant['naam']],
        'onderwerp' => test_voorvoegsel($b) . 'Nieuwe bestelling ' . $b['ref'] . ' (betaald)',
        'tekst'     => implode("\n", $t) . "\n",
    ];
}

function mail_aan_klant(array $config, array $b): array
{
    $klant = $b['klant'];
    $t = [];
    if (!empty($b['betaling']['test'])) {
        $t[] = 'Dit is een testbestelling: er is niet echt betaald.';
        $t[] = '';
    }
    $t[] = 'Beste ' . $klant['naam'] . ',';
    $t[] = '';
    $t[] = 'Bedankt voor je bestelling bij ' . $config['winkel_naam'] . '. Je betaling is goed ontvangen.';
    $t[] = '';
    $t[] = 'Je bestelnummer is ' . $b['ref'] . '.';
    $t[] = 'Besteld op: ' . datum_nl((string) $b['aangemaakt']);
    $t[] = 'Betaald via Mollie';
    $t[] = '';
    $t[] = 'Je bestelling';
    array_push($t, ...overzicht_regels($b));
    $t[] = '';
    $t[] = 'Wat gebeurt er nu?';
    $t[] = catalogus()['levertermijn'];
    if ($b['levering'] === 'bezorgen') {
        $t[] = 'Ze spreekt een moment met je af en bezorgt je pakket op dit adres:';
        array_push($t, ...adres_regels($klant));
    } else {
        $t[] = 'Ze stuurt je het adres en spreekt een moment met je af om je pakket op te halen.';
    }
    $t[] = '';
    $t[] = 'Bij bezorgen of ophalen vragen we om je ID.';
    $t[] = '';

    // Wat de wet (art. 6:230m en 6:230v lid 7 BW) ook op papier of per mail wil zien
    $t[] = 'Bedenktijd van 14 dagen';
    $t[] = 'Je mag je bestelling zonder reden ontbinden tot 14 dagen na de dag waarop je het pakket hebt gekregen.'
        . ' Dat doe je via ' . $config['site_url'] . '/ontbinden.html?ref=' . rawurlencode($b['ref'])
        . ' (de knop "Hier je bestelling ontbinden" onderaan elke pagina van de site),'
        . ' door deze mail te beantwoorden of met het modelformulier onderaan deze mail.'
        . ' Daarna heb je nog 14 dagen om het pakket terug te geven. ' . terugsturen_zin($config)
        . ' Maak liever geen flessen open: voor een geopende fles kan Cath de waardevermindering in rekening brengen.'
        . ($b['verzendkosten'] > 0
            ? ' Je krijgt het hele bedrag, ook de bezorgkosten, binnen 14 dagen terug.'
            : ' Je krijgt het hele bedrag binnen 14 dagen terug.');
    $t[] = '';
    $t[] = 'Is er iets mis met je pakket?';
    $t[] = 'Je hebt altijd recht op een pakket dat in orde is (wettelijke garantie).'
        . ' Is een fles kapot of klopt er iets niet? Mail of bel Cath, dan lost ze het met je op.';
    $t[] = '';
    $t[] = 'Algemene voorwaarden: ' . $config['site_url'] . '/voorwaarden.html';
    $t[] = '';
    $t[] = 'Heb je een vraag? Mail naar ' . $config['mail_cath'] . ' of beantwoord deze mail.';
    $t[] = '';
    array_push($t, ...groet_regels($config));
    $t[] = '';
    array_push($t, ...modelformulier_regels($config));

    return [
        'ref'       => $b['ref'],
        'wie'       => 'klant',
        'van'       => ['adres' => $config['mail_van'], 'naam' => $config['winkel_naam']],
        'aan'       => ['adres' => $klant['email'], 'naam' => $klant['naam']],
        'antwoord'  => ['adres' => $config['mail_cath'], 'naam' => $config['winkel_naam']],
        'onderwerp' => test_voorvoegsel($b) . 'Bedankt voor je bestelling bij ' . $config['winkel_naam'],
        'tekst'     => implode("\n", $t) . "\n",
    ];
}

// ---------- Ontbinden (art. 6:230oa BW) ----------

/**
 * Ontvangstbevestiging aan de klant: wat er is ingevuld en wanneer het binnenkwam.
 * $o is de bewaarde ontbinding (zie api/ontbinden.php).
 */
function mail_ontbinding_klant(array $config, array $o): array
{
    $t = [];
    if (!empty($o['test'])) {
        $t[] = 'Dit is een test: er is geen echte bestelling ontbonden.';
        $t[] = '';
    }
    $t[] = 'Beste ' . $o['naam'] . ',';
    $t[] = '';
    $t[] = 'We hebben je ontbinding ontvangen. Met deze mail bevestigen we dat.';
    $t[] = '';
    $t[] = 'Ontvangen op ' . datum_nl((string) $o['ontvangen']);
    $t[] = '';
    $t[] = 'Wat je hebt ingevuld';
    $t[] = 'Naam: ' . $o['naam'];
    $t[] = 'Bestelnummer: ' . $o['bestelnummer'];
    $t[] = 'E-mail: ' . $o['email'];
    $t[] = '';
    $t[] = 'Wat gebeurt er nu?';
    $t[] = 'Cath neemt contact met je op over het teruggeven van het pakket. ' . terugsturen_zin($config)
        . ' Je krijgt het hele bedrag dat je hebt betaald binnen 14 dagen terug.';
    $t[] = '';
    $t[] = 'Klopt er iets niet? Beantwoord deze mail of mail naar ' . $config['mail_cath'] . '.';
    $t[] = '';
    array_push($t, ...groet_regels($config));

    return [
        'ref'       => $o['ref'],
        'wie'       => 'ontbinding-klant',
        'van'       => ['adres' => $config['mail_van'], 'naam' => $config['winkel_naam']],
        'aan'       => ['adres' => $o['email'], 'naam' => $o['naam']],
        'antwoord'  => ['adres' => $config['mail_cath'], 'naam' => $config['winkel_naam']],
        'onderwerp' => (!empty($o['test']) ? '[test] ' : '') . 'Ontvangstbevestiging: je ontbinding van bestelling ' . $o['bestelnummer'],
        'tekst'     => implode("\n", $t) . "\n",
    ];
}

/** Melding aan Cath, met de bestelling erbij als die te vinden is. */
function mail_ontbinding_cath(array $config, array $o, ?array $bestelling): array
{
    $t = [];
    if (!empty($o['test'])) {
        $t[] = 'Dit is een test: er is geen echte bestelling ontbonden.';
        $t[] = '';
    }
    $t[] = 'Er is een bestelling ontbonden via de site. De klant heeft een ontvangstbevestiging gekregen.';
    $t[] = '';
    $t[] = 'Ontvangen op: ' . datum_nl((string) $o['ontvangen']);
    $t[] = 'Naam: ' . $o['naam'];
    $t[] = 'E-mail: ' . $o['email'];
    $t[] = 'Bestelnummer zoals ingevuld: ' . $o['bestelnummer'];
    $t[] = '';
    if ($bestelling !== null) {
        $t[] = 'De bestelling';
        $t[] = 'Besteld op: ' . datum_nl((string) $bestelling['aangemaakt']);
        $t[] = 'Stand van de betaling: ' . $bestelling['status'];
        array_push($t, ...overzicht_regels($bestelling));
        if (strcasecmp((string) $bestelling['klant']['email'], $o['email']) !== 0) {
            $t[] = '';
            $t[] = 'Let op: bij de bestelling hoort een ander e-mailadres: ' . $bestelling['klant']['email'];
        }
    } else {
        $t[] = 'Bij dit bestelnummer is geen bestelling gevonden. Zoek de bestelling op naam of e-mailadres op.';
    }
    $t[] = '';
    $t[] = 'Wat nu? Spreek met de klant af hoe het pakket terugkomt, en betaal het bedrag'
        . ' (ook de bezorgkosten) binnen 14 dagen terug via het Mollie-dashboard.';
    $t[] = '';
    $t[] = 'Met beantwoorden mail je de klant rechtstreeks.';

    return [
        'ref'       => $o['ref'],
        'wie'       => 'ontbinding-cath',
        'van'       => ['adres' => $config['mail_van'], 'naam' => $config['winkel_naam']],
        'aan'       => ['adres' => $config['mail_cath'], 'naam' => $config['winkel_naam']],
        'antwoord'  => ['adres' => $o['email'], 'naam' => $o['naam']],
        'onderwerp' => (!empty($o['test']) ? '[test] ' : '') . 'Ontbinding ontvangen ' . $o['bestelnummer'],
        'tekst'     => implode("\n", $t) . "\n",
    ];
}

// ---------- Stukjes die in meer mails staan ----------

/** "[test] " voor betalingen met een Mollie-testsleutel of in testmodus */
function test_voorvoegsel(array $bestelling): string
{
    return !empty($bestelling['betaling']['test']) ? '[test] ' : '';
}

/**
 * Een gegeven van de onderneming uit config.php. Ontbreekt het nog, dan staat er
 * een duidelijke [... volgt] (dat kan alleen zonder live-sleutel, zie bestelling.php).
 */
function bedrijf_gegeven(array $config, string $veld, string $omschrijving): string
{
    $waarde = $config['bedrijf'][$veld] ?? '';
    return $waarde !== '' ? $waarde : '[' . $omschrijving . ' volgt]';
}

/** Hoe het pakket na ontbinden teruggaat en wie dat betaalt (uit config.php) */
function terugsturen_zin(array $config): string
{
    $zin = $config['bedrijf']['terugsturen'] ?? '';
    return $zin !== '' ? $zin : '[Volgt: hoe je het pakket teruggeeft en wie de kosten daarvan betaalt.]';
}

/** Groet met daaronder wie we zijn: naam, adres, telefoon, e-mail, KvK en btw-id */
function groet_regels(array $config): array
{
    return [
        'Hartelijke groet,',
        'Cath',
        $config['winkel_naam'],
        '',
        bedrijf_gegeven($config, 'naam', 'naam') . ', ' . bedrijf_gegeven($config, 'adres', 'adres'),
        'Telefoon ' . bedrijf_gegeven($config, 'telefoon', 'telefoonnummer') . ' · ' . $config['mail_cath'],
        'KvK ' . bedrijf_gegeven($config, 'kvk', 'KvK-nummer') . ' · btw-id ' . bedrijf_gegeven($config, 'btw_id', 'btw-id'),
    ];
}

/** Het modelformulier voor herroeping (bijlage I, deel B, van richtlijn 2011/83/EU) */
function modelformulier_regels(array $config): array
{
    return [
        'Modelformulier voor herroeping',
        '(dit formulier alleen invullen en terugzenden als u de overeenkomst wilt herroepen)',
        '- Aan: ' . bedrijf_gegeven($config, 'naam', 'naam') . ', ' . bedrijf_gegeven($config, 'adres', 'adres') . ', ' . $config['mail_cath'],
        '- Ik/Wij (*) deel/delen (*) u hierbij mede dat ik/wij (*) onze overeenkomst betreffende de verkoop van de volgende goederen herroep/herroepen (*):',
        '- Besteld op (*) / Ontvangen op (*):',
        '- Naam/Namen consument(en):',
        '- Adres consument(en):',
        '- Handtekening van consument(en) (alleen wanneer dit formulier op papier wordt ingediend):',
        '- Datum:',
        '(*) Doorhalen wat niet van toepassing is.',
    ];
}

/** De pakketregels met subtotaal, verzendkosten en totaal */
function overzicht_regels(array $b): array
{
    $regels = [];
    foreach ($b['regels'] as $r) {
        $regel = '- ' . $r['aantal'] . ' x ' . $r['naam'];
        if (!empty($r['flessen'])) {
            $regel .= ' (' . $r['flessen'] . ' flessen)';
        }
        if ($r['aantal'] > 1) {
            $regel .= ', ' . euro($r['prijs']) . ' per stuk';
        }
        $regels[] = $regel . ': ' . euro($r['subtotaal']);
    }
    $regels[] = '';
    $regels[] = 'Subtotaal: ' . euro($b['subtotaal']);
    if ($b['levering'] === 'ophalen') {
        $regels[] = 'Verzendkosten: geen (ophalen)';
    } else {
        $regels[] = 'Verzendkosten: ' . ($b['verzendkosten'] > 0 ? euro($b['verzendkosten']) : 'gratis');
    }
    $regels[] = 'Totaal: ' . euro($b['totaal']) . ' (inclusief btw)';
    return $regels;
}

function adres_regels(array $klant): array
{
    return [
        '  ' . $klant['straat'] . ' ' . $klant['huisnummer'],
        '  ' . $klant['postcode'] . ' ' . $klant['plaats'],
    ];
}

/** "4 oktober 2026 om 15:02" */
function datum_nl(string $iso): string
{
    $maanden = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli',
        'augustus', 'september', 'oktober', 'november', 'december'];
    $tijd = strtotime($iso) ?: time();
    return date('j', $tijd) . ' ' . $maanden[(int) date('n', $tijd) - 1] . ' ' . date('Y', $tijd) . ' om ' . date('H:i', $tijd);
}

// ---------- Opbouw van het bericht ----------

/**
 * Kopregels van het bericht. $metAanEnOnderwerp is false voor PHP mail(),
 * dat To en Subject zelf toevoegt.
 */
function mail_kopregels(array $config, array $bericht, string $codering, bool $metAanEnOnderwerp): array
{
    $domein = substr((string) strrchr($config['mail_van'], '@'), 1);
    $kop = [
        'Date: ' . date('r'),
        'From: ' . adres_kop($bericht['van']),
    ];
    if ($metAanEnOnderwerp) {
        $kop[] = 'To: ' . adres_kop($bericht['aan']);
        $kop[] = 'Subject: ' . kop_tekst($bericht['onderwerp']);
    }
    if (!empty($bericht['antwoord'])) {
        $kop[] = 'Reply-To: ' . adres_kop($bericht['antwoord']);
    }
    $kop[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domein . '>';
    $kop[] = 'MIME-Version: 1.0';
    $kop[] = 'Content-Type: text/plain; charset=UTF-8';
    $kop[] = 'Content-Transfer-Encoding: ' . $codering;
    return $kop;
}

/** De tekst met CRLF-regeleinden, korte regels, in '8bit' of 'quoted-printable' */
function mail_inhoud(string $tekst, string $codering): string
{
    $tekst = vouw(str_replace(["\r\n", "\r"], "\n", $tekst));
    $tekst = str_replace("\n", "\r\n", $tekst);
    return $codering === 'quoted-printable' ? quoted_printable_encode($tekst) : $tekst;
}

/** Het complete bericht zoals het in een .eml-bestand of over SMTP gaat */
function mail_bericht(array $config, array $bericht, string $codering): string
{
    return implode("\r\n", mail_kopregels($config, $bericht, $codering, true)) . "\r\n\r\n"
        . mail_inhoud($bericht['tekst'], $codering);
}

/** "Naam" <adres> of =?UTF-8?B?...?= <adres> */
function adres_kop(array $adres): string
{
    $email = schoon_regel((string) $adres['adres']);
    $naam = schoon_regel((string) ($adres['naam'] ?? ''));
    if ($naam === '') {
        return $email;
    }
    if (preg_match('/^[\x20-\x7E]*\z/', $naam)) {
        return '"' . addcslashes($naam, '"\\') . '" <' . $email . '>';
    }
    // Het adres op een eigen vervolgregel, zodat elke regel kort blijft
    return kop_tekst($naam) . "\r\n <" . $email . '>';
}

/**
 * Tekst voor een kopregel. Gewone ASCII blijft zoals hij is; anders
 * RFC 2047-codering (=?UTF-8?B?...?=) in stukjes van 39 bytes (64 tekens
 * gecodeerd, dus ook met "Reply-To: " ervoor binnen de 76 tekens per regel),
 * zonder ooit midden in een letter te knippen.
 */
function kop_tekst(string $tekst): string
{
    $tekst = schoon_regel($tekst);
    if (preg_match('/^[\x20-\x7E]*\z/', $tekst)) {
        return $tekst;
    }
    preg_match_all('/./su', $tekst, $letters);
    $stukken = [];
    $stuk = '';
    foreach ($letters[0] as $letter) {
        if ($stuk !== '' && strlen($stuk) + strlen($letter) > 39) {
            $stukken[] = $stuk;
            $stuk = '';
        }
        $stuk .= $letter;
    }
    if ($stuk !== '') {
        $stukken[] = $stuk;
    }
    $woorden = array_map(fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=', $stukken);
    return implode("\r\n ", $woorden);
}

/** Breekt lange regels af op hoogstens $breedte tekens (liefst bij een spatie). */
function vouw(string $tekst, int $breedte = 76): string
{
    $uit = [];
    foreach (explode("\n", $tekst) as $regel) {
        while (tekens($regel) > $breedte) {
            if (preg_match('/^(.{1,' . $breedte . '})\s+(.*)\z/su', $regel, $m)) {
                $uit[] = rtrim($m[1]);
                $regel = $m[2];
            } elseif (preg_match('/^(.{' . $breedte . '})(.*)\z/su', $regel, $m)) {
                $uit[] = $m[1];
                $regel = $m[2];
            } else {
                break;
            }
        }
        $uit[] = $regel;
    }
    return implode("\n", $uit);
}

// ---------- Versturen ----------

function mail_verstuur(array $config, array $bericht): void
{
    // In testmodus nooit echt versturen, wat er ook bij mail_modus staat:
    // zo komt een nep-betaalde bestelling nooit in iemands postvak.
    $modus = testmodus($config) ? 'bestand' : $config['mail_modus'];
    match ($modus) {
        'bestand' => mail_naar_bestand($config, $bericht),
        'mail'    => mail_met_php($config, $bericht),
        'smtp'    => mail_met_smtp($config, $bericht),
    };
}

/**
 * Testmodus: het bericht als .eml-bestand in <data_map>/mails (te openen met een mailprogramma).
 * Een ontbinding met een onbekend bestelnummer heeft geen ref: dan 'onbekend' in de naam.
 */
function mail_naar_bestand(array $config, array $bericht): void
{
    $ref = $bericht['ref'] ?? null;
    $wie = ['cath', 'klant', 'ontbinding-cath', 'ontbinding-klant'];
    if (($ref !== null && !geldige_ref($ref)) || !in_array($bericht['wie'], $wie, true)) {
        throw new MailFout('Onverwachte bestandsnaam voor de mail');
    }
    $pad = data_map($config, 'mails') . '/' . date('Ymd-His') . '-' . ($ref ?? 'onbekend') . '-' . $bericht['wie'] . '.eml';
    schrijf_atomair($pad, mail_bericht($config, $bericht, '8bit'));
}

/** Via PHP mail() (de mailserver van de hosting) */
function mail_met_php(array $config, array $bericht): void
{
    if (!function_exists('mail')) {
        throw new MailFout('mail() staat uit op deze server');
    }
    $kop = implode("\r\n", mail_kopregels($config, $bericht, '8bit', false));
    // -f zet de afzender van de envelop gelijk aan mail_van (beter tegen spamfilters)
    $gelukt = @mail(
        adres_kop($bericht['aan']),
        kop_tekst($bericht['onderwerp']),
        mail_inhoud($bericht['tekst'], '8bit'),
        $kop,
        '-f' . $config['mail_van']
    );
    if (!$gelukt) {
        throw new MailFout('mail() kon het bericht niet afleveren');
    }
}

/**
 * Via SMTP met een eigen kleine client: ssl:// (meestal poort 465) of
 * starttls (meestal poort 587), inloggen met AUTH LOGIN.
 * 'geen' (onversleuteld) kan alleen zonder inloggen, bijvoorbeeld om lokaal te testen.
 */
function mail_met_smtp(array $config, array $bericht): void
{
    $smtp = $config['smtp'];
    $host = schoon_regel((string) $smtp['host']);
    $poort = (int) $smtp['poort'];
    $beveiliging = strtolower((string) $smtp['beveiliging']);
    if ($beveiliging === 'tls') {
        $beveiliging = 'starttls';
    }
    if ($host === '' || $poort < 1 || $poort > 65535) {
        throw new MailFout('SMTP: host of poort ontbreekt in config.php');
    }
    if (!in_array($beveiliging, ['ssl', 'starttls', 'geen'], true)) {
        throw new MailFout("SMTP: beveiliging moet 'ssl' of 'starttls' zijn");
    }
    $gebruiker = (string) $smtp['gebruiker'];
    if ($gebruiker !== '' && $beveiliging === 'geen') {
        throw new MailFout('SMTP: inloggen zonder versleuteling gebeurt niet; kies ssl of starttls');
    }

    $context = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => $host,
        'SNI_enabled'      => true,
    ]]);
    $foutNummer = 0;
    $foutTekst = '';
    $verbinding = @stream_socket_client(
        ($beveiliging === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $poort,
        $foutNummer,
        $foutTekst,
        10,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($verbinding === false) {
        throw new MailFout('SMTP: verbinden met ' . $host . ':' . $poort . ' mislukt (' . $foutNummer . ' ' . schoon_regel($foutTekst) . ')');
    }
    stream_set_timeout($verbinding, 15);

    try {
        smtp_lees($verbinding, [220], 'begroeting');
        $helo = (string) (parse_url($config['site_url'], PHP_URL_HOST) ?: 'localhost');
        $kan = smtp_opdracht($verbinding, 'EHLO ' . $helo, [250], 'EHLO');

        if ($beveiliging === 'starttls') {
            if (!preg_match('/^250[ -]STARTTLS\b/mi', $kan)) {
                throw new MailFout('SMTP: de server biedt geen STARTTLS aan');
            }
            smtp_opdracht($verbinding, 'STARTTLS', [220], 'STARTTLS');
            $methode = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $methode |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (@stream_socket_enable_crypto($verbinding, true, $methode) !== true) {
                throw new MailFout('SMTP: versleutelen met STARTTLS mislukt');
            }
            $kan = smtp_opdracht($verbinding, 'EHLO ' . $helo, [250], 'EHLO na STARTTLS');
        }

        if ($gebruiker !== '') {
            smtp_opdracht($verbinding, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            smtp_opdracht($verbinding, base64_encode($gebruiker), [334], 'AUTH gebruikersnaam');
            smtp_opdracht($verbinding, base64_encode((string) $smtp['wachtwoord']), [235], 'AUTH wachtwoord');
        }

        // Zonder 8BITMIME gaat de tekst als quoted-printable (alleen ASCII over de lijn)
        $achtBits = preg_match('/^250[ -]8BITMIME\b/mi', $kan) === 1;
        $codering = $achtBits ? '8bit' : 'quoted-printable';
        $van = schoon_regel($bericht['van']['adres']);
        $aan = schoon_regel($bericht['aan']['adres']);
        smtp_opdracht($verbinding, 'MAIL FROM:<' . $van . '>' . ($achtBits ? ' BODY=8BITMIME' : ''), [250], 'MAIL FROM');
        smtp_opdracht($verbinding, 'RCPT TO:<' . $aan . '>', [250, 251], 'RCPT TO');
        smtp_opdracht($verbinding, 'DATA', [354], 'DATA');

        // Een regel die met een punt begint krijgt een extra punt, anders ziet de server hem als het einde
        $data = (string) preg_replace('/^\./m', '..', mail_bericht($config, $bericht, $codering));
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }
        smtp_schrijf($verbinding, $data . ".\r\n", 'bericht');
        smtp_lees($verbinding, [250], 'einde bericht');

        try {
            smtp_opdracht($verbinding, 'QUIT', [221], 'QUIT');
        } catch (Throwable $e) {
            // De mail is al aangenomen; een slordig afscheid maakt niet uit
        }
    } finally {
        fclose($verbinding);
    }
}

/**
 * Stuurt één SMTP-opdracht en controleert het antwoord.
 * $stap is wat er in een foutmelding komt; nooit de opdracht zelf
 * (daar kunnen inloggegevens in staan).
 */
function smtp_opdracht($verbinding, string $opdracht, array $verwacht, string $stap): string
{
    smtp_schrijf($verbinding, $opdracht . "\r\n", $stap);
    return smtp_lees($verbinding, $verwacht, $stap);
}

function smtp_schrijf($verbinding, string $data, string $stap): void
{
    $lengte = strlen($data);
    $geschreven = 0;
    while ($geschreven < $lengte) {
        $n = @fwrite($verbinding, substr($data, $geschreven, 8192));
        if ($n === false || $n === 0) {
            throw new MailFout('SMTP: schrijven mislukt bij ' . $stap);
        }
        $geschreven += $n;
    }
}

/** Leest een (eventueel meerregelig) SMTP-antwoord en controleert de code. */
function smtp_lees($verbinding, array $verwacht, string $stap): string
{
    $antwoord = '';
    for ($i = 0; $i < 100; $i++) {
        $regel = @fgets($verbinding, 2048);
        if ($regel === false) {
            $meta = stream_get_meta_data($verbinding);
            throw new MailFout('SMTP: ' . ($meta['timed_out'] ? 'geen antwoord (time-out)' : 'verbinding verbroken') . ' bij ' . $stap);
        }
        $antwoord .= $regel;
        // "250-..." betekent: er komt nog een regel; "250 ..." is de laatste
        if (strlen($regel) < 4 || $regel[3] !== '-') {
            break;
        }
    }
    $code = (int) substr($antwoord, 0, 3);
    if (!in_array($code, $verwacht, true)) {
        throw new MailFout('SMTP: ' . $stap . ' gaf "' . substr(schoon_regel($antwoord), 0, 200) . '"');
    }
    return $antwoord;
}
