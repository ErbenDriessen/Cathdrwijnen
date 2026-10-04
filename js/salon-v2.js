// cathdrwijnen · Salon v2
// Actief menu-item, pauzeknop voor de bewegende band, het mandje (knoppen op de
// pakketten, mandjeknop in de header, mandje-paneel, actiebalk onderin) en het
// contactformulier.
// De 18+ vraag staat in js/leeftijd.js (gedeeld met de afrekenpagina). Het mandje
// zelf staat in js/winkel.js (window.Winkel); dat bestand moet vóór dit bestand
// geladen worden.
// Zonder JavaScript werkt het contactformulier ook: alle velden zijn dan zichtbaar
// en het formulier verstuurt rechtstreeks naar Formspree (alleen naam, e-mail en
// de 18+ bevestiging zijn dan verplicht).

(function () {
    'use strict';

    // ---------- Menu: laat zien in welke sectie je bent ----------
    function initNav() {
        var links = document.querySelectorAll('.site-nav__list a[href^="#"]');
        if (!('IntersectionObserver' in window)) return;
        if (!links.length || !('IntersectionObserver' in window)) return;

        var byId = {};
        links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
        var sections = Object.keys(byId).map(function (id) { return document.getElementById(id); }).filter(Boolean);

        function setCurrent(id) {
            links.forEach(function (a) {
                if (a === byId[id]) a.setAttribute('aria-current', 'true');
                else a.removeAttribute('aria-current');
            });
            // Houd het actieve item in beeld als de menubalk op de telefoon scrolt
            var active = byId[id];
            if (active && active.parentNode.parentNode.scrollWidth > active.parentNode.parentNode.clientWidth) {
                active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        // Een sectie telt als "huidig" zodra hij de bovenste helft van het scherm vult
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) setCurrent(entry.target.id);
            });
            var anyVisible = sections.some(function (sec) {
                var r = sec.getBoundingClientRect();
                return r.top < window.innerHeight * 0.5 && r.bottom > window.innerHeight * 0.3;
            });
            if (!anyVisible) setCurrent(null);
        }, { rootMargin: '-30% 0px -50% 0px' });

        sections.forEach(function (sec) { observer.observe(sec); });

        initOrderBar();
    }

    // ---------- Actiebalk onderin (alleen zichtbaar op de telefoon via css) ----------
    // Zit er iets in het mandje, dan staat de balk er altijd en gaat hij naar de
    // afrekenpagina ("Mandje (2) · € 174,00 · Afrekenen").
    // Is het mandje leeg, dan verschijnt hij pas na de hero, past hij zich aan per
    // sectie en verdwijnt hij waar de pagina zelf al knoppen of het formulier toont.
    function initOrderBar() {
        var bar = document.querySelector('[data-order-bar]');
        var hero = document.getElementById('top');
        if (!bar || !hero) return;

        var watch = ['top', 'pakketten', 'aan-huis', 'over', 'contact'];
        var visible = {};
        var winkel = window.Winkel;

        function showCart(n) {
            var info = 'Mandje (' + n + ') · ' + winkel.euro(winkel.subtotaal());
            if (bar.getAttribute('data-mandje') === info) return; // niets veranderd
            bar.setAttribute('data-mandje', info);
            bar.textContent = '';
            // Links het mandje, rechts "Afrekenen" als lichte knop. De punt ertussen
            // is onzichtbaar maar telt wel mee in de tekst van de link.
            [['order-bar__info', info], ['visually-hidden', ' · '], ['order-bar__go', 'Afrekenen']].forEach(function (part) {
                var span = document.createElement('span');
                span.className = part[0];
                span.textContent = part[1];
                bar.appendChild(span);
            });
            bar.classList.add('order-bar--mandje');
            bar.setAttribute('href', 'afrekenen.html');
            bar.removeAttribute('data-form-type');
        }

        function update() {
            var inCart = winkel ? winkel.aantal() : 0;
            if (inCart > 0) {
                showCart(inCart);
                bar.hidden = false;
                return;
            }
            bar.removeAttribute('data-mandje');
            bar.classList.remove('order-bar--mandje');

            var show = !visible.top && !visible.pakketten && !visible.contact && !visible.footer;
            if (visible['aan-huis']) {
                bar.textContent = 'Proeverij aanvragen';
                bar.setAttribute('href', '#orderForm');
                bar.setAttribute('data-form-type', 'proeverij');
            } else {
                bar.textContent = 'Bekijk de pakketten';
                bar.setAttribute('href', '#pakketten');
                bar.removeAttribute('data-form-type');
            }
            bar.hidden = !show;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var key = entry.target.id || 'footer';
                visible[key] = entry.isIntersecting;
            });
            update();
        }, { rootMargin: '-25% 0px -35% 0px' });

        watch.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) io.observe(el);
        });
        var footer = document.querySelector('.site-footer');
        if (footer) io.observe(footer);

        document.addEventListener('winkel:change', update);
    }

    // ---------- Pauzeknop voor de bewegende band ----------
    function initMarquee() {
        var band = document.querySelector('.marquee');
        var btn = band && band.querySelector('.marquee__pause');
        if (!btn) return;
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                band.classList.toggle('is-offscreen', !entries[0].isIntersecting);
            }).observe(band);
        }
        btn.addEventListener('click', function () {
            var paused = btn.getAttribute('aria-pressed') !== 'true';
            btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
            btn.setAttribute('aria-label', paused ? 'Bewegende tekst afspelen' : 'Bewegende tekst pauzeren');
            band.classList.toggle('is-paused', paused);
        });
    }

    // ---------- Contactformulier ----------
    // Alleen voor een proeverij aan huis of een vraag: pakketten bestel je via het mandje.
    var TYPES = {
        proeverij: {
            submit: 'Aanvraag versturen',
            subject: 'Nieuwe aanvraag proeverij aan huis via cathdrwijnen',
            age: 'Ik en mijn gasten zijn 18 jaar of ouder'
        },
        vraag: {
            submit: 'Vraag versturen',
            subject: 'Nieuwe vraag via cathdrwijnen',
            age: 'Ik ben 18 jaar of ouder'
        }
    };

    function messageFor(field) {
        var v = field.validity;
        switch (field.name) {
            case 'naam': return 'Vul je naam in.';
            case 'email':
                return v.valueMissing
                    ? 'Vul je e-mailadres in, zodat Cath je kan antwoorden.'
                    : 'Dit e-mailadres klopt niet helemaal. Controleer het even.';
            case 'gasten': return 'Een proeverij aan huis is vanaf 4 personen.';
            case 'datum': return 'Kies een datum vanaf vandaag.';
            case '18plus':
                return field.closest('form').querySelector('input[name="onderwerp"]:checked').value === 'proeverij'
                    ? 'Bevestig dat jij en je gasten 18 jaar of ouder zijn.'
                    : 'Bevestig dat je 18 jaar of ouder bent.';
            default: return 'Controleer dit veld.';
        }
    }

    // aria-describedby kan meerdere id's bevatten; voeg er één toe of haal er één weg
    function describedBy(el, id, add) {
        var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        ids = ids.filter(function (x) { return x !== id; });
        if (add) ids.push(id);
        if (ids.length) el.setAttribute('aria-describedby', ids.join(' '));
        else el.removeAttribute('aria-describedby');
    }

    function todayISO() {
        var d = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function initForm() {
        var form = document.getElementById('orderForm');
        if (!form) return;
        form.noValidate = true;

        var done = document.getElementById('formDone');
        var errorBox = document.getElementById('formError');
        var submit = form.querySelector('[data-submit]');
        var subject = form.querySelector('[data-subject]');
        var ageLabel = form.querySelector('[data-age-label]');
        var radios = form.querySelectorAll('input[name="onderwerp"]');
        var conditional = form.querySelectorAll('[data-for]');
        var dateInput = document.getElementById('f-datum');
        var SENDING = 'Bezig met versturen…';

        if (dateInput) dateInput.min = todayISO();

        function currentType() {
            var checked = form.querySelector('input[name="onderwerp"]:checked');
            return checked ? checked.value : 'vraag';
        }

        function applyType() {
            var type = currentType();
            conditional.forEach(function (el) {
                var show = el.getAttribute('data-for').split(' ').indexOf(type) !== -1;
                el.hidden = !show;
                // Verborgen velden worden niet meegestuurd en niet gecontroleerd
                el.querySelectorAll('input, select, textarea').forEach(function (input) { input.disabled = !show; });
            });
            submit.textContent = TYPES[type].submit;
            subject.value = TYPES[type].subject;
            ageLabel.textContent = TYPES[type].age;
            clearErrors();
        }

        function setType(type) {
            var radio = form.querySelector('input[name="onderwerp"][value="' + type + '"]');
            if (radio) radio.checked = true;
            applyType();
        }

        radios.forEach(function (r) { r.addEventListener('change', applyType); });

        // Knoppen elders op de pagina ("Proeverij aanvragen")
        document.addEventListener('click', function (e) {
            var link = e.target.closest('[data-form-type]');
            if (!link) return;
            if (!done.hidden) showForm();
            setType(link.getAttribute('data-form-type'));
            var target = form.querySelector('input[name="onderwerp"]:checked');
            if (target) {
                // Wacht tot de pagina naar het formulier is gescrold
                window.setTimeout(function () { target.focus({ preventScroll: true }); }, 50);
            }
        });

        // Foutmelding direct onder het veld, gekoppeld via aria-describedby
        function errorAnchor(field) {
            return field.type === 'checkbox' ? field.closest('label') : field;
        }

        function showFieldError(field) {
            var id = (field.id || 'f-' + field.name) + '-fout';
            var msg = document.getElementById(id);
            if (!msg) {
                msg = document.createElement('p');
                msg.className = 'field__error';
                msg.id = id;
                errorAnchor(field).insertAdjacentElement('afterend', msg);
            }
            msg.textContent = messageFor(field);
            field.setAttribute('aria-invalid', 'true');
            describedBy(field, id, true);
        }

        function clearFieldError(field) {
            var id = (field.id || 'f-' + field.name) + '-fout';
            var msg = document.getElementById(id);
            if (msg) msg.remove();
            field.removeAttribute('aria-invalid');
            describedBy(field, id, false);
        }

        function clearErrors() {
            errorBox.hidden = true;
            errorBox.textContent = '';
            form.querySelectorAll('[aria-invalid="true"]').forEach(clearFieldError);
        }

        var FIELD_NAMES = {
            naam: 'je naam',
            email: 'je e-mailadres',
            gasten: 'het aantal gasten',
            datum: 'de datum',
            '18plus': 'de 18+ bevestiging'
        };

        function validate() {
            var invalid = [];
            form.querySelectorAll('input, select, textarea').forEach(function (f) {
                if (f.disabled || f.type === 'hidden' || f.name === '_gotcha') return;
                if (f.checkValidity()) {
                    clearFieldError(f);
                } else {
                    showFieldError(f);
                    invalid.push(f);
                }
            });
            return invalid;
        }

        function summarise(invalid) {
            var names = invalid.map(function (f) { return FIELD_NAMES[f.name] || 'een veld'; });
            var list = names.length > 1
                ? names.slice(0, -1).join(', ') + ' en ' + names[names.length - 1]
                : names[0];
            return (invalid.length === 1 ? 'Nog 1 ding invullen: ' : 'Nog ' + invalid.length + ' dingen invullen: ') + list + '.';
        }

        function showForm() {
            done.hidden = true;
            form.hidden = false;
        }

        // Fout weg zodra het veld klopt
        form.addEventListener('input', function (e) {
            if (e.target.getAttribute('aria-invalid') === 'true' && e.target.checkValidity()) clearFieldError(e.target);
        });
        form.addEventListener('change', function (e) {
            if (e.target.getAttribute('aria-invalid') === 'true' && e.target.checkValidity()) clearFieldError(e.target);
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (submit.disabled) return;
            errorBox.hidden = true;

            var invalid = validate();
            if (invalid.length) {
                errorBox.textContent = summarise(invalid);
                errorBox.hidden = false;
                invalid[0].focus();
                return;
            }

            var label = submit.textContent;
            submit.setAttribute('aria-busy', 'true');
            submit.disabled = true;
            submit.textContent = SENDING;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' }
            }).then(function (res) {
                if (!res.ok) throw new Error('status ' + res.status);
                form.reset();
                applyType();
                form.hidden = true;
                done.hidden = false;
                done.focus();
            }).catch(function () {
                errorBox.textContent = 'Versturen is niet gelukt. Controleer je internetverbinding en probeer het opnieuw, of stuur een DM via Instagram.';
                errorBox.hidden = false;
            }).then(function () {
                submit.removeAttribute('aria-busy');
                submit.disabled = false;
                if (submit.textContent === SENDING) submit.textContent = label;
                if (!errorBox.hidden) submit.focus();
            });
        });

        done.querySelector('[data-form-reset]').addEventListener('click', function () {
            showForm();
            document.getElementById('f-naam').focus();
        });

        applyType();
    }

    // ---------- Mandje: gedeelde hulpjes ----------
    // Pakketten, prijzen en bezorgkosten komen uit pakketten.json (via js/winkel.js).
    // De bedragen in de html zijn alleen een terugval voor als dat bestand niet laadt.

    function packagesText(n) {
        return n === 1 ? '1 pakket' : n + ' pakketten';
    }

    // Rustig bedrag voor in de tekst: "€ 49" voor hele euro's, anders "€ 49,50"
    function shortPrice(centen) {
        return window.Winkel.euro(centen).replace(/,00$/, '');
    }

    // "Bezorgen kost € 6,95, vanaf € 100 is het gratis. Ophalen is gratis."
    function deliveryText() {
        var winkel = window.Winkel;
        var bezorgen = winkel.levering('bezorgen');
        var ophalen = winkel.levering('ophalen');
        var tekst = '';
        if (bezorgen) {
            tekst = bezorgen.kosten ? 'Bezorgen kost ' + winkel.euro(bezorgen.kosten) : 'Bezorgen is gratis';
            if (bezorgen.kosten && bezorgen.gratisVanaf) tekst += ', vanaf ' + shortPrice(bezorgen.gratisVanaf) + ' is het gratis';
            tekst += '.';
        }
        if (ophalen) {
            tekst += (tekst ? ' ' : '') + (ophalen.kosten ? 'Ophalen kost ' + winkel.euro(ophalen.kosten) + '.' : 'Ophalen is gratis.');
        }
        return tekst;
    }

    // Meldingen voor schermlezers. Staat het mandje-paneel open, dan moet de melding
    // daarbinnen staan: de rest van de pagina is dan even onbereikbaar.
    var announceTimer = null;
    function announce(text) {
        var panel = document.getElementById('cartPanel');
        var region = panel && panel.open
            ? panel.querySelector('[data-cart-status-panel]')
            : document.querySelector('[data-cart-status]');
        if (!region) return;
        // Eerst leegmaken, dan vullen: zo wordt dezelfde zin ook een tweede keer voorgelezen
        region.textContent = '';
        window.clearTimeout(announceTimer);
        announceTimer = window.setTimeout(function () { region.textContent = text; }, 80);
    }

    // ---------- Mandje: prijzen, teller en de knoppen op de pakketten ----------
    function initShop() {
        var winkel = window.Winkel;
        if (!winkel || !winkel.ready) return;

        var loadFailed = false;
        var labelTimers = {};

        // Prijzen en bezorgkosten op de pagina gelijk zetten met pakketten.json
        function showPrices() {
            document.querySelectorAll('[data-prijs-voor]').forEach(function (el) {
                var p = winkel.pakket(el.getAttribute('data-prijs-voor'));
                if (p) el.textContent = shortPrice(p.prijs);
            });
            var delivery = deliveryText();
            if (delivery) {
                document.querySelectorAll('[data-levering-tekst]').forEach(function (el) { el.textContent = delivery; });
            }
        }

        // Teller in de header, en een klasse op <html> zodat de css weet dat er iets in zit
        function showCount() {
            var n = winkel.aantal();
            document.querySelectorAll('[data-cart-count]').forEach(function (el) {
                el.textContent = String(n);
                el.hidden = n === 0;
            });
            document.querySelectorAll('[data-cart-open]').forEach(function (btn) {
                btn.setAttribute('aria-label', n ? 'Mandje, ' + packagesText(n) : 'Mandje, leeg');
            });
            document.documentElement.classList.toggle('has-cart-items', n > 0);
        }

        // Onder de knop op elke pakketkaart: hoeveel je er al van in je mandje hebt
        function cardLine(btn) {
            var line = btn.parentNode.querySelector('.package__in-cart');
            if (!line) {
                line = document.createElement('p');
                line.className = 'package__in-cart';
                line.hidden = true;
                btn.insertAdjacentElement('afterend', line);
            }
            return line;
        }

        function showOnCards() {
            var items = winkel.inhoud();
            document.querySelectorAll('[data-add]').forEach(function (btn) {
                var line = cardLine(btn);
                var n = items[btn.getAttribute('data-add')] || 0;
                line.classList.remove('is-error');
                line.textContent = n >= winkel.max()
                    ? 'In je mandje: ' + n + '. Meer kan niet in één bestelling.'
                    : 'In je mandje: ' + n;
                line.hidden = n === 0;
            });
        }

        // Knoptekst even vervangen ("Toegevoegd ✓") en daarna terugzetten
        function flashLabel(btn, text, className) {
            var id = btn.getAttribute('data-add');
            var label = btn.querySelector('[data-add-label]');
            if (!label) return;
            if (!btn.hasAttribute('data-add-tekst')) btn.setAttribute('data-add-tekst', label.textContent);
            window.clearTimeout(labelTimers[id]);
            label.textContent = text;
            btn.classList.remove('is-added', 'is-full');
            btn.classList.add(className);
            labelTimers[id] = window.setTimeout(function () {
                label.textContent = btn.getAttribute('data-add-tekst');
                btn.classList.remove('is-added', 'is-full');
            }, 2200);
        }

        // De teller in de header wipt even op
        function bumpCount() {
            document.querySelectorAll('[data-cart-count]').forEach(function (el) {
                el.classList.remove('is-bumped');
                el.getBoundingClientRect(); // animatie opnieuw laten beginnen
                el.classList.add('is-bumped');
            });
        }

        function showLoadError(btn) {
            var line = cardLine(btn);
            line.textContent = 'Het mandje werkt nu even niet. Ververs de pagina of probeer het later nog eens.';
            line.classList.add('is-error');
            line.hidden = false;
            announce(line.textContent);
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-add]');
            if (!btn) return;
            var id = btn.getAttribute('data-add');
            winkel.ready.then(function () {
                var p = winkel.pakket(id);
                if (!p) return;
                var before = winkel.inhoud()[id] || 0;
                if (before >= winkel.max()) {
                    flashLabel(btn, 'Maximum bereikt', 'is-full');
                    announce('Je hebt al ' + before + ' keer ' + p.naam + ' in je mandje. Meer kan niet in één bestelling.');
                    return;
                }
                winkel.voegToe(id, 1);
                flashLabel(btn, 'Toegevoegd ✓', 'is-added');
                bumpCount();
                announce(p.naam + ' zit in je mandje. In je mandje: ' + packagesText(winkel.aantal()) + '.');
            }, function () {
                showLoadError(btn);
            });
        });

        function render() {
            if (loadFailed) return;
            showPrices();
            showCount();
            showOnCards();
        }

        document.addEventListener('winkel:change', render);
        winkel.ready.catch(function () { loadFailed = true; });
        render();
    }

    // ---------- Mandje-paneel ----------
    // Een <dialog> die met showModal() opent: de rest van de pagina is dan niet
    // bereikbaar, Escape sluit, en de focus gaat terug naar de knop waarmee je hem opende.
    function initCartPanel() {
        var winkel = window.Winkel;
        var dialog = document.getElementById('cartPanel');
        var template = document.getElementById('cartLineTemplate');
        var openers = document.querySelectorAll('[data-cart-open]');
        if (!winkel || !dialog || !template) return;

        // Heel oude browser zonder <dialog>: de mandjeknop gaat dan naar de afrekenpagina,
        // daar staat het mandje ook
        if (typeof dialog.showModal !== 'function') {
            openers.forEach(function (btn) {
                btn.addEventListener('click', function () { window.location.href = 'afrekenen.html'; });
            });
            return;
        }

        var title = document.getElementById('cartTitle');
        var list = dialog.querySelector('[data-cart-lines]');
        var empty = dialog.querySelector('[data-cart-empty]');
        var errorBox = dialog.querySelector('[data-cart-error]');
        var foot = dialog.querySelector('[data-cart-foot]');
        var summary = dialog.querySelector('[data-cart-summary]');
        var subtotal = dialog.querySelector('[data-cart-subtotal]');
        var shipping = dialog.querySelector('[data-cart-shipping]');
        var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
        var CLOSE_MS = 320; // gelijk aan de transition in css/salon-v2.css
        var opener = null;
        var returnFocus = true;
        var jumpTarget = null;
        var closeTimer = null;
        var loadFailed = false;

        function shippingText() {
            var bezorgen = winkel.levering('bezorgen');
            if (!bezorgen) return 'Verzendkosten zie je bij afrekenen.';
            if (bezorgen.kosten && bezorgen.gratisVanaf && winkel.subtotaal() >= bezorgen.gratisVanaf) {
                return 'Bezorgen is gratis bij deze bestelling. Bezorgen of ophalen kies je bij het afrekenen.';
            }
            return deliveryText() + ' Je kiest bij het afrekenen.';
        }

        function setDisabled(btn, off) {
            // aria-disabled in plaats van disabled: dan blijft de focus op de knop staan
            if (off) btn.setAttribute('aria-disabled', 'true');
            else btn.removeAttribute('aria-disabled');
        }

        function fillLine(li, r) {
            var max = winkel.max();
            li.querySelector('[data-line-name]').textContent = r.naam;
            li.querySelector('[data-line-total]').textContent = winkel.euro(r.subtotaal);
            li.querySelector('[data-line-meta]').textContent =
                (r.flessen === 1 ? '1 fles' : r.flessen + ' flessen') + ' · ' + winkel.euro(r.prijs) + ' per stuk';
            li.querySelector('[data-line-count]').textContent = String(r.aantal);
            li.querySelector('[data-line-group]').setAttribute('aria-label', 'Aantal van ' + r.naam);
            li.querySelector('[data-line-remove-name]').textContent = ': ' + r.naam;

            var less = li.querySelector('[data-step="-1"]');
            var more = li.querySelector('[data-step="1"]');
            less.setAttribute('aria-label', 'Eén minder van ' + r.naam);
            more.setAttribute('aria-label', 'Eén meer van ' + r.naam);
            setDisabled(less, r.aantal <= 1);
            setDisabled(more, r.aantal >= max);

            var maxNote = li.querySelector('[data-line-max]');
            maxNote.textContent = 'Meer dan ' + max + ' nodig? Vraag het Cath via het contactformulier.';
            maxNote.hidden = r.aantal < max;
        }

        // Regels bijwerken in plaats van opnieuw tekenen, zodat de focus op de
        // − of + knop blijft staan terwijl je het aantal aanpast
        function render() {
            var lines = loadFailed ? [] : winkel.regels();
            var existing = {};
            list.querySelectorAll('[data-regel]').forEach(function (li) {
                existing[li.getAttribute('data-regel')] = li;
            });
            lines.forEach(function (r, i) {
                var li = existing[r.id];
                if (!li) {
                    li = template.content.firstElementChild.cloneNode(true);
                    li.setAttribute('data-regel', r.id);
                }
                delete existing[r.id];
                fillLine(li, r);
                if (list.children[i] !== li) list.insertBefore(li, list.children[i] || null);
            });
            Object.keys(existing).forEach(function (id) { list.removeChild(existing[id]); });

            var n = loadFailed ? 0 : winkel.aantal();
            list.hidden = n === 0;
            empty.hidden = n > 0 || loadFailed;
            errorBox.hidden = !loadFailed;
            foot.hidden = n === 0;
            dialog.classList.toggle('is-empty', n === 0);
            summary.textContent = n ? packagesText(n) : '';
            subtotal.textContent = winkel.euro(winkel.subtotaal());
            shipping.textContent = shippingText();
        }

        // Pagina achter het paneel niet laten meescrollen; de ruimte van de
        // scrollbalk opvullen zodat er niets verspringt
        function lockScroll(lock) {
            var root = document.documentElement;
            if (lock) {
                var bar = window.innerWidth - root.clientWidth;
                if (bar > 0) root.style.paddingRight = bar + 'px';
                root.classList.add('cart-is-open');
            } else {
                root.classList.remove('cart-is-open');
                root.style.paddingRight = '';
            }
        }

        function open(from) {
            if (dialog.open) return;
            window.clearTimeout(closeTimer);
            opener = from || document.activeElement;
            returnFocus = true;
            jumpTarget = null;
            render();
            lockScroll(true);
            dialog.classList.remove('is-closing');
            dialog.showModal();
            title.focus({ preventScroll: true });
            dialog.getBoundingClientRect(); // eerst de beginstand tekenen, dan inschuiven
            dialog.classList.add('is-open');
        }

        function close(options) {
            if (!dialog.open || dialog.classList.contains('is-closing')) return;
            if (options && options.returnFocus === false) returnFocus = false;
            lockScroll(false);
            dialog.classList.remove('is-open');
            if (reduceMotion && reduceMotion.matches) {
                dialog.close();
                return;
            }
            dialog.classList.add('is-closing');
            closeTimer = window.setTimeout(function () { dialog.close(); }, CLOSE_MS);
        }

        // Ook als de browser de dialog zelf sluit komt alles hier terecht
        dialog.addEventListener('close', function () {
            window.clearTimeout(closeTimer);
            dialog.classList.remove('is-open');
            dialog.classList.remove('is-closing');
            lockScroll(false);
            if (returnFocus && opener && document.body.contains(opener)) {
                opener.focus({ preventScroll: true });
            } else if (jumpTarget) {
                // Na een link als "Bekijk de pakketten": verder met de toets Tab vanaf
                // die sectie, niet vanaf de mandjeknop bovenin
                var heading = jumpTarget.querySelector('h2') || jumpTarget;
                if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
            opener = null;
            jumpTarget = null;
        });

        // Escape: dicht met dezelfde animatie
        dialog.addEventListener('cancel', function (e) {
            e.preventDefault();
            close();
        });

        dialog.addEventListener('click', function (e) {
            // Klik op de donkere achtergrond naast het paneel
            if (e.target === dialog) {
                var box = dialog.getBoundingClientRect();
                if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) close();
                return;
            }

            var line = e.target.closest('[data-regel]');
            var step = e.target.closest('[data-step]');
            if (line && step) {
                if (step.getAttribute('aria-disabled') === 'true') return;
                var id = line.getAttribute('data-regel');
                var n = (winkel.inhoud()[id] || 0) + Number(step.getAttribute('data-step'));
                winkel.zet(id, n);
                announce(winkel.pakket(id).naam + ': ' + n + '. Subtotaal ' + winkel.euro(winkel.subtotaal()) + '.');
                return;
            }
            if (line && e.target.closest('[data-remove]')) {
                var name = winkel.pakket(line.getAttribute('data-regel')).naam;
                winkel.haalWeg(line.getAttribute('data-regel'));
                // De knop is weg; de focus gaat naar de kop van het paneel
                title.focus();
                announce(name + ' is uit je mandje gehaald.' + (winkel.aantal() ? '' : ' Je mandje is nu leeg.'));
                return;
            }

            if (e.target.closest('[data-cart-close]')) {
                close();
                return;
            }
            // Link naar een plek op deze pagina ("Bekijk de pakketten"): paneel dicht,
            // de pagina scrolt er zelf heen
            var link = e.target.closest('a[href^="#"]');
            if (link) {
                jumpTarget = document.getElementById(link.getAttribute('href').slice(1));
                close({ returnFocus: false });
            }
        });

        openers.forEach(function (btn) {
            btn.addEventListener('click', function () { open(btn); });
        });

        document.addEventListener('winkel:change', render);
        winkel.ready.catch(function () {
            loadFailed = true;
            render();
        });
        render();
    }

    function initYear() {
        var el = document.querySelector('[data-year]');
        if (el) el.textContent = String(new Date().getFullYear());
    }

    function init() {
        initNav();
        initMarquee();
        initShop();
        initCartPanel();
        initForm();
        initYear();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
