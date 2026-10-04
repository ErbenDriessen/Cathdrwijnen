# Webshop: installeren op de hosting

De map `api/` is het enige stukje server van de site. Geen database, geen Composer,
geen extra libraries: alleen PHP-bestanden die bestellingen als JSON-bestand
bewaren, de betaling bij Mollie starten en de bevestigingsmails sturen.

## Wat er nodig is

- PHP 8.2 of nieuwer, met de extensies **curl** en **openssl** (staan bij vrijwel elke hosting aan).
  `mbstring` is fijn maar niet verplicht.
- Apache of LiteSpeed die `.htaccess` leest (voor het afschermen van `data/` en `api/config.php`).
- Een Mollie-account (zie onderaan).

## Installeren

1. Zet de hele site op de hosting, inclusief `api/`, `data/` en `pakketten.json`.
2. Kopieer `api/config.voorbeeld.php` naar `api/config.php` en vul hem in:

   | instelling | wat |
   |---|---|
   | `mollie_api_key` | `test_...` om te testen, `live_...` voor echte betalingen. Leeg = testmodus (nep-betaalpagina). |
   | `site_url` | adres van de site zonder slash aan het eind, bijvoorbeeld `https://www.cathdrwijnen.nl` |
   | `winkel_naam` | naam in de mails en op het Mollie-afschrift: `cathdrwijnen` |
   | `mail_van` | afzender van de mails; neem een adres op het eigen domein, anders belanden ze in de spam |
   | `mail_cath` | waar de bestellingen heen gaan |
   | `mail_modus` | `smtp` (aanrader), `mail` (PHP `mail()` van de hosting) of `bestand` (niets versturen, alleen `.eml`-bestanden) |
   | `smtp` | gegevens van de mailbox: `host`, `poort`, `beveiliging` (`ssl` bij poort 465, `starttls` bij poort 587), `gebruiker`, `wachtwoord` |
   | `bedrijf` | gegevens van Cath die de wet in elke bevestigingsmail wil zien: `naam`, `adres` (vestigingsadres, geen postbus), `telefoon`, `kvk`, `btw_id`, en `terugsturen` (één zin: hoe het pakket na ontbinden teruggaat en wie dat betaalt). Zolang er één leeg is, staat er `[... volgt]` in de mails en weigert de winkel een `live_`-sleutel. |
   | `data_map` | map voor bestellingen, ontbindingen, mails en het logboek |

3. **Data-map buiten de webroot** als de hosting dat toelaat, bijvoorbeeld
   `'data_map' => __DIR__ . '/../../data-cathdrwijnen'`. Kan dat niet, laat hem dan
   op `data/` staan: daar zorgt `data/.htaccess` dat niets op te vragen is.
   Controleer dat: `https://<jouw-site>/data/index.html` moet een foutpagina
   (403 of 404) geven. PHP moet in de data-map mogen schrijven.
4. `api/config.php` staat in `.gitignore` en hoort nooit in git. `api/.htaccess`
   schermt hem ook af via de website.

## Testen met een Mollie-testsleutel

1. Zet in `config.php` de testsleutel (`test_...`, uit het Mollie-dashboard) en
   de echte `site_url`.
2. Bestel iets op de site. Je komt op de testpagina van Mollie: kies daar
   *Betaald*, *Mislukt*, *Geannuleerd* of *Verlopen*.
3. Bij *Betaald* krijgen Cath en de klant een mail. Bij testbetalingen staat
   `[test]` voor het onderwerp en een regel bovenaan dat er niet echt is betaald.
4. Klopt alles, vervang dan de sleutel door de live-sleutel (`live_...`).

Mollie meldt elke statuswijziging aan `api/webhook.php`; dat adres geeft de
server zelf mee bij elke betaling, in het dashboard hoeft daarvoor niets te
worden ingesteld. Komt de webhook om wat voor reden niet aan, dan haalt de
bedankpagina de status zelf op en gaan de mails alsnog de deur uit.
Lokaal (localhost) kan Mollie de webhook niet bereiken; daar gebeurt dat dus
altijd via de bedankpagina.

## Lokaal testen zonder Mollie (testmodus)

```
php -S localhost:8767 -t C:\dev\Cathdrwijnen
```

Met een lege `mollie_api_key` gaat afrekenen naar `api/nep-betaalpagina.php`
(gelabeld TESTMODUS) met de knoppen *Betaling gelukt*, *Betaling mislukt* en
*Annuleren*. De mails worden in testmodus nooit verstuurd, wat er ook bij
`mail_modus` staat: ze komen als `.eml`-bestand in `data/mails/` (te openen met
een mailprogramma). Zodra er een sleutel is ingevuld, bestaat de nep-betaalpagina niet meer.

## Wat Cath in het Mollie-dashboard moet doen

1. Een account aanmaken op mollie.com en de verificatie afronden (KvK-gegevens,
   identiteit, zakelijke bankrekening).
2. Een websiteprofiel aanmaken met het domein van de site en vertellen dat er
   wijn wordt verkocht. Mollie kan om extra uitleg vragen, bijvoorbeeld over de
   leeftijdscontrole bij het bestellen en bezorgen.
3. Betaalmethoden aanzetten: in elk geval iDEAL | Wero, eventueel creditcard.
   De site kiest geen methode: de klant kiest op de betaalpagina van Mollie.
4. Bij *Ontwikkelaars → API-sleutels* de test- en live-sleutel van dat
   websiteprofiel opzoeken en aan Erben geven (of zelf in `config.php` zetten).

## Hoe een bestelling loopt

1. `afrekenen.html` stuurt de bestelling naar `api/bestelling.php` (POST, JSON).
   De server controleert alles, rekent de prijzen zelf uit `pakketten.json`, bewaart
   `data/bestellingen/CDW-JJJJMMDD-XXXXXX.json` en start de betaling.
2. De klant betaalt bij Mollie en komt terug op `bedankt.html?ref=...`, dat
   `api/status.php?ref=...` opvraagt.
3. Mollie roept `api/webhook.php` aan. Bij *betaald* gaan er twee mails uit
   (aan Cath en aan de klant), elk precies één keer. In de mail aan de klant
   staat alles wat de wet na het bestellen op papier of per mail wil zien:
   de bedenktijd, waar je ontbindt, het modelformulier, de wettelijke garantie,
   de levertermijn uit `pakketten.json` en de gegevens uit `bedrijf`.

## Ontbinden (verplicht sinds 19 juni 2026, art. 6:230oa BW)

Onderaan elke pagina staat de link *Hier je bestelling ontbinden* naar
`ontbinden.html`. De klant vult naam, bestelnummer en e-mail in, drukt op
*Verder* en daarna op *Ontbinding bevestigen*. `api/ontbinden.php` bewaart dat in
`data/ontbindingen/` en mailt meteen een ontvangstbevestiging aan de klant (met wat
er is ingevuld en de datum en tijd) en een melding aan Cath. Een onbekend
bestelnummer wordt nooit geweigerd: Cath zoekt het uit. Boven 30 ontbindingen per
uur neemt de server er even geen meer aan (tegen misbruik van het formulier om mail
te versturen); de klant krijgt dan het advies om Cath te mailen.

Deze pagina moet de hele bedenktijd bereikbaar blijven. Haal hem dus nooit weg en
verander de knopteksten niet zonder na te gaan of ze nog ondubbelzinnig zijn.

## Voor je live gaat

Met een `live_`-sleutel weigert `api/bestelling.php` elke bestelling (en schrijft
het waarom in `data/log.txt`) zolang er een lege `bedrijf`-gegeven in `config.php`
staat, `voorwaarden.html` of `privacy.html` nog de tijdelijke tekst heeft, of er in
`pakketten.json`, `salon-v2.html`, `index.html`, `afrekenen.html`, `bedankt.html`
of `ontbinden.html` nog iets als `[gebied volgt]` staat (de lijst staat in
`api/lib/winkel.php`, `LIVE_CONTROLE_BESTANDEN`). Loop daarnaast deze lijst na:

- [ ] `voorwaarden.html` en `privacy.html` hebben de definitieve tekst, nagekeken door
      iemand die er verstand van heeft (haal de blokken met `class="tijdelijk"` en
      `class="volgt"` weg). Plak de definitieve voorwaarden ook in de bevestigingsmail
      of stuur ze als pdf mee (art. 6:234 BW); nu staat er alleen een link.
- [ ] Bedrijfsgegevens (naam, vestigingsadres, telefoon, e-mail, KvK, btw-id) staan in
      `bedrijf` in `config.php` én in de voettekst van alle pagina's (zoek op `volgt]`).
- [ ] Het bezorggebied, de ophaalplaats en de levertermijn staan in `pakketten.json` en
      bovenaan `afrekenen.html`.
- [ ] De officiële Europese kennisgeving over de wettelijke garantie (afbeelding met
      QR-code, Uitvoeringsverordening (EU) 2025/1960) staat bij de pakketten in
      `salon-v2.html`, in het overzicht op `afrekenen.html` en op `voorwaarden.html#garantie`
      (zoek op *kennisgeving*).
- [ ] De geborgde werkwijze voor alcoholverkoop op afstand (Alcoholwet art. 20a) is
      ingevuld en ondertekend, bijvoorbeeld met het voorbeelddocument van KHN of CBL:
      Cath bezorgt zelf en/of de klant haalt op bij [adres]. Bewaar hem buiten de
      openbare repository; de NVWA kan erom vragen.
- [ ] Het officiële NIX18-logo staat in de voettekst.
- [ ] `https://<jouw-site>/data/index.html` en `https://<jouw-site>/api/config.php`
      geven een foutpagina (403). De ingebouwde PHP-server leest `.htaccess` niet,
      dus dit kun je alleen op de hosting controleren.
- [ ] Eén keer bestellen met een `test_`-sleutel op de hosting: mails komen aan en
      `ontbinden.html` stuurt een ontvangstbevestiging.

## Onderhoud

- **Prijzen of pakketten wijzigen**: alleen in `pakketten.json` (prijzen in centen, inclusief btw).
- **Logboek**: technische fouten staan in `data/log.txt` (zonder persoonsgegevens
  en zonder sleutels). Boven 1 MB gaat het oude deel naar `log.1.txt`.
- **Bestellingen opruimen**: de bestanden in `data/bestellingen/` en
  `data/ontbindingen/` bevatten naam, adres en e-mail. Verwijder ze na de
  bewaartermijn uit de privacyverklaring.
- **Ontbindingen**: elke ontbinding staat in `data/ontbindingen/`. Mislukte een mail
  daarover, dan staat dat in `data/log.txt` en is `gemaild` in het bestand leeg.
- **"Het betalen kon niet worden gestart"** of **geen mails**: kijk in `data/log.txt`.
  Een mislukte mail probeert de server opnieuw zodra Mollie de webhook nog eens aanroept.
