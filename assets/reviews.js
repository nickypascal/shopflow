(() => {
    document.querySelectorAll('[data-star-rating]').forEach((group) => {
        const inputs = group.querySelectorAll('input[type="radio"]');
        const label = group.parentElement?.querySelector('[data-rating-label]');
        const descriptions = {
            1: '1 bintang · Sangat Buruk',
            2: '2 bintang · Buruk',
            3: '3 bintang · Cukup',
            4: '4 bintang · Baik',
            5: '5 bintang · Sangat Baik',
        };
        const update = () => {
            const selected = group.querySelector('input[type="radio"]:checked');
            if (label && selected) label.textContent = descriptions[Number(selected.value)] || `${selected.value} bintang`;
        };
        inputs.forEach((input) => input.addEventListener('change', update));
        update();
    });

    document.querySelectorAll('[data-review-images]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.files && input.files.length > 3) {
                alert('Maksimal tiga foto untuk setiap ulasan.');
                input.value = '';
            }
        });
    });
})();
