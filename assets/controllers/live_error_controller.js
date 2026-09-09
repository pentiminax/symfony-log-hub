import { Controller } from '@hotwired/stimulus';

/*
 * Turns a failed Live Component round trip into an inline banner.
 *
 * The default handler replaces the page with the error response, which throws away
 * the results already on screen. Suppressing it means a lost connection leaves the
 * last successful table exactly where it was — literally, not approximately.
 */
export default class extends Controller {
    static targets = ['banner'];

    connect() {
        this.element.addEventListener('live:response:error', this.#show);
        this.element.addEventListener('live:success', this.#hide);
    }

    disconnect() {
        this.element.removeEventListener('live:response:error', this.#show);
        this.element.removeEventListener('live:success', this.#hide);
    }

    #show = (event) => {
        event.detail.controls.displayError = false;

        if (this.hasBannerTarget) {
            this.bannerTarget.hidden = false;
        }
    };

    #hide = () => {
        if (this.hasBannerTarget) {
            this.bannerTarget.hidden = true;
        }
    };
}
