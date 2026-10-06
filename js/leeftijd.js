// cathdrwijnen · 18+ vraag
// Eén vraag voor de hele site: salon-v2.html en afrekenen.html laden allebei dit
// script. Het antwoord staat in localStorage ('cathdrwijnen-18plus' = 'ja'), dus
// wie op de salonpagina al "ja" heeft gezegd, krijgt de vraag bij het afrekenen
// niet nog een keer.
// De opmaak van de vraag staat hieronder (MARKUP); dit script zet hem zelf in de
// pagina. Staat er al een <dialog id="ageGate"> in de pagina, dan wordt die
// gebruikt. De vormgeving komt uit css/salon-v2.css.
// Handig bij het testen: salon-v2.html?leeftijd-opnieuw of afrekenen.html?leeftijd-opnieuw

(function () {
    'use strict';

    var AGE_KEY = 'cathdrwijnen-18plus';

    // ---------- Opslag (kan geblokkeerd zijn in privévensters) ----------
    var store = {
        get: function (key) {
            try { return window.localStorage.getItem(key); } catch (e) {}
            try { return window.sessionStorage.getItem(key); } catch (e) {}
            return null;
        },
        set: function (key, value) {
            try { window.localStorage.setItem(key, value); return; } catch (e) {}
            try { window.sessionStorage.setItem(key, value); } catch (e) {}
        },
        remove: function (key) {
            try { window.localStorage.removeItem(key); } catch (e) {}
            try { window.sessionStorage.removeItem(key); } catch (e) {}
        }
    };

    // De vraag zelf; teksten aanpassen doe je hier
    var MARKUP =
        '<dialog class="age-gate" id="ageGate" closedby="none" aria-labelledby="ageGateTitle" aria-describedby="ageGateText">' +
            '<div class="age-gate__ask" data-age-step="ask">' +
                '<img class="age-gate__logo" src="assets/v2/logo-160.webp" width="88" height="88" alt="">' +
                '<h2 class="age-gate__title" id="ageGateTitle">Ben je 18 jaar of ouder?</h2>' +
                '<p class="age-gate__text" id="ageGateText">Op deze site kun je wijn bestellen. Alcohol verkopen we alleen aan mensen van 18 jaar en ouder.</p>' +
                '<div class="age-gate__actions">' +
                    '<button type="button" class="btn btn--wine" data-age-answer="yes">Ja, ik ben 18+</button>' +
                    '<button type="button" class="btn btn--ghost" data-age-answer="no">Nee</button>' +
                '</div>' +
                '<p class="age-gate__nix"><img class="nix18-logo" src="assets/v2/nix18-oranje.png" width="96" height="26" alt="NIX18"><span>Geen 18, geen alcohol.</span></p>' +
            '</div>' +
            '<div class="age-gate__denied" data-age-step="denied" hidden>' +
                '<img class="age-gate__logo" src="assets/v2/logo-160.webp" width="88" height="88" alt="">' +
                '<h2 class="age-gate__title" tabindex="-1">Kom terug als je 18 bent</h2>' +
                '<p class="age-gate__text">Tot die tijd kun je hier niets bestellen. Waarom dat zo is, lees je op <a href="https://www.nix18.nl" rel="noopener">nix18.nl</a>.</p>' +
                '<div class="age-gate__actions">' +
                    '<button type="button" class="btn btn--ghost" data-age-back>Toch 18+? Terug naar de vraag</button>' +
                '</div>' +
                '<p class="age-gate__nix"><img class="nix18-logo" src="assets/v2/nix18-oranje.png" width="96" height="26" alt="NIX18"><span>Geen 18, geen alcohol.</span></p>' +
            '</div>' +
        '</dialog>';

    // Gebruik de dialog uit de pagina, of zet hem er bovenaan in
    function vindOfMaakDialog() {
        var dialog = document.getElementById('ageGate');
        if (dialog) return dialog;
        var houder = document.createElement('div');
        houder.innerHTML = MARKUP;
        dialog = houder.firstElementChild;
        var skip = document.querySelector('.skip-link');
        if (skip) skip.insertAdjacentElement('afterend', dialog);
        else document.body.insertBefore(dialog, document.body.firstChild);
        return dialog;
    }

    function initAgeGate() {
        // Oude browsers zonder <dialog>: geen vraag
        if (typeof document.createElement('dialog').showModal !== 'function') return;

        if (/[?&]leeftijd-opnieuw\b/.test(window.location.search)) store.remove(AGE_KEY);
        if (store.get(AGE_KEY) === 'ja') return;

        var dialog = vindOfMaakDialog();
        var ask = dialog.querySelector('[data-age-step="ask"]');
        var denied = dialog.querySelector('[data-age-step="denied"]');
        var answeredYes = false;
        var root = document.documentElement;
        var rustig = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Na "ja": de kaart zakt weg en het scherm trekt op. Daarna haalt het
        // weghalen van 'leeftijd-open' de vlek en de tekst van de hero terug, met
        // de overgangen uit css/salon-v2.css (de vlek vloeit uit).
        function welkom() {
            if (rustig) {
                dialog.close();
                root.classList.remove('leeftijd-open');
                return;
            }
            dialog.classList.add('is-sluiten');
            window.setTimeout(function () {
                dialog.close();
                dialog.classList.remove('is-sluiten');
                root.classList.remove('leeftijd-open');
            }, 320);
        }

        // Escape mag de vraag niet wegklikken. closedby="none" regelt dat in
        // nieuwe browsers; Chrome laat een eerste Escape soms toch door, dus
        // openen we de vraag opnieuw als hij zonder "ja" dichtgaat.
        dialog.addEventListener('cancel', function (e) { e.preventDefault(); });
        dialog.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') e.preventDefault();
        });
        dialog.addEventListener('close', function () {
            if (!answeredYes) dialog.showModal();
        });

        dialog.addEventListener('click', function (e) {
            if (e.target.closest('[data-age-back]')) {
                denied.hidden = true;
                ask.hidden = false;
                ask.querySelector('[data-age-answer="yes"]').focus();
                return;
            }
            var btn = e.target.closest('[data-age-answer]');
            if (!btn) return;
            if (btn.getAttribute('data-age-answer') === 'yes') {
                if (answeredYes) return;
                answeredYes = true;
                store.set(AGE_KEY, 'ja');
                welkom();
            } else {
                ask.hidden = true;
                denied.hidden = false;
                denied.querySelector('h2').focus();
            }
        });

        root.classList.add('leeftijd-open');
        dialog.showModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAgeGate);
    } else {
        initAgeGate();
    }
})();
