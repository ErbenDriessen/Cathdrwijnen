<?php
// cathdrwijnen · bestellingen
// Pakketten en prijzen uit pakketten.json, de controle van een binnenkomende
// bestelling, de bedragen en het bewaren van bestellingen als JSON-bestand.

declare(strict_types=1);

// Bestelreferentie: CDW-JJJJMMDD-XXXXXX, zonder tekens die op elkaar lijken (0/O, 1/I/L)
const REF_TEKENS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const REF_PATROON = '/^CDW-[0-9]{8}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}\z/';

// Maximale lengtes van de velden (in tekens)
const MAX_LENGTE = [
    'naam'       => 100,
    'email'      => 254,
    'telefoon'   => 30,
    'straat'     => 100,
    'huisnummer' => 12,
    'plaats'     => 100,
    'opmerking'  => 1000,
];

// ---------- Pakketten ----------

/**
 * pakketten.json uit de hoofdmap van de site: de enige bron voor prijzen.
 * Gooit een uitzondering als het bestand ontbreekt of niet klopt.
 */
function catalogus(): array
{
    static $catalogus = null;
    if ($catalogus !== null) {
        return $catalogus;
    }

    $data = lees_json(dirname(__DIR__, 2) . '/pakketten.json');
    if ($data === null || !isset($data['pakketten']) || !is_array($data['pakketten'])) {
        throw new RuntimeException('pakketten.json ontbreekt of is geen geldige JSON');
    }

    $pakketten = [];
    foreach ($data['pakketten'] as $p) {
        if (!is_array($p) || !is_string($p['id'] ?? null) || !is_string($p['naam'] ?? null)
            || !is_int($p['prijs'] ?? null) || $p['prijs'] <= 0) {
            throw new RuntimeException('pakketten.json: een pakket mist id, naam of een geldige prijs');
        }
        $pakketten[$p['id']] = [
            'id'      => $p['id'],
            'naam'    => $p['naam'],
            'flessen' => is_int($p['flessen'] ?? null) ? $p['flessen'] : 0,
            'prijs'   => $p['prijs'],
        ];
    }

    $levering = [];
    foreach (['bezorgen', 'ophalen'] as $methode) {
        $l = $data['levering'][$methode] ?? null;
        if (!is_array($l) || !is_int($l['kosten'] ?? null) || $l['kosten'] < 0) {
            throw new RuntimeException('pakketten.json: levering ' . $methode . ' mist geldige kosten');
        }
        $levering[$methode] = [
            'naam'        => is_string($l['naam'] ?? null) ? $l['naam'] : ucfirst($methode),
            'kosten'      => $l['kosten'],
            'gratisVanaf' => is_int($l['gratisVanaf'] ?? null) ? $l['gratisVanaf'] : null,
        ];
    }

    $max = $data['maxPerPakket'] ?? 10;
    $termijn = is_string($data['levertermijn'] ?? null) ? schoon_regel($data['levertermijn']) : '';
    $catalogus = [
        'pakketten'    => $pakketten,
        'levering'     => $levering,
        'maxPerPakket' => is_int($max) && $max > 0 ? $max : 10,
        // Zonder afgesproken termijn geldt de wettelijke: uiterlijk 30 dagen
        'levertermijn' => $termijn !== '' ? $termijn : 'Je hebt je pakket uiterlijk 30 dagen na je bestelling.',
    ];
    return $catalogus;
}

// ---------- Klaar voor live? ----------

// Bestanden waarin tijdelijke tekst als "[gebied volgt]" kan staan (index.html: voor als
// salon-v2.html de homepagina wordt). Een bestand dat er niet is, wordt overgeslagen.
const LIVE_CONTROLE_BESTANDEN = ['pakketten.json', 'salon-v2.html', 'index.html', 'afrekenen.html', 'bedankt.html', 'ontbinden.html'];

/**
 * Wat er nog ontbreekt voordat de winkel echt mag verkopen: lege bedrijfsgegevens
 * in config.php, voorwaarden en privacyverklaring met nog de tijdelijke tekst
 * (class="tijdelijk" of class="volgt"), of een gegeven dat nog "[... volgt]" is.
 * Lege lijst = klaar.
 */
function nog_niet_klaar_voor_live(array $config): array
{
    $root = dirname(__DIR__, 2);
    $missend = [];
    foreach (BEDRIJF_VELDEN as $veld) {
        if ($config['bedrijf'][$veld] === '') {
            $missend[] = "bedrijf['" . $veld . "'] in config.php is leeg";
        }
    }
    foreach (['voorwaarden.html', 'privacy.html'] as $pagina) {
        $html = @file_get_contents($root . '/' . $pagina);
        if (!is_string($html) || $html === '') {
            $missend[] = $pagina . ' ontbreekt';
        } elseif (str_contains($html, 'class="tijdelijk"') || str_contains($html, 'class="volgt"') || str_contains($html, 'volgt]')) {
            $missend[] = $pagina . ' heeft nog de tijdelijke tekst';
        }
    }
    foreach (LIVE_CONTROLE_BESTANDEN as $bestand) {
        $tekst = @file_get_contents($root . '/' . $bestand);
        if (is_string($tekst) && str_contains($tekst, 'volgt]')) {
            $missend[] = $bestand . ' heeft nog een gegeven dat "[... volgt]" is';
        }
    }
    return $missend;
}

/**
 * Regels, subtotaal, verzendkosten en totaal (alles in centen), alleen uit
 * pakketten.json. $items is al gecontroleerd: [id => aantal].
 */
function bereken_bedragen(array $items, string $levering, array $catalogus): array
{
    $regels = [];
    $subtotaal = 0;
    foreach ($items as $id => $aantal) {
        $p = $catalogus['pakketten'][$id];
        $regels[] = [
            'id'        => $p['id'],
            'naam'      => $p['naam'],
            'flessen'   => $p['flessen'],
            'prijs'     => $p['prijs'],
            'aantal'    => $aantal,
            'subtotaal' => $p['prijs'] * $aantal,
        ];
        $subtotaal += $p['prijs'] * $aantal;
    }

    $l = $catalogus['levering'][$levering];
    $verzendkosten = $l['kosten'];
    if ($l['gratisVanaf'] !== null && $subtotaal >= $l['gratisVanaf']) {
        $verzendkosten = 0;
    }

    return [
        'regels'        => $regels,
        'subtotaal'     => $subtotaal,
        'verzendkosten' => $verzendkosten,
        'totaal'        => $subtotaal + $verzendkosten,
    ];
}

/** "€ 1.234,50" */
function euro(int $centen): string
{
    return '€ ' . number_format(intdiv($centen, 100), 0, ',', '.') . ',' . sprintf('%02d', $centen % 100);
}

/** Bedrag zoals Mollie het wil: "1234.50" */
function mollie_bedrag(int $centen): string
{
    return sprintf('%d.%02d', intdiv($centen, 100), $centen % 100);
}

// ---------- Controle van een bestelling ----------

/**
 * Controleert de bestelling uit het JSON-verzoek.
 * Geeft [schone gegevens, fouten] terug; fouten is [veldnaam => Nederlandse zin].
 */
function controleer_bestelling(array $invoer, array $catalogus): array
{
    $fouten = [];

    // Pakketten en aantallen
    $items = [];
    $ruweItems = $invoer['items'] ?? null;
    if (!is_array($ruweItems) || $ruweItems === []) {
        $fouten['items'] = 'Je mandje is leeg. Kies eerst een pakket.';
    } else {
        $max = $catalogus['maxPerPakket'];
        foreach ($ruweItems as $id => $aantal) {
            $id = (string) $id;
            if (!isset($catalogus['pakketten'][$id])) {
                $fouten['items'] = 'Een van de pakketten in je mandje bestaat niet meer. Haal het weg en probeer het opnieuw.';
                break;
            }
            if (!is_int($aantal) || $aantal < 1 || $aantal > $max) {
                $fouten['items'] = 'Je kunt van elk pakket 1 tot ' . $max . ' stuks bestellen.';
                break;
            }
            $items[$id] = $aantal;
        }
    }

    // Bezorgen of ophalen
    $levering = $invoer['levering'] ?? null;
    if (!is_string($levering) || !isset($catalogus['levering'][$levering])) {
        $fouten['levering'] = 'Kies of je je pakket wilt laten bezorgen of zelf wilt ophalen.';
        $levering = null;
    }

    // Klantgegevens
    $ruweKlant = $invoer['klant'] ?? null;
    if (!is_array($ruweKlant)) {
        $ruweKlant = [];
    }
    $klant = [];

    $naam = tekstveld($ruweKlant['naam'] ?? null);
    if ($naam === null || $naam === '') {
        $fouten['klant.naam'] = 'Vul je naam in.';
    } elseif (tekens($naam) > MAX_LENGTE['naam']) {
        $fouten['klant.naam'] = 'Je naam is te lang (hoogstens ' . MAX_LENGTE['naam'] . ' tekens).';
    }
    $klant['naam'] = $naam;

    $email = tekstveld($ruweKlant['email'] ?? null);
    if ($email === null || $email === '') {
        $fouten['klant.email'] = 'Vul je e-mailadres in.';
    } elseif (strlen($email) > MAX_LENGTE['email'] || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fouten['klant.email'] = 'Vul een geldig e-mailadres in, bijvoorbeeld naam@voorbeeld.nl.';
    }
    $klant['email'] = $email;

    $telefoon = tekstveld($ruweKlant['telefoon'] ?? null);
    if ($telefoon === null) {
        $fouten['klant.telefoon'] = 'Vul een geldig telefoonnummer in, of laat dit veld leeg.';
    } elseif ($telefoon !== '' && (tekens($telefoon) > MAX_LENGTE['telefoon']
        || !preg_match('/^\+?[0-9 ()\-.]+\z/', $telefoon)
        || preg_match_all('/[0-9]/', $telefoon) < 8)) {
        $fouten['klant.telefoon'] = 'Vul een geldig telefoonnummer in, of laat dit veld leeg.';
    }
    $klant['telefoon'] = $telefoon;

    // Adres alleen bij bezorgen (bij ophalen bewaren we het niet)
    if ($levering === 'bezorgen') {
        $postcode = tekstveld($ruweKlant['postcode'] ?? null);
        if ($postcode === null || $postcode === '') {
            $fouten['klant.postcode'] = 'Vul je postcode in.';
        } elseif (!preg_match('/^([1-9][0-9]{3}) ?([A-Za-z]{2})\z/', $postcode, $delen)) {
            $fouten['klant.postcode'] = 'Vul een geldige postcode in, bijvoorbeeld 1234 AB.';
        } else {
            $postcode = $delen[1] . ' ' . strtoupper($delen[2]);
        }
        $klant['postcode'] = $postcode;

        $huisnummer = tekstveld($ruweKlant['huisnummer'] ?? null);
        if ($huisnummer === null || $huisnummer === '') {
            $fouten['klant.huisnummer'] = 'Vul je huisnummer in.';
        } elseif (tekens($huisnummer) > MAX_LENGTE['huisnummer']
            || !preg_match('/^[0-9][\p{L}0-9 \-\/.]*\z/u', $huisnummer)) {
            $fouten['klant.huisnummer'] = 'Vul een geldig huisnummer in, bijvoorbeeld 12 of 12A.';
        }
        $klant['huisnummer'] = $huisnummer;

        $straat = tekstveld($ruweKlant['straat'] ?? null);
        if ($straat === null || $straat === '') {
            $fouten['klant.straat'] = 'Vul je straat in.';
        } elseif (tekens($straat) > MAX_LENGTE['straat']) {
            $fouten['klant.straat'] = 'De straatnaam is te lang (hoogstens ' . MAX_LENGTE['straat'] . ' tekens).';
        }
        $klant['straat'] = $straat;

        $plaats = tekstveld($ruweKlant['plaats'] ?? null);
        if ($plaats === null || $plaats === '') {
            $fouten['klant.plaats'] = 'Vul je woonplaats in.';
        } elseif (tekens($plaats) > MAX_LENGTE['plaats']) {
            $fouten['klant.plaats'] = 'De plaatsnaam is te lang (hoogstens ' . MAX_LENGTE['plaats'] . ' tekens).';
        }
        $klant['plaats'] = $plaats;
    }

    // Opmerking (optioneel, mag meerdere regels hebben)
    $opmerking = tekstveld($invoer['opmerking'] ?? null, true);
    if ($opmerking === null) {
        $fouten['opmerking'] = 'Je opmerking kon niet worden gelezen.';
    } elseif (tekens($opmerking) > MAX_LENGTE['opmerking']) {
        $fouten['opmerking'] = 'Je opmerking is te lang (hoogstens ' . MAX_LENGTE['opmerking'] . ' tekens).';
    }

    // Leeftijd en voorwaarden: de klant moet zelf het vinkje hebben gezet
    if (($invoer['achttienPlus'] ?? null) !== true) {
        $fouten['achttienPlus'] = 'Bevestig dat je 18 jaar of ouder bent.';
    }
    if (($invoer['voorwaarden'] ?? null) !== true) {
        $fouten['voorwaarden'] = 'Ga akkoord met de algemene voorwaarden om te kunnen bestellen.';
    }

    return [
        [
            'items'     => $items,
            'levering'  => $levering,
            'klant'     => $klant,
            'opmerking' => $opmerking ?? '',
        ],
        $fouten,
    ];
}

/**
 * Een tekstveld uit de invoer, schoongemaakt. Leeg of ontbrekend wordt '',
 * iets anders dan tekst (getal, lijst, ...) wordt null.
 */
function tekstveld(mixed $waarde, bool $meerdereRegels = false): ?string
{
    if ($waarde === null) {
        return '';
    }
    if (!is_string($waarde)) {
        return null;
    }
    if ($meerdereRegels) {
        $waarde = str_replace(["\r\n", "\r"], "\n", $waarde);
        $waarde = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $waarde);
        return trim($waarde);
    }
    return (string) preg_replace('/\s+/u', ' ', schoon_regel($waarde));
}

// ---------- Bewaren ----------

function geldige_ref(mixed $ref): bool
{
    return is_string($ref) && preg_match(REF_PATROON, $ref) === 1;
}

/** Pad naar het bestelbestand; alleen voor een gecontroleerde referentie. */
function bestelling_pad(array $config, string $ref): string
{
    if (!geldige_ref($ref)) {
        throw new InvalidArgumentException('Ongeldige bestelreferentie');
    }
    return data_map($config, 'bestellingen') . '/' . $ref . '.json';
}

/** Maakt een nieuwe, nog ongebruikte referentie en reserveert het bestand ervoor. */
function nieuwe_ref(array $config): string
{
    for ($poging = 0; $poging < 10; $poging++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= REF_TEKENS[random_int(0, strlen(REF_TEKENS) - 1)];
        }
        $ref = 'CDW-' . date('Ymd') . '-' . $code;
        // 'x' maakt het bestand alleen aan als het nog niet bestaat
        $handvat = @fopen(bestelling_pad($config, $ref), 'x');
        if ($handvat !== false) {
            fclose($handvat);
            return $ref;
        }
    }
    throw new RuntimeException('Geen vrije bestelreferentie gevonden');
}

function lees_bestelling(array $config, string $ref): ?array
{
    if (!geldige_ref($ref)) {
        return null;
    }
    $bestelling = lees_json(bestelling_pad($config, $ref));
    if ($bestelling === null || ($bestelling['ref'] ?? null) !== $ref) {
        return null;
    }
    return $bestelling;
}

function bewaar_bestelling(array $config, array $bestelling): void
{
    $bestelling['bijgewerkt'] = date('c');
    schrijf_json(bestelling_pad($config, $bestelling['ref']), $bestelling);
}
