// cathdrwijnen · bedankt
// Na het betalen stuurt Mollie (of de nep-betaalpagina in testmodus) de klant
// hierheen met ?ref=CDW-…. We vragen bij api/status.php hoe de betaling ervoor
// staat: elke 2 seconden, hooguit een halve minuut. Bij "betaald" gaat het
// mandje leeg; bij "niet gelukt" blijft het staan, zodat de klant het opnieuw
// kan proberen. De status-API geeft alleen de stand terug, geen persoonsgegevens.

(function () {
    'use strict';

    var API = 'api/status.php?ref=';
    var ELKE = 2000;      // ms tussen twee keer vragen
    var HOOGUIT = 30000;  // ms; daarna stoppen we met vragen
    var CONCEPT_KEY = 'cathdrwijnen-afrekenen'; // ingevulde gegevens, zie js/afrekenen.js

    var BETAALD = ['paid', 'authorized'];
    var REDEN = {
        failed: 'Je bank of kaartmaatschappij heeft de betaling niet goedgekeurd.',
        canceled: 'Je hebt het betalen afgebroken.',
        expired: 'Het betalen duurde te lang, daardoor is de betaling verlopen.'
    };
    // Paginatitel en voorleestekst per state
    var TITEL = {
        laden: 'Je bestelling',
        betaald: 'Betaald',
        wachten: 'Wachten op je bank',
        mislukt: 'Betaling niet gelukt',
        storing: 'Betaling nakijken lukt niet',
        onbekend: 'Geen bestelling gevonden'
    };

    var ref = '';
    var start = 0;
    var timer = null;
    var huidige = 'laden';
    var statusRegel;

    function leesRef() {
        var waarde = '';
        try {
            waarde = new URLSearchParams(window.location.search).get('ref') || '';
        } catch (e) {}
        waarde = waarde.trim();
        return /^[A-Za-z0-9-]{4,64}$/.test(waarde) ? waarde : '';
    }

    function alle(selector, fn) {
        document.querySelectorAll(selector).forEach(fn);
    }

    function toon(staat) {
        var focusWeg = false;
        alle('[data-staat]', function (blok) {
            var zichtbaar = blok.getAttribute('data-staat') === staat;
            if (!zichtbaar && blok.contains(document.activeElement)) focusWeg = true;
            blok.hidden = !zichtbaar;
        });
        var nieuw = staat !== huidige;
        huidige = staat;
        document.title = TITEL[staat] + ' · cathdrwijnen';

        var blok = document.querySelector('[data-staat="' + staat + '"]');
        // Knop "Opnieuw kijken" verdwijnt: zet de focus op de nieuwe kop
        if (focusWeg) blok.querySelector('h1').focus();
        if (nieuw && staat !== 'laden') {
            var zin = blok.querySelector('h1').textContent;
            statusRegel.textContent = '';
            window.setTimeout(function () { statusRegel.textContent = zin; }, 60);
        }
    }

    function zetWachtenLang(lang) {
        alle('[data-wachten-kort]', function (n) { n.hidden = lang; });
        alle('[data-wachten-lang]', function (n) { n.hidden = !lang; });
    }

    function leegMandje() {
        if (window.Winkel) window.Winkel.leeg();
        try { window.sessionStorage.removeItem(CONCEPT_KEY); } catch (e) {}
    }

    // Opnieuw vragen, of stoppen als de halve minuut om is
    function straks() {
        if (Date.now() - start + ELKE > HOOGUIT) {
            if (huidige === 'wachten') {
                zetWachtenLang(true);
                // Ook hardop: het wachten is voorbij en er staan nu knoppen
                var zin = document.querySelector('[data-staat="wachten"] p[data-wachten-lang]').textContent;
                statusRegel.textContent = '';
                window.setTimeout(function () { statusRegel.textContent = zin; }, 60);
            } else {
                toon('storing');
            }
            return;
        }
        timer = window.setTimeout(vraag, ELKE);
    }

    function verwerk(status) {
        if (BETAALD.indexOf(status) !== -1) {
            leegMandje();
            toon('betaald');
            return;
        }
        if (REDEN[status]) {
            document.querySelector('[data-reden]').textContent = REDEN[status];
            toon('mislukt');
            return;
        }
        // open, pending (of een stand die we nog niet kennen): blijven vragen
        toon('wachten');
        straks();
    }

    function vraag() {
        timer = null;
        fetch(API + encodeURIComponent(ref), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (res) {
            // 404 (of 400): deze bestelling bestaat niet
            if (res.status === 404 || res.status === 400) return { ok: false };
            if (!res.ok) throw new Error('status ' + res.status);
            return res.json();
        }).then(function (data) {
            if (!data || data.ok !== true) {
                toon('onbekend');
                return;
            }
            if (typeof data.status !== 'string') throw new Error('geen status');
            verwerk(data.status);
        }).catch(function () {
            // Geen verbinding of een serverfout: gewoon nog eens proberen
            straks();
        });
    }

    function begin() {
        if (timer) window.clearTimeout(timer);
        start = Date.now();
        if (huidige === 'wachten') {
            var actief = document.activeElement;
            zetWachtenLang(false);
            // De knop "Opnieuw kijken" is nu weg: focus naar de kop
            if (actief && actief.closest && actief.closest('[data-wachten-lang]')) {
                document.querySelector('[data-staat="wachten"] h1').focus();
            }
        } else {
            toon('laden');
        }
        vraag();
    }

    function init() {
        statusRegel = document.querySelector('[data-status]');
        // Het wachtrondje alleen met JavaScript: zonder draait er niets
        alle('[data-js-laden]', function (n) { n.hidden = false; });
        ref = leesRef();
        if (!ref) {
            toon('onbekend');
            return;
        }
        alle('[data-ref]', function (n) { n.textContent = ref; });
        alle('[data-ref-regel]', function (n) { n.hidden = false; });
        // Ontbinden met het bestelnummer al ingevuld
        alle('[data-ontbinden-link]', function (a) { a.href = 'ontbinden.html?ref=' + encodeURIComponent(ref); });
        // De levertermijn uit pakketten.json (anders blijft de tekst uit de HTML staan)
        if (window.Winkel && window.Winkel.ready) {
            window.Winkel.ready.then(function () {
                var termijn = window.Winkel.levertermijn();
                if (termijn) alle('[data-levertermijn]', function (n) { n.textContent = termijn; });
            }, function () {});
        }
        alle('[data-opnieuw-kijken]', function (knop) { knop.addEventListener('click', begin); });
        begin();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
