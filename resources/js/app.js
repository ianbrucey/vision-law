import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

window.Alpine = Alpine;

// x-trap (focus containment for <x-ui.modal> and the nav drawer) lives in the
// first-party Focus plugin — it is NOT in the Alpine core bundle.
Alpine.plugin(focus);

Alpine.start();
