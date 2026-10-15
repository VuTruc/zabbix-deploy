(function () {
    'use strict';

    function initTacacsAssignmentPasswordModal() {
        const modal = document.getElementById('tacacs-assignment-reset-modal');
        const openButton = document.querySelector('.tacacs-reset-open');

        if (!modal || !openButton) return;

        const closeButton = modal.querySelector('.tacacs-reset-close');
        const recoveryInput = modal.querySelector('input[name="recovery_code"]');

        function openModal() {
            modal.hidden = false;
            modal.style.display = 'flex';
            document.body.classList.add('tacacs-modal-open');
            if (recoveryInput) recoveryInput.focus();
        }

        function closeModal() {
            modal.hidden = true;
            modal.style.display = 'none';
            document.body.classList.remove('tacacs-modal-open');
            openButton.focus();
        }

        openButton.addEventListener('click', openModal);

        if (closeButton) {
            closeButton.addEventListener('click', closeModal);
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });

        modal.hidden = true;
        modal.style.display = 'none';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTacacsAssignmentPasswordModal);
    }
    else {
        initTacacsAssignmentPasswordModal();
    }
})();

/* BEGIN TACACS liquid glass viewport bounds v1 */
(function tacacsLiquidGlassViewportBounds() {
    'use strict';

    function initTacacsLiquidGlassViewport() {
        var overlay = document.querySelector('.tacacs-assignment-lock-screen');

        if (!overlay || overlay.dataset.tacacsLiquidGlassBound === '1') {
            return;
        }

        var main = overlay.closest
            ? overlay.closest('main')
            : null;

        main = main
            || document.querySelector('main')
            || document.querySelector('#content')
            || document.querySelector('.content-container');

        if (!main || !document.body) {
            // CSS fallback still fills the available Assignments content area.
            return;
        }

        overlay.dataset.tacacsLiquidGlassBound = '1';
        overlay.classList.add('tacacs-liquid-glass-viewport-bound');

        // Move only the overlay to body; password form handlers remain attached.
        document.body.appendChild(overlay);

        function updateBounds() {
            var rect = main.getBoundingClientRect();
            var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
            var viewportHeight = document.documentElement.clientHeight || window.innerHeight;

            var left = Math.max(0, Math.min(rect.left, viewportWidth));
            var right = Math.max(left, Math.min(rect.right, viewportWidth));
            var top = Math.max(0, Math.min(rect.top, viewportHeight));

            overlay.style.left = left + 'px';
            overlay.style.top = top + 'px';
            overlay.style.width = Math.max(0, right - left) + 'px';
            // Extend from the content top to the bottom of the visible viewport.
            overlay.style.height = Math.max(0, viewportHeight - top) + 'px';
        }

        updateBounds();

        window.addEventListener('resize', updateBounds, {passive: true});
        window.addEventListener('scroll', updateBounds, {passive: true, capture: true});

        if (typeof window.ResizeObserver === 'function') {
            var observer = new window.ResizeObserver(updateBounds);
            observer.observe(main);
            overlay.__tacacsLiquidGlassObserver = observer;
        }

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateBounds, {passive: true});
            window.visualViewport.addEventListener('scroll', updateBounds, {passive: true});
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initTacacsLiquidGlassViewport,
            {once: true}
        );
    }
    else {
        initTacacsLiquidGlassViewport();
    }
})();
/* END TACACS liquid glass viewport bounds v1 */

/* BEGIN TACACS inline reset toggle v1 */
(function tacacsInlineResetToggle() {
    'use strict';

    if (document.documentElement.dataset.tacacsInlineResetBound === '1') {
        return;
    }
    document.documentElement.dataset.tacacsInlineResetBound = '1';

    document.addEventListener('click', function(event) {
        var target = event.target;
        var button = target && target.closest
            ? target.closest('.tacacs-reset-toggle')
            : null;

        if (!button) {
            return;
        }

        var section = document.getElementById('tacacs-assignment-reset-inline');
        if (!section) {
            return;
        }

        // Reset is a toggle, not a submit of the surrounding Unlock form.
        event.preventDefault();

        var isHidden = section.hasAttribute('hidden');

        if (isHidden) {
            section.removeAttribute('hidden');
            button.setAttribute('aria-expanded', 'true');

            if (typeof section.scrollIntoView === 'function') {
                section.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }
        }
        else {
            section.setAttribute('hidden', 'hidden');
            button.setAttribute('aria-expanded', 'false');
        }
    }, true);
})();
/* END TACACS inline reset toggle v1 */

/* BEGIN TACACS reset form closes on reload v1 */
(function tacacsResetFormVisibility() {
    'use strict';

    function syncResetPanel() {
        var details = document.getElementById('tacacs-assignment-reset-details');
        var panel = document.getElementById('tacacs-assignment-reset-inline');

        if (!panel) {
            return;
        }

        if (details && details.open) {
            panel.removeAttribute('hidden');
        }
        else {
            panel.setAttribute('hidden', 'hidden');
        }
    }

    function initializeResetPanel() {
        var details = document.getElementById('tacacs-assignment-reset-details');

        if (details) {
            /* Always begin closed, including after browser page restoration. */
            details.removeAttribute('open');
            details.addEventListener('toggle', syncResetPanel);
        }

        syncResetPanel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeResetPanel, {once: true});
    }
    else {
        initializeResetPanel();
    }

    window.addEventListener('pageshow', function() {
        var details = document.getElementById('tacacs-assignment-reset-details');

        if (details) {
            details.removeAttribute('open');
        }

        syncResetPanel();
    });
})();
/* END TACACS reset form closes on reload v1 */

/* BEGIN TACACS separate access mode v2 */
(function tacacsAssignmentAccessMode() {
    'use strict';

    function initAccessMode() {
        var form = document.forms.namedItem('tacacs_assignments_form');
        if (!form) return;

        var modes = form.querySelectorAll('input[type="radio"][name="access_mode"]');
        var policy = form.querySelector('select[name="policy_id"]');
        var panel = form.querySelector('#tacacs-assignment-policy-selection');

        if (!modes.length || !policy || !panel) return;

        function syncPolicyVisibility() {
            var selected = form.querySelector('input[name="access_mode"]:checked');
            var showPolicy = !!selected && selected.value === 'policy';

            panel.hidden = !showPolicy;
            policy.required = showPolicy;

            if (!showPolicy) {
                policy.value = '';
            }

            modes.forEach(function (radio) {
                var card = radio.closest('.tacacs-assignment-mode-option');
                if (card) card.classList.toggle('is-selected', radio.checked);
            });
        }

        modes.forEach(function (radio) {
            radio.addEventListener('change', syncPolicyVisibility);
        });

        syncPolicyVisibility();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccessMode, {once: true});
    }
    else {
        initAccessMode();
    }
})();
/* END TACACS separate access mode v1 */

/* BEGIN TACACS assignment visibility hardening v1 */
(function tacacsAssignmentVisibilityHardening() {
    'use strict';

    var radioSelector = 'input[type="radio"][name="access_mode"]';

    function sync(form) {
        var radios = form.querySelectorAll(radioSelector);
        if (!radios.length) return;

        var selected = form.querySelector(radioSelector + ':checked');
        var panel = form.querySelector('#tacacs-assignment-policy-selection')
            || document.getElementById('tacacs-assignment-policy-selection');
        var policy = form.querySelector('select[name="policy_id"]')
            || document.querySelector('select[name="policy_id"]');

        if (!panel || !policy) return;

        var showPolicy = !!selected && selected.value === 'policy';

        if (showPolicy) {
            panel.hidden = false;
            panel.removeAttribute('hidden');
        }
        else {
            panel.hidden = true;
            panel.setAttribute('hidden', 'hidden');
            policy.value = '';
        }

        policy.required = showPolicy;

        radios.forEach(function (radio) {
            var card = radio.closest('.tacacs-assignment-mode-option');
            if (card) card.classList.toggle('is-selected', radio.checked);
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches(radioSelector)) return;
        sync(target.closest('form') || document);
    }, true);

    function initialize() {
        var radios = document.querySelectorAll(radioSelector);
        radios.forEach(function (radio) {
            sync(radio.closest('form') || document);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    }
    else {
        initialize();
    }
})();
/* END TACACS assignment visibility hardening v1 */

/* BEGIN TACACS policy panel visibility fix v1 */
(function tacacsPolicyPanelVisibilityFix() {
    'use strict';

    var radioSelector = 'input[type="radio"][name="access_mode"]';

    function syncPolicyPanel() {
        var panel = document.getElementById(
            'tacacs-assignment-policy-selection'
        );
        var policy = document.querySelector('select[name="policy_id"]');
        var selected = document.querySelector(
            radioSelector + ':checked'
        );

        if (!panel || !policy) return;

        var showPolicy = !!selected && selected.value === 'policy';

        if (showPolicy) {
            panel.hidden = false;
            panel.removeAttribute('hidden');
            panel.style.setProperty('display', 'block', 'important');
        }
        else {
            panel.hidden = true;
            panel.setAttribute('hidden', 'hidden');
            panel.style.setProperty('display', 'none', 'important');
            policy.value = '';
        }

        policy.required = showPolicy;

        document.querySelectorAll(radioSelector).forEach(function (radio) {
            var card = radio.closest('.tacacs-assignment-mode-option');
            if (card) {
                card.classList.toggle('is-selected', radio.checked);
            }
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches(radioSelector)) {
            syncPolicyPanel();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded', syncPolicyPanel, {once: true}
        );
    }
    else {
        syncPolicyPanel();
    }
})();
/* END TACACS policy panel visibility fix v1 */

/* BEGIN TACACS vendor access level v1 */
(function tacacsVendorAccessLevel() {
    'use strict';

    function initAccessLevel() {
        var form = document.forms.namedItem('tacacs_assignments_form');
        if (!form) return;

        var device = form.querySelector('z-select[name="device_id"]');
        var placeholder = form.querySelector('[data-access-level-placeholder]');
        var panels = form.querySelectorAll('[data-access-level-vendor]');
        var vendors = {};

        try {
            vendors = JSON.parse(form.getAttribute('data-device-vendors') || '{}');
        }
        catch (e) {
            vendors = {};
        }

        if (!device || !panels.length) return;

        // Show only the selected device's vendor levels. Disabled selects are
        // not submitted, so the form posts exactly one access_level.
        function syncAccessLevel() {
            var vendor = vendors[String(device.value)] || '';

            panels.forEach(function (panel) {
                var active = panel.getAttribute('data-access-level-vendor') === vendor;
                var select = panel.querySelector('z-select');

                panel.hidden = !active;
                if (select) select.disabled = !active;
            });

            if (placeholder) placeholder.hidden = vendor !== '';
        }

        device.addEventListener('change', syncAccessLevel);
        syncAccessLevel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccessLevel, {once: true});
    }
    else {
        initAccessLevel();
    }
})();
/* END TACACS vendor access level v1 */
