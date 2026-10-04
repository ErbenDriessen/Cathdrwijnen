// cathdrwijnen · winkelmandje
// Gedeelde basis voor de salonpagina en de afrekenpagina. Leest de pakketten en
// prijzen uit pakketten.json en bewaart het mandje in de browser.
// Prijzen zijn altijd in centen; de betaalserver rekent het totaal zelf opnieuw uit.
//
//   Winkel.ready                 Promise, klaar zodra pakketten.json geladen is
//   Winkel.pakket(id)            { id, naam, flessen, prijs, tekst } of null
//   Winkel.pakketten()           alle pakketten
//   Winkel.levering(methode)     { naam, uitleg, kosten, gratisVanaf? } of null
//   Winkel.levertermijn()        "Cath neemt binnen … contact met je op. …" of ''
//   Winkel.regels()              [{ id, naam, flessen, prijs, aantal, subtotaal }]
//   Winkel.aantal()              totaal aantal pakketten in het mandje
//   Winkel.subtotaal()           centen
//   Winkel.verzendkosten(m)      centen voor 'bezorgen' of 'ophalen'
//   Winkel.totaal(m)             subtotaal + verzendkosten
//   Winkel.voegToe(id, n = 1)    n erbij (tot maxPerPakket)
//   Winkel.zet(id, n)            aantal vastzetten (0 = weghalen)
//   Winkel.haalWeg(id)
//   Winkel.leeg()
//   Winkel.inhoud()              { id: aantal } zoals de server het wil hebben
//   Winkel.euro(centen)          "€ 49,00"
//   document 'winkel:change'     event na elke wijziging (ook vanuit een ander tabblad,
//                                en als de pagina terugkomt via de terugknop)

(function () {
    'use strict';

    var KEY = 'cathdrwijnen-mandje';
    var catalogus = { pakketten: [], levering: {}, levertermijn: '', maxPerPakket: 10 };
    var geheugen = null; // als localStorage geblokkeerd is

    function lees() {
        try {
            var raw = window.localStorage.getItem(KEY);
            if (raw) {
                var data = JSON.parse(raw);
                if (data && data.v === 1 && data.items && typeof data.items === 'object') return data.items;
            }
            return {};
        } catch (e) {
            return geheugen || {};
        }
    }

    function schrijf(items) {
        try {
            window.localStorage.setItem(KEY, JSON.stringify({ v: 1, items: items }));
        } catch (e) {
            geheugen = items;
        }
        document.dispatchEvent(new CustomEvent('winkel:change'));
    }

    function pakket(id) {
        for (var i = 0; i < catalogus.pakketten.length; i++) {
            if (catalogus.pakketten[i].id === id) return catalogus.pakketten[i];
        }
        return null;
    }

    function begrens(n) {
        n = Math.floor(Number(n) || 0);
        return Math.max(0, Math.min(n, catalogus.maxPerPakket || 10));
    }

    // Alleen pakketten die (nog) bestaan tellen mee
    function geldig(items) {
        var schoon = {};
        Object.keys(items).forEach(function (id) {
            var n = begrens(items[id]);
            if (n > 0 && pakket(id)) schoon[id] = n;
        });
        return schoon;
    }

    var euroFormat = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' });

    var Winkel = {
        ready: null,

        pakket: pakket,
        pakketten: function () { return catalogus.pakketten.slice(); },
        levering: function (methode) { return catalogus.levering[methode] || null; },
        levertermijn: function () { return catalogus.levertermijn; },

        regels: function () {
            var items = geldig(lees());
            return Object.keys(items).map(function (id) {
                var p = pakket(id);
                return {
                    id: id, naam: p.naam, flessen: p.flessen, prijs: p.prijs,
                    aantal: items[id], subtotaal: p.prijs * items[id]
                };
            });
        },
        aantal: function () {
            return Winkel.regels().reduce(function (som, r) { return som + r.aantal; }, 0);
        },
        subtotaal: function () {
            return Winkel.regels().reduce(function (som, r) { return som + r.subtotaal; }, 0);
        },
        verzendkosten: function (methode) {
            var l = catalogus.levering[methode];
            if (!l) return 0;
            if (l.gratisVanaf && Winkel.subtotaal() >= l.gratisVanaf) return 0;
            return l.kosten || 0;
        },
        totaal: function (methode) {
            return Winkel.subtotaal() + Winkel.verzendkosten(methode);
        },

        voegToe: function (id, n) {
            if (!pakket(id)) return;
            var items = geldig(lees());
            items[id] = begrens((items[id] || 0) + (n == null ? 1 : n));
            if (!items[id]) delete items[id];
            schrijf(items);
        },
        zet: function (id, n) {
            if (!pakket(id)) return;
            var items = geldig(lees());
            n = begrens(n);
            if (n) items[id] = n; else delete items[id];
            schrijf(items);
        },
        haalWeg: function (id) { Winkel.zet(id, 0); },
        leeg: function () { schrijf({}); },
        inhoud: function () { return geldig(lees()); },
        max: function () { return catalogus.maxPerPakket || 10; },

        euro: function (centen) { return euroFormat.format((centen || 0) / 100); }
    };

    Winkel.ready = fetch('pakketten.json', { cache: 'no-cache' })
        .then(function (res) {
            if (!res.ok) throw new Error('pakketten.json: ' + res.status);
            return res.json();
        })
        .then(function (data) {
            catalogus = {
                pakketten: data.pakketten || [],
                levering: data.levering || {},
                levertermijn: typeof data.levertermijn === 'string' ? data.levertermijn : '',
                maxPerPakket: data.maxPerPakket || 10
            };
            document.dispatchEvent(new CustomEvent('winkel:change'));
            return catalogus;
        });

    // Mandje aangepast in een ander tabblad
    window.addEventListener('storage', function (e) {
        if (e.key === KEY) document.dispatchEvent(new CustomEvent('winkel:change'));
    });

    // Terug met de terugknop: de browser kan de pagina uit zijn geheugen halen.
    // Niet elke browser meldt dan wat er intussen in het mandje veranderd is
    // (bijvoorbeeld leeg na het betalen), dus voor de zekerheid alles opnieuw tonen.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) document.dispatchEvent(new CustomEvent('winkel:change'));
    });

    window.Winkel = Winkel;
})();
