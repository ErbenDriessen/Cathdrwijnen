// Force light color-scheme at runtime — prevents Firefox auto dark mode
// and keeps colour-scheme stable against extensions that toggle it.
(function () {
    try {
        var setLight = function () {
            document.documentElement.style.colorScheme = 'only light';
            document.documentElement.setAttribute('data-color-scheme', 'light');
            if (document.body) {
                document.body.style.colorScheme = 'only light';
            }
        };
        setLight();
        // Re-assert after DOM ready in case an extension changed it
        document.addEventListener('DOMContentLoaded', setLight);
        window.addEventListener('load', setLight);
    } catch (e) {}
})();

// Mobile navigation toggle (hamburger)
(function () {
    function init() {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.getElementById('siteNav');
        if (!toggle || !nav) return;

        function setOpen(isOpen) {
            nav.dataset.open = isOpen ? 'true' : 'false';
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'menu sluiten' : 'menu openen');
            document.body.classList.toggle('nav-open', isOpen);
        }

        toggle.addEventListener('click', function () {
            setOpen(nav.dataset.open !== 'true');
        });

        // Close when any nav link is tapped
        Array.prototype.forEach.call(nav.querySelectorAll('a'), function (a) {
            a.addEventListener('click', function () {
                setOpen(false);
            });
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.dataset.open === 'true') {
                setOpen(false);
            }
        });

        // Close if viewport grows past the mobile breakpoint
        window.matchMedia('(min-width: 1025px)').addEventListener('change', function (e) {
            if (e.matches) setOpen(false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

