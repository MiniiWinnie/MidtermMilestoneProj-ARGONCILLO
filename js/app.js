'use strict';

(() => {
    const toast = document.querySelector('[data-toast]');
    let toastTimer = null;

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2800);
    }

    const ingredientRows = document.querySelector('[data-ingredient-rows]');
    const addIngredientButton = document.querySelector('[data-add-ingredient]');
    if (ingredientRows && addIngredientButton) {
        addIngredientButton.addEventListener('click', () => {
            const currentRows = ingredientRows.querySelectorAll('[data-ingredient-row]');
            if (currentRows.length >= 40) {
                showToast('A recipe can have at most 40 ingredients.');
                return;
            }
            const indexes = Array.from(currentRows).map(row => Number(row.dataset.index || 0));
            const index = (indexes.length ? Math.max(...indexes) : -1) + 1;
            const row = document.createElement('div');
            row.className = 'ingredient-row';
            row.dataset.ingredientRow = '';
            row.dataset.index = String(index);

            const fields = [
                { key: 'name', placeholder: 'Ingredient name', max: 100, required: true, label: 'Ingredient name' },
                { key: 'quantity', placeholder: 'Quantity', max: 20, required: false, label: 'Quantity' },
                { key: 'unit', placeholder: 'Unit', max: 30, required: false, label: 'Unit' }
            ];
            fields.forEach(field => {
                const label = document.createElement('label');
                const hiddenLabel = document.createElement('span');
                hiddenLabel.className = 'visually-hidden';
                hiddenLabel.textContent = field.label;
                const input = document.createElement('input');
                input.type = 'text';
                input.name = `ingredients[${index}][${field.key}]`;
                input.placeholder = field.placeholder;
                input.maxLength = field.max;
                input.required = field.required;
                label.append(hiddenLabel, input);
                row.append(label);
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-ingredient';
            remove.dataset.removeIngredient = '';
            remove.setAttribute('aria-label', 'Remove ingredient row');
            remove.textContent = '×';
            row.append(remove);
            ingredientRows.append(row);
            row.querySelector('input')?.focus();
        });

        ingredientRows.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-ingredient]');
            if (!button) return;
            const row = button.closest('[data-ingredient-row]');
            if (!row) return;
            const rows = ingredientRows.querySelectorAll('[data-ingredient-row]');
            if (rows.length === 1) {
                row.querySelectorAll('input').forEach(input => { input.value = ''; });
                row.querySelector('input')?.focus();
                return;
            }
            row.remove();
        });
    }

    const registerForm = document.querySelector('#register-form');
    if (registerForm) {
        const password = registerForm.querySelector('#register-password');
        const confirmation = registerForm.querySelector('#register-confirm');
        const matchPasswords = () => {
            if (!password || !confirmation) return;
            confirmation.setCustomValidity(confirmation.value !== password.value ? 'Passwords do not match.' : '');
        };
        password?.addEventListener('input', matchPasswords);
        confirmation?.addEventListener('input', matchPasswords);
        registerForm.addEventListener('submit', event => {
            matchPasswords();
            if (!registerForm.checkValidity()) {
                event.preventDefault();
                registerForm.reportValidity();
            }
        });
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-favorite-button]');
        if (!button || button.disabled) return;
        event.preventDefault();
        button.disabled = true;
        const wasFavorite = button.dataset.favorited === '1';
        try {
            const response = await fetch(button.dataset.endpoint || 'home.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({
                    action: 'toggle_favorite',
                    recipe_id: button.dataset.recipeId || '',
                    csrf_token: button.dataset.csrf || ''
                }),
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Favorite could not be updated.');

            const isFavorite = Boolean(data.is_favorite);
            button.dataset.favorited = isFavorite ? '1' : '0';
            button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
            button.classList.toggle('is-favorite', isFavorite);
            button.textContent = isFavorite ? '♥ Saved' : (button.closest('.detail-toolbar') ? '♡ Save recipe' : '♡ Save');

            const card = button.closest('.recipe-card');
            const count = card?.querySelector('[data-favorite-count]') || document.querySelector('.detail-toolbar [data-favorite-count]');
            if (count) count.textContent = String(Math.max(0, Number(count.textContent || '0') + (isFavorite ? 1 : -1)));
            showToast(data.message || 'Favorites updated.');

            if (!isFavorite && card?.dataset.favoritesOnly === '1') {
                card.remove();
                const counter = document.querySelector('[data-recipe-total]');
                if (counter) counter.textContent = String(Math.max(0, Number(counter.textContent || '0') - 1));
                if (!document.querySelector('.member-card')) {
                    const empty = document.querySelector('[data-favorites-empty]');
                    if (empty) empty.hidden = false;
                }
            }
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Favorite could not be updated.');
        } finally {
            button.disabled = false;
        }
    });

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-copy-ingredients]');
        if (!button) return;
        const text = button.dataset.ingredients || '';
        const feedback = document.querySelector('[data-copy-feedback]');
        try {
            await navigator.clipboard.writeText(text);
            if (feedback) feedback.textContent = 'Ingredient list copied.';
            showToast('Ingredient list copied.');
        } catch (_error) {
            const temporary = document.createElement('textarea');
            temporary.value = text;
            temporary.setAttribute('readonly', 'readonly');
            temporary.style.position = 'fixed';
            temporary.style.opacity = '0';
            document.body.append(temporary);
            temporary.select();
            const copied = document.execCommand('copy');
            temporary.remove();
            const message = copied ? 'Ingredient list copied.' : 'Copy is unavailable in this browser.';
            if (feedback) feedback.textContent = message;
            showToast(message);
        }
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-confirm]');
        if (!form) return;
        if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault();
    });
})();
