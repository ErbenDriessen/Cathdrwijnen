<?php
// cathdrwijnen · instellingen van de webshop (voorbeeld)
// Kopieer dit bestand naar config.php (in dezelfde map) en vul het daar in.
// config.php staat in .gitignore: de Mollie-sleutel en het mailwachtwoord
// komen dus nooit in git. Uitleg per instelling: api/LEESMIJ.md.

// Bewust zonder declare(strict_types=1): dan gaat het ook goed als een
// teksteditor dit bestand met een BOM opslaat.

return [
    // Mollie-sleutel: test_... om te testen, live_... voor echte betalingen.
    // Leeg = testmodus: een nep-betaalpagina en mails als bestand in data/mails.
    'mollie_api_key' => '',

    // Adres van de site, zonder slash aan het eind (bijvoorbeeld https://www.cathdrwijnen.nl)
    'site_url'       => 'http://localhost:8767',

    'winkel_naam'    => 'cathdrwijnen',

    // Afzender van de mails; liefst een adres op het domein van de site
    'mail_van'       => 'info@cathdrwijnen.nl',

    // Waar bestellingen heen gaan
    'mail_cath'      => 'info@cathdrwijnen.nl',

    // Gegevens van de onderneming. De wet wil ze in elke bevestigingsmail zien
    // (en ze staan ook in de voettekst van de site). Niet zelf verzinnen: Cath
    // levert ze aan. Zolang er één leeg is, weigert de winkel een live_-sleutel.
    'bedrijf'        => [
        'naam'        => '',   // handelsnaam en naam, bijvoorbeeld 'cathdrwijnen, Cathelijne Achternaam'
        'adres'       => '',   // vestigingsadres, geen postbus: 'Straat 1, 1234 AB Plaats'
        'telefoon'    => '',
        'kvk'         => '',   // KvK-nummer (8 cijfers)
        'btw_id'      => '',   // btw-identificatienummer, NL...B..
        // Hoe het pakket na ontbinden teruggaat en wie de kosten betaalt. Bijvoorbeeld
        // 'Cath haalt het pakket bij je op.' of 'Je stuurt het pakket op eigen kosten terug naar het adres hieronder.'
        'terugsturen' => '',
    ],

    // 'bestand' (data/mails/*.eml, niets wordt verstuurd), 'mail' (PHP mail()) of 'smtp'.
    // In testmodus worden mails altijd als bestand bewaard.
    'mail_modus'     => 'bestand',

    // Alleen voor mail_modus 'smtp'. beveiliging: 'ssl' (meestal poort 465) of 'starttls' (meestal poort 587)
    'smtp'           => [
        'host'        => '',
        'poort'       => 465,
        'beveiliging' => 'ssl',
        'gebruiker'   => '',
        'wachtwoord'  => '',
    ],

    // Map voor bestellingen, mails en het logboek. Op de hosting liefst buiten
    // de webroot, bijvoorbeeld __DIR__ . '/../../data-cathdrwijnen'.
    'data_map'       => __DIR__ . '/../data',
];
