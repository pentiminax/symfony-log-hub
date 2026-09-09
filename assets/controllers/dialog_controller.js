import { Controller } from '@hotwired/stimulus';

/*
 * Opens a <dialog> modally as soon as it exists in the DOM.
 *
 * The drawer is server-rendered: it appears and disappears with the component's
 * markup rather than through a client-side "open" flag. Reconnecting on every
 * re-render is what keeps showModal() — and with it the native focus trap, the top
 * layer and Escape — in charge, instead of reimplementing all three.
 *
 * Escape and backdrop clicks are intercepted rather than allowed to close the
 * element directly: the server owns whether the drawer exists, so closing it here
 * first would leave the DOM and the URL disagreeing until the round trip lands.
 */
export default class extends Controller {
    connect() {
        if (!this.element.open) {
            this.element.showModal();
        }

        this.element.addEventListener('cancel', this.#requestClose);
        this.element.addEventListener('click', this.#closeOnBackdrop);
    }

    disconnect() {
        this.element.removeEventListener('cancel', this.#requestClose);
        this.element.removeEventListener('click', this.#closeOnBackdrop);
    }

    #requestClose = (event) => {
        event.preventDefault();
        this.dispatch('closed', { prefix: 'dialog' });
    };

    /* A click on the dialog element itself lands on the backdrop, never on content. */
    #closeOnBackdrop = (event) => {
        if (event.target === this.element) {
            this.dispatch('closed', { prefix: 'dialog' });
        }
    };
}
