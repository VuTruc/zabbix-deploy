(function () {
    'use strict';

    function initTacacsPolicyOrder() {
        const form = document.querySelector(
            'form[name="tacacs_rule_form"]'
        );

        if (!form) return;

        const policySelect = form.querySelector('select[name="id"]');
        const orderInput = form.querySelector('[name="rule_order"]');

        if (!policySelect || !orderInput) return;

        // Không tự thay đổi Order khi sửa Rule.
        if (form.querySelector('[name="rule_id"]')) return;

        let rules = [];

        try {
            rules = JSON.parse(form.dataset.rules || '[]');
        }
        catch (error) {
            console.error('TACACS: Invalid rules JSON', error);
            return;
        }

        function updateOrder() {
            const policyId = Number(policySelect.value || 0);
            let maxOrder = 0;

            for (const rule of rules) {
                if (Number(rule.policy_id) === policyId) {
                    maxOrder = Math.max(
                        maxOrder,
                        Number(rule.rule_order) || 0
                    );
                }
            }

            orderInput.value = maxOrder > 0 ? maxOrder + 10 : 10;
        }

        policySelect.addEventListener('change', updateOrder);
        updateOrder();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initTacacsPolicyOrder
        );
    }
    else {
        initTacacsPolicyOrder();
    }
})();
