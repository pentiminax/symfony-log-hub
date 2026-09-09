import { Controller } from '@hotwired/stimulus';

/*
 * Tab panels with a roving tabindex, entirely client-side.
 *
 * Switching tabs in the detail drawer is a reading gesture, not a new question:
 * every panel is already in the DOM, so it must not cost a round trip.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    select(event) {
        this.#activate(this.tabTargets.indexOf(event.currentTarget));
    }

    navigate(event) {
        const step = { ArrowRight: 1, ArrowLeft: -1, Home: -Infinity, End: Infinity }[event.key];

        if (step === undefined) {
            return;
        }

        event.preventDefault();

        const count = this.tabTargets.length;
        const current = this.tabTargets.indexOf(event.currentTarget);
        const next = Number.isFinite(step)
            ? (current + step + count) % count
            : (step < 0 ? 0 : count - 1);

        this.#activate(next);
        this.tabTargets[next].focus();
    }

    #activate(index) {
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', String(i === index));
            tab.tabIndex = i === index ? 0 : -1;
        });

        this.panelTargets.forEach((panel, i) => {
            panel.hidden = i !== index;
        });
    }
}
