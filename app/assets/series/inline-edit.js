import { hideError, showError } from './banners.js';

// Click-to-edit text/number cell: click the value → input, Enter/blur saves,
// Escape cancels. `onSave(next)` persists; a rejected save surfaces an error
// and reverts to the previous value.
export function buildInlineEditable(value, {inputType = 'text', min = 1, ariaLabel, onSave}) {
    const wrap = document.createElement('span');
    wrap.className = 'inline-editable';

    const normalize = (raw) => inputType === 'number' ? parseInt(raw, 10) : String(raw).trim();
    const isValid = (v) => inputType === 'number' ? Number.isInteger(v) && v >= min : v !== '';

    const showDisplay = () => {
        wrap.innerHTML = '';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'inline-editable-value js-inline-edit';
        btn.textContent = value;
        btn.title = ariaLabel ? `Edit ${ariaLabel}` : 'Click to edit';
        btn.addEventListener('click', showEditor);
        wrap.appendChild(btn);
    };

    const showEditor = () => {
        wrap.innerHTML = '';
        const input = document.createElement('input');
        input.type = inputType;
        input.className = 'inline-editable-input';
        input.value = value;
        if (inputType === 'number') input.min = String(min);

        let settled = false;
        const cancel = () => { if (!settled) { settled = true; showDisplay(); } };
        const save = async () => {
            if (settled) return;
            const next = normalize(input.value);
            if (next === value || !isValid(next)) { cancel(); return; }
            settled = true;
            input.disabled = true;
            hideError();
            try {
                await onSave(next);
                value = next;
            } catch (err) {
                showError(err.message || 'Failed to save.');
            }
            showDisplay();
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
        input.addEventListener('blur', save);

        wrap.appendChild(input);
        input.focus();
        input.select();
    };

    showDisplay();
    return wrap;
}
