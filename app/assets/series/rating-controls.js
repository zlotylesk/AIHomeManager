import { hideError, showError } from '../banners.js';

// 1–10 button selector (the project deliberately uses buttons, not a number
// input). `onChange` is invoked with the picked value.
export function renderRatingSelector(selected, onChange) {
    const wrap = document.createElement('div');
    wrap.className = 'rating-selector';
    for (let i = 1; i <= 10; i++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rating-btn' + (selected === i ? ' selected' : '');
        btn.textContent = i;
        btn.dataset.value = i;
        btn.addEventListener('click', () => {
            wrap.querySelectorAll('.rating-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            onChange(i);
        });
        wrap.appendChild(btn);
    }
    return wrap;
}

// "My rating" control with a display/edit/clear cycle. `onSave(value|null)`
// persists the change (null clears the rating).
export function buildOwnRatingControl(current, onSave) {
    const wrap = document.createElement('span');
    wrap.className = 'own-rating';

    const renderDisplay = () => {
        wrap.innerHTML = '';
        const rated = current !== null && current !== undefined;
        const label = document.createElement('span');
        label.className = 'own-rating-label';
        label.textContent = 'Moja ocena:';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rating-cell-btn' + (rated ? '' : ' rating-cell-empty');
        btn.textContent = rated ? `★ ${current}` : 'Oceń';
        btn.title = rated ? 'Zmień swoją ocenę' : 'Ustaw swoją ocenę';
        btn.addEventListener('click', renderEditor);
        wrap.append(label, ' ', btn);

        if (rated) {
            const clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'rating-clear';
            clear.textContent = '✕';
            clear.title = 'Usuń swoją ocenę';
            clear.addEventListener('click', async () => {
                clear.disabled = true;
                hideError();
                try {
                    await onSave(null);
                    current = null;
                    renderDisplay();
                } catch (err) {
                    showError(err.message || 'Nie udało się usunąć oceny.');
                    clear.disabled = false;
                }
            });
            wrap.append(' ', clear);
        }
    };

    const renderEditor = () => {
        wrap.innerHTML = '';
        const editor = document.createElement('div');
        editor.className = 'rating-editor';

        const selector = renderRatingSelector(current ?? null, async value => {
            if (value === current) {
                renderDisplay();
                return;
            }
            selector.querySelectorAll('.rating-btn').forEach(b => { b.disabled = true; });
            hideError();
            try {
                await onSave(value);
                current = value;
                renderDisplay();
            } catch (err) {
                showError(err.message || 'Nie udało się zapisać oceny.');
                renderDisplay();
            }
        });

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'rating-cancel';
        cancel.textContent = '✕';
        cancel.title = 'Anuluj';
        cancel.addEventListener('click', renderDisplay);

        editor.append(selector, cancel);
        wrap.appendChild(editor);
    };

    renderDisplay();
    return wrap;
}
