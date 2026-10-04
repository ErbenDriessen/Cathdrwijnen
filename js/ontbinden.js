// cathdrwijnen · bestelling ontbinden
// Twee stappen, zoals de wet het wil (art. 6:230oa BW): eerst naam, bestelnummer
// en e-mail invullen en op "Verder" drukken, dan controleren en op "Ontbinding
// bevestigen" drukken. api/ontbinden.php bewaart de ontbinding en mailt meteen
// een ontvangstbevestiging; hier laten we zien wanneer die binnenkwam.
// Een link als ontbinden.html?ref=CDW-… (uit de bevestigingsmail of van de
// bedankpagina) vult het bestelnummer alvast in.
// Een onbekend bestelnummer keuren we niet af: dat zoekt Cath zelf uit.

(function () {
    'use strict';

    var API = 'api/ontbinden.php';
    var WACHTTIJD = 30000; // zo lang wachten we op de server (ms)
    var VELDEN = ['naam', 'bestelnummer', 'email'];
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    var NAMEN = {
        naam: 'je naam',
        bestelnummer: 'je bestelnummer',
        email: 'je e-mailadres'
    };
    var TEKST = {
        bezig: 'Even geduld…',
        mislukt: 'Je ontbinding kon niet worden verstuurd. Probeer het zo nog eens, of mail Cath op info@cathdrwijnen.nl.',
        geenVerbinding: 'Er is geen verbinding met de site. Controleer je internetverbinding en probeer het opnieuw. Je ontbinding is nog niet verstuurd.',
        teLang: 'Het duurt te lang voordat de site reageert. Probeer het zo nog eens. Je ontbinding is nog niet verstuurd.',
        serverVelden: 'Niet alles klopt. Kijk de rode meldingen even na.'
    };

    var form, stappen, intro, foutInvullen, foutBevestig, bevestigKnop, knopTekst, statusRegel;
    var bezig = false;

    // ---------- Kleine hulpjes (zelfde aanpak als js/afrekenen.js) ----------

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function describedBy(node, id, add) {
        var ids = (node.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        ids = ids.filter(function (x) { return x !== id; });
        if (add) ids.push(id);
        if (ids.length) node.setAttribute('aria-describedby', ids.join(' '));
        else node.removeAttribute('aria-describedby');
    }

    function meld(tekst) {
        statusRegel.textContent = '';
        window.setTimeout(function () { statusRegel.textContent = tekst; }, 60);
    }

    function waarde(naam) {
        return String(form.elements[naam].value || '').replace(/\s+/g, ' ').trim();
    }

    // ---------- Stappen: invullen → controleren → klaar ----------

    function toonStap(stap) {
        Object.keys(stappen).forEach(function (naam) { stappen[naam].hidden = naam !== stap; });
        form.hidden = stap === 'klaar';
        intro.hidden = stap === 'klaar';
    }

    // ---------- Controleren ----------

    function melding(naam, v) {
        switch (naam) {
            case 'naam':
                return v ? '' : 'Vul je naam in.';
            case 'bestelnummer':
                return v ? '' : 'Vul je bestelnummer in. Weet je het niet meer? Schrijf dan iets waaraan Cath je bestelling herkent, zoals de datum.';
            case 'email':
                if (!v) return 'Vul je e-mailadres in. Daar sturen we de bevestiging naartoe.';
                return EMAIL.test(v) ? '' : 'Dit e-mailadres klopt niet helemaal. Controleer het even.';
            default:
                return '';
        }
    }

    function toonFout(veld, tekst) {
        var id = veld.id + '-fout';
        var p = document.getElementById(id);
        if (!p) {
            p = el('p', 'field__error');
            p.id = id;
            veld.insertAdjacentElement('afterend', p);
        }
        p.textContent = tekst;
        veld.setAttribute('aria-invalid', 'true');
        describedBy(veld, id, true);
    }

    function wisFout(veld) {
        var id = veld.id + '-fout';
        var p = document.getElementById(id);
        if (p) p.remove();
        veld.removeAttribute('aria-invalid');
        describedBy(veld, id, false);
    }

    function toonAlgemeen(box, tekst) {
        box.textContent = '';
        box.appendChild(el('p', null, tekst));
        box.hidden = false;
    }

    function samenvatting(namen) {
        var tekst = namen.length > 1
            ? namen.slice(0, -1).join(', ') + ' en ' + namen[namen.length - 1]
            : namen[0];
        return (namen.length === 1 ? 'Nog 1 ding nodig: ' : 'Nog ' + namen.length + ' dingen nodig: ') + tekst + '.';
    }

    // Fouten (van de browser of van de server) tonen en de focus op de eerste zetten
    function toonFouten(fouten, kop) {
        var eerste = null;
        var namen = [];
        VELDEN.forEach(function (naam) {
            var veld = form.elements[naam];
            if (fouten[naam]) {
                toonFout(veld, fouten[naam]);
                namen.push(NAMEN[naam]);
                if (!eerste) eerste = veld;
            } else {
                wisFout(veld);
            }
        });
        toonAlgemeen(foutInvullen, kop || samenvatting(namen));
        if (eerste) eerste.focus();
        else foutInvullen.focus();
    }

    // ---------- Stap 1: Verder ----------

    function verder(e) {
        e.preventDefault();
        foutInvullen.hidden = true;
        var fouten = {};
        var fout = false;
        VELDEN.forEach(function (naam) {
            var tekst = melding(naam, waarde(naam));
            if (tekst) { fouten[naam] = tekst; fout = true; }
        });
        if (fout) {
            toonFouten(fouten);
            return;
        }
        VELDEN.forEach(function (naam) {
            wisFout(form.elements[naam]);
            form.querySelector('[data-toon="' + naam + '"]').textContent = waarde(naam);
        });
        foutBevestig.hidden = true;
        toonStap('controleren');
        document.getElementById('controleTitel').focus();
    }

    function wijzig() {
        if (bezig) return;
        toonStap('invullen');
        form.elements.naam.focus();
    }

    // ---------- Stap 2: Ontbinding bevestigen ----------

    function zetBezig(aan) {
        bezig = aan;
        if (aan) {
            bevestigKnop.setAttribute('aria-busy', 'true');
            bevestigKnop.setAttribute('aria-disabled', 'true');
            bevestigKnop.textContent = TEKST.bezig;
        } else {
            bevestigKnop.removeAttribute('aria-busy');
            bevestigKnop.removeAttribute('aria-disabled');
            bevestigKnop.textContent = knopTekst;
        }
    }

    function klaar(data) {
        var klaarBlok = stappen.klaar;
        klaarBlok.querySelector('[data-ontvangen]').textContent = typeof data.ontvangen === 'string' ? data.ontvangen : 'zojuist';
        klaarBlok.querySelector('[data-gemaild]').hidden = data.gemaild === false;
        klaarBlok.querySelector('[data-niet-gemaild]').hidden = data.gemaild !== false;
        toonStap('klaar');
        document.title = 'Ontbinding ontvangen · cathdrwijnen';
        klaarBlok.querySelector('h2').focus();
        meld('Je ontbinding is ontvangen.');
    }

    function bevestig() {
        if (bezig) return; // dubbel tikken
        foutBevestig.hidden = true;
        zetBezig(true);

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = window.setTimeout(function () { if (controller) controller.abort(); }, WACHTTIJD);

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                naam: waarde('naam'),
                bestelnummer: waarde('bestelnummer'),
                email: waarde('email'),
                website: String(form.elements.website.value || '')
            }),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            return res.json().then(
                function (data) { return { status: res.status, data: data }; },
                function () { return { status: res.status, data: null }; }
            );
        }).then(function (antwoord) {
            window.clearTimeout(timer);
            zetBezig(false);
            var data = antwoord.data && typeof antwoord.data === 'object' ? antwoord.data : {};
            if (antwoord.status >= 200 && antwoord.status < 300 && data.ok === true) {
                klaar(data);
                return;
            }
            // Iets niet goed ingevuld: terug naar stap 1, met de melding bij het veld
            if (data.fouten && typeof data.fouten === 'object' && Object.keys(data.fouten).some(function (k) { return VELDEN.indexOf(k) !== -1; })) {
                toonStap('invullen');
                toonFouten(data.fouten, TEKST.serverVelden);
                return;
            }
            toonAlgemeen(foutBevestig, typeof data.melding === 'string' && data.melding ? data.melding : TEKST.mislukt);
            bevestigKnop.focus();
        }).catch(function (err) {
            window.clearTimeout(timer);
            zetBezig(false);
            toonAlgemeen(foutBevestig, err && err.name === 'AbortError' ? TEKST.teLang : TEKST.geenVerbinding);
            bevestigKnop.focus();
        });
    }

    // Melding weg zodra het veld klopt
    function bijInvullen(e) {
        var veld = e.target;
        if (VELDEN.indexOf(veld.name) === -1) return;
        if (veld.getAttribute('aria-invalid') === 'true' && !melding(veld.name, waarde(veld.name))) wisFout(veld);
        if (!form.querySelector('[aria-invalid="true"]')) foutInvullen.hidden = true;
    }

    // Bestelnummer uit de link (?ref=CDW-…)
    function leesRef() {
        var ref = '';
        try {
            ref = new URLSearchParams(window.location.search).get('ref') || '';
        } catch (e) {}
        ref = ref.trim().toUpperCase();
        return /^CDW-[0-9]{8}-[A-Z0-9]{6}$/.test(ref) ? ref : '';
    }

    // ---------- Start ----------

    function init() {
        form = document.getElementById('ontbindForm');
        if (!form) return;
        stappen = {
            invullen: form.querySelector('[data-stap="invullen"]'),
            controleren: form.querySelector('[data-stap="controleren"]'),
            klaar: document.querySelector('[data-stap="klaar"]')
        };
        intro = document.querySelector('[data-ontbinden-intro]');
        foutInvullen = document.getElementById('ontbindFout');
        foutBevestig = document.getElementById('bevestigFout');
        bevestigKnop = form.querySelector('[data-bevestig]');
        knopTekst = bevestigKnop.textContent;
        statusRegel = document.querySelector('[data-ontbinden-status]');

        var ref = leesRef();
        if (ref && !form.elements.bestelnummer.value) form.elements.bestelnummer.value = ref;

        form.addEventListener('submit', verder);
        form.addEventListener('input', bijInvullen);
        bevestigKnop.addEventListener('click', bevestig);
        form.querySelector('[data-wijzig]').addEventListener('click', wijzig);

        toonStap('invullen');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
