// cathdrwijnen · afrekenen
// Laat het mandje zien (window.Winkel uit js/winkel.js), vraagt bezorgen of
// ophalen en de gegevens van de klant, controleert alles en stuurt de bestelling
// als JSON naar api/bestelling.php. Klopt alles, dan stuurt de server een
// checkoutUrl terug en gaat de klant door naar de betaalpagina (Mollie, of de
// nep-betaalpagina in testmodus). De server rekent de prijzen zelf opnieuw uit;
// de bedragen op deze pagina zijn alleen ter controle voor de klant.
//
// Wat de klant invult, bewaren we tijdelijk in sessionStorage (alleen in dit
// tabblad). Mislukt het betalen, dan staat alles nog klaar bij "Opnieuw
// afrekenen". js/bedankt.js gooit het weg zodra de betaling gelukt is.
// De twee vinkjes bewaren we bewust niet: die zet de klant elke keer zelf aan.

(function () {
    'use strict';

    var API = 'api/bestelling.php';
    // Op de online voorbeeldsite (GitHub Pages) draait geen PHP, dus daar kan niet betaald worden
    var VOORBEELD = /\.github\.io$/i.test(window.location.hostname);
    var CONCEPT_KEY = 'cathdrwijnen-afrekenen';
    var WACHTTIJD = 30000; // zo lang wachten we op de server (ms)
    var ADRESVELDEN = ['postcode', 'huisnummer', 'straat', 'plaats'];
    var CONCEPTVELDEN = ['naam', 'email', 'telefoon', 'postcode', 'huisnummer', 'straat', 'plaats', 'opmerking'];
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var POSTCODE = /^\s*([1-9][0-9]{3})\s*([a-zA-Z]{2})\s*$/;

    var TEKST = {
        bezig: 'Even geduld…',
        doorsturen: 'Je gaat naar het betalen…',
        mislukt: 'Het betalen kon niet worden gestart. Probeer het zo nog eens.',
        geenVerbinding: 'Er is geen verbinding met de winkel. Controleer je internetverbinding en probeer het opnieuw. Er is nog niets betaald.',
        teLang: 'Het duurt te lang voordat de betaalpagina reageert. Probeer het zo nog eens. Er is nog niets betaald.',
        serverVelden: 'Niet alles klopt. Kijk de rode meldingen even na.'
    };

    // Hoe een veld heet in de samenvatting bij de knop
    var NAMEN = {
        items: 'je bestelling',
        levering: 'bezorgen of ophalen',
        naam: 'je naam',
        email: 'je e-mailadres',
        telefoon: 'je telefoonnummer',
        postcode: 'je postcode',
        huisnummer: 'je huisnummer',
        straat: 'je straat',
        plaats: 'je woonplaats',
        opmerking: 'je opmerking',
        achttienPlus: 'de 18+ bevestiging',
        voorwaarden: 'de algemene voorwaarden'
    };

    var form, lijst, knop, knopTekst, foutBox, statusRegel, adresBlok, leveringGroep, intro;
    var staten = {};
    var klaar = false;   // pakketten.json is geladen
    var bezig = false;   // bestelling wordt verstuurd
    var foutSoort = '';  // 'velden' of 'algemeen': bepaalt of de samenvatting vanzelf weg mag

    // ---------- Kleine hulpjes ----------

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function zetAlle(selector, tekst) {
        document.querySelectorAll(selector).forEach(function (node) { node.textContent = tekst; });
    }

    // aria-describedby kan meerdere id's bevatten; voeg er één toe of haal er één weg
    function describedBy(node, id, add) {
        var ids = (node.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        ids = ids.filter(function (x) { return x !== id; });
        if (add) ids.push(id);
        if (ids.length) node.setAttribute('aria-describedby', ids.join(' '));
        else node.removeAttribute('aria-describedby');
    }

    // Korte melding voor schermlezers ("Aantal van De salon: 3.")
    function meld(tekst) {
        statusRegel.textContent = '';
        window.setTimeout(function () { statusRegel.textContent = tekst; }, 60);
    }

    function netjesPostcode(waarde) {
        var m = POSTCODE.exec(waarde);
        return m ? m[1] + ' ' + m[2].toUpperCase() : String(waarde).trim();
    }

    // Zelfde regel als op de server (api/lib/winkel.php): alleen cijfers, spaties
    // en ( ) - . met hooguit een + vooraan, en minstens 8 cijfers
    function telefoonKlopt(waarde) {
        var cijfers = waarde.replace(/\D/g, '').length;
        return /^\+?[0-9()\-.\s]+$/.test(waarde) && cijfers >= 8 && cijfers <= 15;
    }

    function gekozenLevering() {
        var radio = form.querySelector('input[name="levering"]:checked');
        return radio ? radio.value : '';
    }

    // Alleen http(s)-adressen volgen we, nooit iets als javascript:
    function veiligeUrl(url) {
        if (typeof url !== 'string' || !url) return '';
        try {
            var u = new URL(url, window.location.href);
            return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : '';
        } catch (e) {
            return '';
        }
    }

    // ---------- Concept in sessionStorage ----------

    function bewaarConcept() {
        var data = { levering: gekozenLevering() };
        CONCEPTVELDEN.forEach(function (naam) { data[naam] = form.elements[naam].value; });
        try { window.sessionStorage.setItem(CONCEPT_KEY, JSON.stringify(data)); } catch (e) {}
    }

    function herstelConcept() {
        var data = null;
        try { data = JSON.parse(window.sessionStorage.getItem(CONCEPT_KEY) || 'null'); } catch (e) {}
        if (!data || typeof data !== 'object') return;
        CONCEPTVELDEN.forEach(function (naam) {
            var veld = form.elements[naam];
            // Wat de browser zelf al teruggezet heeft, laten we staan
            if (typeof data[naam] === 'string' && !veld.value) veld.value = data[naam];
        });
        if (data.levering === 'bezorgen' || data.levering === 'ophalen') {
            form.querySelector('input[name="levering"][value="' + data.levering + '"]').checked = true;
        }
    }

    // ---------- Welke state is zichtbaar ----------
    // laden → form (mandje gevuld), leeg of storing (pakketten.json niet geladen)

    var huidigeStaat = '';

    function toonStaat(staat) {
        var vorige = huidigeStaat;
        if (staat === vorige) return;
        huidigeStaat = staat;
        Object.keys(staten).forEach(function (naam) { staten[naam].hidden = naam !== staat; });
        if (intro) intro.hidden = staat !== 'form' && staat !== 'laden';
        // Laatste pakket weggehaald: focus mag niet in het niets verdwijnen
        if (vorige === 'form' && staat === 'leeg') staten.leeg.focus();
        if (staat === 'storing') staten.storing.focus();
    }

    // ---------- 1 Je bestelling ----------

    function maakRegel(id) {
        var li = el('li', 'regel');
        li.setAttribute('data-regel', id);

        var info = el('div', 'regel__info');
        info.appendChild(el('h3', 'regel__naam'));
        info.appendChild(el('p', 'regel__meta'));

        var acties = el('div', 'regel__acties');
        var teller = el('div', 'teller');
        teller.setAttribute('role', 'group');
        var min = el('button', 'teller__knop', '−');
        min.type = 'button';
        min.setAttribute('data-min', '');
        min.setAttribute('aria-label', 'Eén minder');
        var aantal = el('span', 'teller__aantal');
        var plus = el('button', 'teller__knop', '+');
        plus.type = 'button';
        plus.setAttribute('data-plus', '');
        plus.setAttribute('aria-label', 'Eén meer');
        teller.appendChild(min);
        teller.appendChild(aantal);
        teller.appendChild(plus);

        var weg = el('button', 'regel__weg');
        weg.type = 'button';
        weg.setAttribute('data-weg', '');

        var max = el('p', 'regel__max');
        max.hidden = true;

        acties.appendChild(teller);
        acties.appendChild(weg);

        li.appendChild(info);
        li.appendChild(el('p', 'regel__prijs'));
        li.appendChild(acties);
        li.appendChild(max);
        return li;
    }

    function vulRegel(li, r, max) {
        li.querySelector('.regel__naam').textContent = r.naam;
        li.querySelector('.regel__meta').textContent =
            r.flessen + (r.flessen === 1 ? ' fles' : ' flessen') + ' · ' + Winkel.euro(r.prijs) + ' per pakket';

        var prijs = li.querySelector('.regel__prijs');
        prijs.textContent = '';
        prijs.appendChild(el('span', 'visually-hidden', 'Samen '));
        prijs.appendChild(document.createTextNode(Winkel.euro(r.subtotaal)));

        li.querySelector('.teller').setAttribute('aria-label', 'Aantal van ' + r.naam);
        li.querySelector('.teller__aantal').textContent = String(r.aantal);
        // aria-disabled in plaats van disabled: dan blijft de focus op de knop staan
        li.querySelector('[data-min]').setAttribute('aria-disabled', r.aantal <= 1 ? 'true' : 'false');
        li.querySelector('[data-plus]').setAttribute('aria-disabled', r.aantal >= max ? 'true' : 'false');

        var weg = li.querySelector('[data-weg]');
        weg.textContent = 'Weghalen';
        weg.appendChild(el('span', 'visually-hidden', ': ' + r.naam));

        var hint = li.querySelector('.regel__max');
        hint.textContent = 'Je kunt hooguit ' + max + ' van dit pakket bestellen.';
        hint.hidden = r.aantal < max;
    }

    function tekenRegels(regels) {
        var max = Winkel.max();
        var ids = regels.map(function (r) { return r.id; });
        var focusKwijt = false;

        // Eerst weghalen wat niet meer in het mandje zit
        Array.prototype.slice.call(lijst.children).forEach(function (li) {
            if (ids.indexOf(li.getAttribute('data-regel')) === -1) {
                if (li.contains(document.activeElement)) focusKwijt = true;
                li.remove();
            }
        });

        // Dan bijwerken en aanvullen, in de volgorde van het mandje
        regels.forEach(function (r, i) {
            var li = lijst.querySelector('[data-regel="' + r.id + '"]') || maakRegel(r.id);
            vulRegel(li, r, max);
            if (lijst.children[i] !== li) lijst.insertBefore(li, lijst.children[i] || null);
        });

        if (focusKwijt) document.getElementById('stap1').focus();
    }

    function klikInLijst(e) {
        var btn = e.target.closest('button');
        if (!btn || !lijst.contains(btn)) return;
        var id = btn.closest('[data-regel]').getAttribute('data-regel');
        var pakket = Winkel.pakket(id);
        if (!pakket) return;
        var nu = Winkel.inhoud()[id] || 0;

        if (btn.hasAttribute('data-weg')) {
            Winkel.haalWeg(id);
            meld(pakket.naam + ' is uit je mandje gehaald.');
            return;
        }

        var erbij = btn.hasAttribute('data-plus');
        if (btn.getAttribute('aria-disabled') === 'true') {
            meld(erbij
                ? 'Je kunt hooguit ' + Winkel.max() + ' van dit pakket bestellen.'
                : 'Wil je dit pakket niet meer? Kies dan Weghalen.');
            return;
        }
        Winkel.zet(id, nu + (erbij ? 1 : -1));
        meld('Aantal van ' + pakket.naam + ': ' + (nu + (erbij ? 1 : -1)) + '.');
    }

    // ---------- 2 Bezorgen of ophalen ----------

    function tekenLevering() {
        ['bezorgen', 'ophalen'].forEach(function (methode) {
            var l = Winkel.levering(methode);
            if (!l) return; // dan blijven de teksten uit de HTML staan
            var kosten = Winkel.verzendkosten(methode);
            zetAlle('[data-levering-naam="' + methode + '"]', l.naam);
            zetAlle('[data-levering-uitleg="' + methode + '"]', l.uitleg);
            zetAlle('[data-levering-prijs="' + methode + '"]', kosten ? Winkel.euro(kosten) : 'Gratis');
            var extra = form.querySelector('[data-levering-extra="' + methode + '"]');
            if (extra) {
                extra.hidden = !(l.gratisVanaf && l.kosten);
                if (!extra.hidden) extra.textContent = 'Gratis vanaf ' + Winkel.euro(l.gratisVanaf);
            }
        });
        var termijn = Winkel.levertermijn();
        if (termijn) zetAlle('[data-levertermijn]', termijn);
    }

    // Adresvelden alleen bij bezorgen; een uitgeschakelde fieldset wordt niet
    // gecontroleerd en niet meegestuurd, maar wat er al in staat blijft bewaard
    function pasLeveringToe() {
        var bezorgen = gekozenLevering() === 'bezorgen';
        adresBlok.hidden = !bezorgen;
        adresBlok.disabled = !bezorgen;
        if (!bezorgen) ADRESVELDEN.forEach(function (naam) { wisFout(form.elements[naam]); });
    }

    // ---------- Straat en huisnummer ----------
    // Automatisch aanvullen van de browser zet bij Straat (address-line1) vaak de
    // hele regel: "Kerkstraat 12". Is Huisnummer nog leeg, dan zetten we het nummer
    // daar. Alleen bij automatisch invullen: een straat als "Plein 1944" die de
    // klant zelf typt, blijft zoals hij is.
    var STRAAT_MET_NUMMER = /^(.+)\s+(\d+\s*[-\s]?[a-zA-Z0-9]{0,4})$/;

    function automatischIngevuld(veld) {
        var kenmerken = [':autofill', ':-webkit-autofill'];
        for (var i = 0; i < kenmerken.length; i++) {
            try {
                if (veld.matches(kenmerken[i])) return true;
            } catch (e) {} // deze browser kent dit kenmerk niet
        }
        return false;
    }

    function splitsStraat() {
        var straat = form.elements.straat;
        var nummer = form.elements.huisnummer;
        if (String(nummer.value).trim() || !automatischIngevuld(straat)) return;
        var m = STRAAT_MET_NUMMER.exec(String(straat.value).trim());
        if (!m) return;
        straat.value = m[1];
        nummer.value = m[2];
        if (!melding(straat)) wisFout(straat);
        if (!melding(nummer)) wisFout(nummer);
        meld('Het huisnummer ' + m[2] + ' staat nu in het vakje Huisnummer.');
    }

    // "Kerkstraat 12" bij Straat én "12" bij Huisnummer: het nummer niet twee keer doorgeven
    function straatZonderNummer(straat, nummer) {
        var eind = ' ' + nummer.replace(/\s+/g, ' ').toLowerCase();
        var s = straat.replace(/\s+/g, ' ');
        if (nummer && s.length > eind.length && s.toLowerCase().slice(-eind.length) === eind) {
            return s.slice(0, -eind.length).trim();
        }
        return straat;
    }

    // ---------- Overzicht ----------

    function tekenOverzicht(regels) {
        var methode = gekozenLevering();
        var l = Winkel.levering(methode);
        var kosten = Winkel.verzendkosten(methode);

        var ul = form.querySelector('[data-overzicht-regels]');
        ul.textContent = '';
        regels.forEach(function (r) {
            var li = el('li');
            li.appendChild(el('span', null, r.aantal + ' × ' + r.naam));
            li.appendChild(el('span', null, Winkel.euro(r.subtotaal)));
            ul.appendChild(li);
        });

        zetAlle('[data-subtotaal]', Winkel.euro(Winkel.subtotaal()));
        zetAlle('[data-levering-label]', l ? l.naam : 'Verzendkosten');
        zetAlle('[data-verzendkosten]', kosten ? Winkel.euro(kosten) : 'Gratis');
        zetAlle('[data-totaal]', Winkel.euro(Winkel.totaal(methode)));

        var tip = form.querySelector('[data-gratis-tip]');
        var tekort = l && l.gratisVanaf ? l.gratisVanaf - Winkel.subtotaal() : 0;
        tip.hidden = !(kosten && tekort > 0);
        if (!tip.hidden) tip.textContent = 'Bestel je voor ' + Winkel.euro(tekort) + ' meer, dan is bezorgen gratis.';
    }

    // Na elke wijziging in het mandje (ook vanuit een ander tabblad)
    function ververs() {
        if (!klaar) return;
        var regels = Winkel.regels();
        if (!regels.length) {
            toonStaat('leeg');
            return;
        }
        toonStaat('form');
        wisFout(lijst);
        tekenRegels(regels);
        tekenLevering();
        tekenOverzicht(regels);
    }

    // ---------- Controleren ----------

    function melding(veld) {
        var v = veld.type === 'checkbox' ? veld.checked : String(veld.value).trim();
        switch (veld.name) {
            case 'naam':
                return v ? '' : 'Vul je naam in.';
            case 'email':
                if (!v) return 'Vul je e-mailadres in. Daar sturen we de bevestiging naartoe.';
                return EMAIL.test(v) ? '' : 'Dit e-mailadres klopt niet helemaal. Controleer het even.';
            case 'telefoon':
                return !v || telefoonKlopt(v) ? '' : 'Dit telefoonnummer klopt niet helemaal. Je mag het ook leeg laten.';
            case 'postcode':
                if (!v) return 'Vul je postcode in.';
                return POSTCODE.test(v) ? '' : 'Vul een Nederlandse postcode in, zoals 1234 AB.';
            case 'huisnummer':
                if (!v) return 'Vul je huisnummer in.';
                return /^[0-9]/.test(v) ? '' : 'Een huisnummer begint met een cijfer, zoals 12 of 12a.';
            case 'straat':
                return v ? '' : 'Vul je straat in.';
            case 'plaats':
                return v ? '' : 'Vul je woonplaats in.';
            case 'opmerking':
                return v.length > 1000 ? 'Je opmerking is te lang. Hooguit 1000 tekens.' : '';
            case 'achttienPlus':
                return v ? '' : 'Bevestig dat je 18 jaar of ouder bent.';
            case 'voorwaarden':
                return v ? '' : 'Ga akkoord met de algemene voorwaarden, anders kun je niet bestellen.';
            default:
                return '';
        }
    }

    // Alle velden die we zelf controleren, in de volgorde van de pagina
    function velden() {
        return Array.prototype.filter.call(form.querySelectorAll('input, textarea'), function (veld) {
            return veld.type !== 'radio' && veld.name && veld.name !== 'website' && !veld.matches(':disabled');
        });
    }

    // Waar de foutmelding komt: onder het veld, onder het vinkje, onder de groep
    function anker(veld) {
        return veld.type === 'checkbox' ? veld.closest('label') : veld;
    }

    function toonFout(veld, tekst) {
        var id = veld.id + '-fout';
        var p = document.getElementById(id);
        if (!p) {
            p = el('p', 'field__error');
            p.id = id;
            anker(veld).insertAdjacentElement('afterend', p);
        }
        p.textContent = tekst;
        if (veld.tagName === 'FIELDSET') {
            veld.querySelectorAll('input').forEach(function (r) { r.setAttribute('aria-invalid', 'true'); });
        } else if (veld.tagName !== 'UL') {
            veld.setAttribute('aria-invalid', 'true');
        }
        describedBy(veld, id, true);
    }

    function wisFout(veld) {
        if (!veld) return;
        var id = veld.id + '-fout';
        var p = document.getElementById(id);
        if (p) p.remove();
        veld.removeAttribute('aria-invalid');
        if (veld.tagName === 'FIELDSET') {
            veld.querySelectorAll('[aria-invalid]').forEach(function (r) { r.removeAttribute('aria-invalid'); });
        }
        describedBy(veld, id, false);
    }

    function controleerAlles() {
        var fout = [];
        velden().forEach(function (veld) {
            var tekst = melding(veld);
            if (tekst) {
                toonFout(veld, tekst);
                fout.push(veld);
            } else {
                wisFout(veld);
            }
        });
        return fout;
    }

    function focusOp(doel) {
        if (doel === lijst) {
            document.getElementById('stap1').focus();
        } else if (doel.tagName === 'FIELDSET') {
            var radio = doel.querySelector('input:checked') || doel.querySelector('input');
            if (radio) radio.focus();
        } else {
            doel.focus();
        }
    }

    function samenvatting(namen) {
        var tekst = namen.length > 1
            ? namen.slice(0, -1).join(', ') + ' en ' + namen[namen.length - 1]
            : namen[0];
        return (namen.length === 1 ? 'Nog 1 ding nodig: ' : 'Nog ' + namen.length + ' dingen nodig: ') + tekst + '.';
    }

    function toonAlgemeen(tekst, soort, extra) {
        foutBox.textContent = '';
        foutBox.appendChild(el('p', null, tekst));
        if (extra && extra.length) {
            var ul = el('ul');
            extra.forEach(function (t) { ul.appendChild(el('li', null, t)); });
            foutBox.appendChild(ul);
        }
        foutBox.hidden = false;
        foutSoort = soort;
    }

    function verbergAlgemeen() {
        foutBox.hidden = true;
        foutBox.textContent = '';
        foutSoort = '';
    }

    // ---------- Fouten van de server op de juiste plek zetten ----------
    // Sleutels zoals 'klant.email', 'levering', 'items', 'achttienPlus'

    function veldVoor(sleutel) {
        var naam = String(sleutel).replace(/^klant\./, '');
        if (/^items(\.|$)/.test(naam)) return lijst;
        if (naam === 'levering') return leveringGroep;
        if (naam === 'website' || !NAMEN[naam]) return null;
        var veld = form.elements[naam];
        if (!veld || !veld.tagName || veld.matches(':disabled')) return null;
        return veld;
    }

    function toonServerFouten(fouten, kop) {
        var doelen = [];
        var los = [];
        Object.keys(fouten).forEach(function (sleutel) {
            var tekst = typeof fouten[sleutel] === 'string' ? fouten[sleutel] : '';
            var veld = veldVoor(sleutel);
            if (veld) {
                toonFout(veld, tekst || 'Controleer dit veld.');
                if (doelen.indexOf(veld) === -1) doelen.push(veld);
            } else if (tekst) {
                los.push(tekst);
            }
        });
        // Focus naar de eerste fout zoals die op de pagina staat
        doelen.sort(function (a, b) {
            return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
        });
        var tekst = typeof kop === 'string' && kop ? kop : (doelen.length ? TEKST.serverVelden : TEKST.mislukt);
        toonAlgemeen(tekst, doelen.length ? 'velden' : 'algemeen', los);
        if (doelen.length) focusOp(doelen[0]);
        else knop.focus();
    }

    // ---------- Versturen ----------

    function zetBezig(aan) {
        bezig = aan;
        if (aan) {
            knop.setAttribute('aria-busy', 'true');
            knop.setAttribute('aria-disabled', 'true');
            knop.textContent = TEKST.bezig;
        } else {
            knop.removeAttribute('aria-busy');
            knop.removeAttribute('aria-disabled');
            knop.textContent = knopTekst;
        }
    }

    function verzamel() {
        var methode = gekozenLevering();
        var bezorgen = methode === 'bezorgen';
        function waarde(naam) { return String(form.elements[naam].value || '').trim(); }
        return {
            items: Winkel.inhoud(),
            levering: methode,
            klant: {
                naam: waarde('naam'),
                email: waarde('email'),
                telefoon: waarde('telefoon'),
                straat: bezorgen ? straatZonderNummer(waarde('straat'), waarde('huisnummer')) : '',
                huisnummer: bezorgen ? waarde('huisnummer') : '',
                postcode: bezorgen ? netjesPostcode(waarde('postcode')) : '',
                plaats: bezorgen ? waarde('plaats') : ''
            },
            opmerking: waarde('opmerking'),
            achttienPlus: form.elements.achttienPlus.checked === true,
            voorwaarden: form.elements.voorwaarden.checked === true,
            website: String(form.elements.website.value || '')
        };
    }

    function verstuur(e) {
        e.preventDefault();
        if (bezig) return; // dubbel klikken of nog een keer Enter
        verbergAlgemeen();
        // Fouten die alleen de server kan geven, horen bij de vorige poging
        wisFout(lijst);
        wisFout(leveringGroep);

        var fout = controleerAlles();
        if (fout.length) {
            toonAlgemeen(samenvatting(fout.map(function (v) { return NAMEN[v.name] || 'een veld'; })), 'velden');
            focusOp(fout[0]);
            return;
        }
        if (!Winkel.aantal()) {
            ververs();
            return;
        }

        if (VOORBEELD) {
            toonAlgemeen('Dit is een voorbeeld. Je kunt alles invullen, maar betalen werkt hier nog niet.', 'voorbeeld');
            return;
        }

        zetBezig(true);
        bewaarConcept();

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = window.setTimeout(function () { if (controller) controller.abort(); }, WACHTTIJD);

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(verzamel()),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            // Ook bij een foutstatus staat er meestal JSON in het antwoord
            return res.json().then(
                function (data) { return { status: res.status, data: data }; },
                function () { return { status: res.status, data: null }; }
            );
        }).then(function (antwoord) {
            window.clearTimeout(timer);
            var data = antwoord.data && typeof antwoord.data === 'object' ? antwoord.data : {};
            var gelukt = antwoord.status >= 200 && antwoord.status < 300 && data.ok === true;
            var url = gelukt ? veiligeUrl(data.checkoutUrl) : '';

            if (url) {
                // Knop blijft uit: de browser gaat zo naar de betaalpagina
                knop.textContent = TEKST.doorsturen;
                window.location.href = url;
                return;
            }

            zetBezig(false);
            if (data.fouten && typeof data.fouten === 'object' && Object.keys(data.fouten).length) {
                toonServerFouten(data.fouten, data.melding);
            } else {
                toonAlgemeen(typeof data.melding === 'string' && data.melding ? data.melding : TEKST.mislukt, 'algemeen');
                knop.focus();
            }
        }).catch(function (err) {
            window.clearTimeout(timer);
            zetBezig(false);
            toonAlgemeen(err && err.name === 'AbortError' ? TEKST.teLang : TEKST.geenVerbinding, 'algemeen');
            knop.focus();
        });
    }

    // Fout weg zodra het veld klopt; samenvatting weg als er geen fouten meer zijn
    function bijInvullen(e) {
        var veld = e.target;
        if (veld.name === 'levering') {
            pasLeveringToe();
            wisFout(leveringGroep);
            if (klaar) tekenOverzicht(Winkel.regels());
        } else if (veld.getAttribute('aria-invalid') === 'true' && !melding(veld)) {
            wisFout(veld);
        }
        if (foutSoort === 'velden' && !form.querySelector('[aria-invalid="true"]') && !document.getElementById(lijst.id + '-fout')) {
            verbergAlgemeen();
        }
        if (veld.name !== 'achttienPlus' && veld.name !== 'voorwaarden' && veld.name !== 'website') bewaarConcept();
    }

    // ---------- Start ----------

    function init() {
        form = document.getElementById('kassaForm');
        if (!form || !window.Winkel) return;

        lijst = form.querySelector('[data-regels]');
        knop = form.querySelector('[data-submit]');
        knopTekst = knop.textContent;
        foutBox = document.getElementById('kassaFout');
        statusRegel = document.querySelector('[data-kassa-status]');
        adresBlok = form.querySelector('[data-adres]');
        leveringGroep = document.getElementById('k-levering');
        staten = {
            laden: document.querySelector('[data-kassa-laden]'),
            storing: document.querySelector('[data-kassa-storing]'),
            leeg: document.querySelector('[data-kassa-leeg]'),
            form: form
        };
        intro = document.querySelector('[data-kassa-intro]');
        if (VOORBEELD) {
            var voorbeeld = document.querySelector('[data-kassa-voorbeeld]');
            if (voorbeeld) voorbeeld.hidden = false;
        }

        herstelConcept();
        pasLeveringToe();
        toonStaat('laden');

        lijst.addEventListener('click', klikInLijst);
        form.addEventListener('input', bijInvullen);
        form.addEventListener('change', bijInvullen);
        form.addEventListener('submit', verstuur);

        // Postcode netjes als "1234 AB" zodra je het veld verlaat
        form.elements.postcode.addEventListener('blur', function () {
            if (POSTCODE.test(this.value)) this.value = netjesPostcode(this.value);
        });
        // Automatisch ingevuld "Kerkstraat 12" uit elkaar halen (vóór bijInvullen op het formulier)
        form.elements.straat.addEventListener('input', splitsStraat);
        form.elements.straat.addEventListener('change', splitsStraat);

        Winkel.ready.then(function () {
            klaar = true;
            ververs();
            document.addEventListener('winkel:change', ververs);
        }, function () {
            toonStaat('storing');
        });

        // Terug van de betaalpagina met de terugknop: de pagina kan uit het
        // geheugen van de browser komen, met de knop nog op "Even geduld…"
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            zetBezig(false);
            ververs();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
