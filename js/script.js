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

// Carousel dot indicators for horizontal scroll carousels on mobile/tablet.
// Active dot tracks whichever card's center is closest to the container's
// center — works for both start-snap and center-snap configurations.
(function () {
    var SELECTOR = '.features-grid, .packages-grid, .how-grid, .polaroid-row';

    function init() {
        var carousels = document.querySelectorAll(SELECTOR);
        Array.prototype.forEach.call(carousels, setupDots);
    }

    function setupDots(container) {
        var cards = [];
        Array.prototype.forEach.call(container.children, function (el) {
            if (el.nodeType === 1) cards.push(el);
        });
        if (cards.length < 2) return;

        var dotsEl = document.createElement('div');
        dotsEl.className = 'carousel-dots';
        dotsEl.setAttribute('role', 'tablist');

        var dots = [];
        cards.forEach(function (card, i) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'carousel-dot';
            dot.setAttribute('role', 'tab');
            dot.setAttribute('aria-label', 'ga naar kaart ' + (i + 1));
            dot.addEventListener('click', function () {
                card.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
            dotsEl.appendChild(dot);
            dots.push(dot);
        });

        container.parentNode.insertBefore(dotsEl, container.nextSibling);

        var activeIndex = 0;
        dots[0].classList.add('is-active');

        var rafId = null;
        function update() {
            rafId = null;
            var containerRect = container.getBoundingClientRect();
            var centerX = containerRect.left + containerRect.width / 2;
            var bestIdx = 0;
            var bestDist = Infinity;
            for (var i = 0; i < cards.length; i++) {
                var r = cards[i].getBoundingClientRect();
                var cx = r.left + r.width / 2;
                var d = Math.abs(cx - centerX);
                if (d < bestDist) {
                    bestDist = d;
                    bestIdx = i;
                }
            }
            if (bestIdx !== activeIndex) {
                dots[activeIndex].classList.remove('is-active');
                activeIndex = bestIdx;
                dots[activeIndex].classList.add('is-active');
            }
        }

        function schedule() {
            if (rafId !== null) return;
            rafId = requestAnimationFrame(update);
        }

        container.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

