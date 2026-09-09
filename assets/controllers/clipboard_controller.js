import { Controller } from '@hotwired/stimulus';

/* Copy a value, and say so on the button that was pressed. */
export default class extends Controller {
    static values = { text: String };

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
        } catch {
            // Denied permission or an insecure origin: no toast, no fallback prompt.
            // The value is already selectable on screen.
            return;
        }

        this.element.classList.add('is-copied');
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.element.classList.remove('is-copied'), 1200);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
