import './bootstrap';
import Alpine from 'alpinejs';
import { spikiaConfirm, spikiaConfirmSubmit } from './spikia-notify';

window.Alpine = Alpine;

Alpine.start();

// Los onsubmit="..." inline en los blades llaman a esto por nombre global, asi que se
// exponen en window (ver spikia-notify.js para la implementacion real, compartida con
// master.js).
window.spikiaConfirm = spikiaConfirm;
window.spikiaConfirmSubmit = spikiaConfirmSubmit;
