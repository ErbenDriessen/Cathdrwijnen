<?php
// cathdrwijnen · basis voor de betaalserver
// Instellingen laden, foutafhandeling, JSON-antwoorden, het logboek en veilig
// schrijven naar de data-map. Wordt door elk bestand in api/ als eerste geladen.
// Dit bestand doet zelf niets als je het rechtstreeks opvraagt.

declare(strict_types=1);

// Nooit PHP-meldingen naar de bezoeker: fouten gaan naar data/log.txt
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Amsterdam');
if (!headers_sent()) {
    header_remove('X-Powered-By');
}

/** Fout bij het betalen (Mollie onbereikbaar, onverwacht antwoord, ...) */
class BetaalFout extends RuntimeException
{
}

/** Fout bij het versturen van een mail */
class MailFout extends RuntimeException
{
}

// Waarschuwingen worden uitzonderingen, zodat niets stilletjes misgaat.
// Met @ onderdrukte meldingen blijven onderdrukt. Een melding dat iets in een
// nieuwere PHP-versie gaat verdwijnen (deprecated) komt alleen in het logboek:
// een update van PHP op de hosting mag de winkel niet stilleggen.
set_error_handler(function (int $nummer, string $tekst, string $bestand, int $regel): bool {
    if (!(error_reporting() & $nummer)) {
        return false;
    }
    if ($nummer === E_DEPRECATED || $nummer === E_USER_DEPRECATED) {
        log_fout('php', 'Verouderd: ' . $tekst . ' (' . basename($bestand) . ':' . $regel . ')');
        return true;
    }
    throw new ErrorException($tekst, 0, $nummer, $bestand, $regel);
});

// Vangnet voor fouten die nergens anders zijn opgevangen
set_exception_handler(function (Throwable $e): void {
    log_fout('onverwacht', fout_omschrijving($e));
    if (!headers_sent()) {
        json_antwoord(500, ['ok' => false, 'melding' => 'Er ging iets mis. Probeer het zo nog eens.']);
    }
});

register_shutdown_function(function (): void {
    $fout = error_get_last();
    if ($fout !== null && in_array($fout['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_fout('fataal', $fout['message'] . ' in ' . basename($fout['file']) . ':' . $fout['line']);
    }
});

// ---------- Instellingen ----------

// De gegevens van de onderneming in config.php ('bedrijf'). Alle verplicht voor live.
const BEDRIJF_VELDEN = ['naam', 'adres', 'telefoon', 'kvk', 'btw_id', 'terugsturen'];

/**
 * De instellingen uit api/config.php, aangevuld en gecontroleerd.
 * Gooit een uitzondering als er iets ontbreekt of niet klopt.
 */
function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $pad = dirname(__DIR__) . '/config.php';
    if (!is_file($pad)) {
        throw new RuntimeException('api/config.php ontbreekt: kopieer config.voorbeeld.php naar config.php en vul hem in');
    }
    // Een BOM of spatie buiten <?php in config.php mag het JSON-antwoord niet verpesten
    ob_start();
    try {
        $ingelezen = require $pad;
    } finally {
        ob_end_clean();
    }
    if (!is_array($ingelezen)) {
        throw new RuntimeException('api/config.php geeft geen lijst met instellingen terug');
    }

    $c = array_replace([
        'mollie_api_key' => '',
        'site_url'       => '',
        'winkel_naam'    => 'cathdrwijnen',
        'mail_van'       => '',
        'mail_cath'      => '',
        'mail_modus'     => 'bestand',
        'smtp'           => [],
        'bedrijf'        => [],
        'data_map'       => dirname(__DIR__, 2) . '/data',
    ], $ingelezen);
    $c['smtp'] = array_replace([
        'host'        => '',
        'poort'       => 465,
        'beveiliging' => 'ssl',
        'gebruiker'   => '',
        'wachtwoord'  => '',
    ], is_array($c['smtp']) ? $c['smtp'] : []);

    // Gegevens van de onderneming (voor de mails); ontbreekt er een, dan is het ''
    $bedrijf = is_array($c['bedrijf']) ? $c['bedrijf'] : [];
    $c['bedrijf'] = [];
    foreach (BEDRIJF_VELDEN as $sleutel) {
        $c['bedrijf'][$sleutel] = schoon_regel(is_scalar($bedrijf[$sleutel] ?? null) ? (string) $bedrijf[$sleutel] : '');
    }

    $c['mollie_api_key'] = trim((string) $c['mollie_api_key']);
    if ($c['mollie_api_key'] !== '' && !preg_match('/^(test|live)_[A-Za-z0-9]{20,}\z/', $c['mollie_api_key'])) {
        throw new RuntimeException('mollie_api_key in config.php heeft een onbekende vorm (verwacht test_... of live_...)');
    }

    $c['site_url'] = rtrim(trim((string) $c['site_url']), '/');
    if (!filter_var($c['site_url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $c['site_url'])) {
        throw new RuntimeException('site_url in config.php is geen geldig adres (bijvoorbeeld https://www.cathdrwijnen.nl)');
    }

    $c['winkel_naam'] = schoon_regel((string) $c['winkel_naam']);
    foreach (['mail_van', 'mail_cath'] as $sleutel) {
        $c[$sleutel] = trim((string) $c[$sleutel]);
        if (!filter_var($c[$sleutel], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($sleutel . ' in config.php is geen geldig e-mailadres');
        }
    }

    if (!in_array($c['mail_modus'], ['bestand', 'mail', 'smtp'], true)) {
        throw new RuntimeException("mail_modus in config.php moet 'bestand', 'mail' of 'smtp' zijn");
    }

    $c['data_map'] = rtrim(str_replace('\\', '/', (string) $c['data_map']), '/');
    if ($c['data_map'] === '') {
        throw new RuntimeException('data_map in config.php is leeg');
    }

    $config = $c;
    return $config;
}

/** Testmodus: geen Mollie-sleutel ingevuld, dus nep-betalingen en mails als bestand */
function testmodus(array $config): bool
{
    return $config['mollie_api_key'] === '';
}

// ---------- Antwoorden ----------

/** Stuurt een JSON-antwoord en stopt. */
function json_antwoord(int $code, array $data): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Alleen de genoemde HTTP-methodes toestaan, anders 405. */
function alleen_methode(string ...$methodes): void
{
    $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($methode, $methodes, true)) {
        header('Allow: ' . implode(', ', $methodes));
        json_antwoord(405, ['ok' => false, 'melding' => 'Deze aanvraag is hier niet toegestaan.']);
    }
}

/**
 * Leest de JSON-body van het verzoek (hoogstens $max bytes) als array.
 * Bij te groot of geen geldige JSON volgt meteen een foutantwoord.
 */
function lees_json_verzoek(int $max): array
{
    $teGroot = ['ok' => false, 'melding' => 'Je bestelling is te groot om te verwerken.'];
    $onleesbaar = ['ok' => false, 'melding' => 'Je bestelling kon niet worden gelezen. Probeer het nog eens.'];

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) {
        json_antwoord(413, $teGroot);
    }
    $ruw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($ruw === false) {
        json_antwoord(400, $onleesbaar);
    }
    if (strlen($ruw) > $max) {
        json_antwoord(413, $teGroot);
    }

    try {
        $data = json_decode($ruw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_antwoord(400, $onleesbaar);
    }
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        json_antwoord(400, $onleesbaar);
    }
    return $data;
}

// ---------- Tekst ----------

/** Aantal tekens (niet bytes) in een UTF-8-tekst */
function tekens(string $tekst): int
{
    return function_exists('mb_strlen') ? mb_strlen($tekst, 'UTF-8') : (int) preg_match_all('/./su', $tekst);
}

/** Eén regel: stuurtekens en regeleinden worden een spatie, witruimte aan de randen weg */
function schoon_regel(string $tekst): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $tekst));
}

/** Korte omschrijving van een uitzondering voor het logboek */
function fout_omschrijving(Throwable $e): string
{
    return get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}

// ---------- Logboek ----------

/**
 * Schrijft een technische foutmelding naar <data_map>/log.txt.
 * E-mailadressen en Mollie-sleutels worden er voor de zekerheid uit gehaald.
 */
function log_fout(string $bron, string $melding): void
{
    $melding = (string) preg_replace('/[^\s<>()"\',;:]+@[^\s<>()"\',;:]+/', '[e-mailadres]', $melding);
    $melding = (string) preg_replace('/\b(test|live)_[A-Za-z0-9]{10,}/', '[sleutel]', $melding);
    $regel = date('Y-m-d H:i:s') . ' [' . $bron . '] ' . schoon_regel($melding) . "\n";

    try {
        $map = data_map(config());
        $pad = $map . '/log.txt';
        // Houd het logboek klein: boven 1 MB gaat het oude deel opzij
        if (is_file($pad) && filesize($pad) > 1000000) {
            @rename($pad, $map . '/log.1.txt');
        }
        if (@file_put_contents($pad, $regel, FILE_APPEND | LOCK_EX) === false) {
            error_log('cathdrwijnen: ' . $regel);
        }
    } catch (Throwable $e) {
        error_log('cathdrwijnen: ' . $regel);
    }
}

// ---------- Bestanden ----------

// Zelfde inhoud als data/.htaccess: niets in de data-map is via de website op te vragen
const DATA_HTACCESS = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

/**
 * Pad naar (een submap van) de data-map; maakt de map aan als die er nog niet is.
 * $sub is altijd een vaste naam uit de code, nooit invoer van buiten.
 */
function data_map(array $config, string $sub = ''): string
{
    $basis = $config['data_map'];
    if (!is_dir($basis)) {
        maak_map($basis);
        // Nieuwe data-map binnen de webroot: meteen afschermen
        @file_put_contents($basis . '/.htaccess', DATA_HTACCESS);
        @file_put_contents($basis . '/index.html', '');
    }
    if ($sub === '') {
        return $basis;
    }
    $map = $basis . '/' . $sub;
    maak_map($map);
    return $map;
}

function maak_map(string $map): void
{
    if (!is_dir($map) && !@mkdir($map, 0750, true) && !is_dir($map)) {
        throw new RuntimeException('Map kan niet worden aangemaakt: ' . basename($map));
    }
}

/**
 * Schrijft een bestand in één keer: eerst naar een tijdelijk bestand ernaast,
 * dan hernoemen. Een lezer ziet dus altijd het oude of het nieuwe bestand, nooit half.
 */
function schrijf_atomair(string $pad, string $inhoud): void
{
    $tijdelijk = dirname($pad) . '/.' . basename($pad) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tijdelijk, $inhoud, LOCK_EX) !== strlen($inhoud)) {
        @unlink($tijdelijk);
        throw new RuntimeException('Schrijven mislukt: ' . basename($pad));
    }
    // Op Windows kan hernoemen even mislukken als een ander proces het bestand open heeft
    for ($poging = 1; $poging <= 5; $poging++) {
        if (@rename($tijdelijk, $pad)) {
            return;
        }
        usleep(50000);
    }
    @unlink($tijdelijk);
    throw new RuntimeException('Hernoemen mislukt: ' . basename($pad));
}

function schrijf_json(string $pad, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    schrijf_atomair($pad, $json . "\n");
}

/** Leest een JSON-bestand; null als het er niet is of niet leesbaar is. */
function lees_json(string $pad): ?array
{
    if (!is_file($pad)) {
        return null;
    }
    $ruw = @file_get_contents($pad);
    if ($ruw === false || $ruw === '') {
        return null;
    }
    $data = json_decode($ruw, true);
    return is_array($data) ? $data : null;
}

/**
 * Voert $werk uit terwijl geen ander verzoek tegelijk betalingen verwerkt.
 * Zo worden er bij twee gelijktijdige webhook-aanroepen geen dubbele mails verstuurd.
 * Let op: niet nesten.
 */
function met_slot(array $config, callable $werk): mixed
{
    $pad = data_map($config, 'bestellingen') . '/.slot';
    $handvat = fopen($pad, 'c');
    if ($handvat === false || !flock($handvat, LOCK_EX)) {
        throw new RuntimeException('Slot op de bestellingen lukt niet');
    }
    try {
        return $werk();
    } finally {
        flock($handvat, LOCK_UN);
        fclose($handvat);
    }
}
