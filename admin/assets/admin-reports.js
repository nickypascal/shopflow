(() => {
    'use strict';

    const pad = (value) => String(value).padStart(2, '0');
    const formatDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const startOfMonth = (date) => new Date(date.getFullYear(), date.getMonth(), 1);
    const endOfMonth = (date) => new Date(date.getFullYear(), date.getMonth() + 1, 0);

    document.querySelectorAll('[data-report-filter]').forEach((form) => {
        const periodSelect = form.querySelector('[data-report-period]');
        const fromInput = form.querySelector('input[name="date_from"]');
        const toInput = form.querySelector('input[name="date_to"]');
        if (!periodSelect || !fromInput || !toInput) return;

        periodSelect.addEventListener('change', () => {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            let from = new Date(today);
            let to = new Date(today);

            switch (periodSelect.value) {
                case 'yesterday':
                    from.setDate(from.getDate() - 1);
                    to = new Date(from);
                    break;
                case '7days':
                    from.setDate(from.getDate() - 6);
                    break;
                case '30days':
                    from.setDate(from.getDate() - 29);
                    break;
                case 'month':
                    from = startOfMonth(today);
                    break;
                case 'last_month': {
                    const previous = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    from = startOfMonth(previous);
                    to = endOfMonth(previous);
                    break;
                }
                case 'year':
                    from = new Date(today.getFullYear(), 0, 1);
                    break;
                case 'custom':
                    return;
                case 'today':
                default:
                    break;
            }

            fromInput.value = formatDate(from);
            toInput.value = formatDate(to);
        });

        [fromInput, toInput].forEach((input) => {
            input.addEventListener('change', () => {
                periodSelect.value = 'custom';
            });
        });
    });
})();
