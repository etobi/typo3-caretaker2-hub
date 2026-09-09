import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { html } from 'lit';
import '@typo3/backend/copy-to-clipboard.js';

/**
 * Shows a fresh enrollment code in a modal.
 *
 * Fetched rather than posted so the instance list stays where it is. The
 * button remains a real submit button, so without JavaScript the server-side
 * path still works.
 */
const field = (label, value) => html`
  <div class="form-group">
    <label class="form-label">${label}</label>
    <div class="input-group">
      <input type="text" class="form-control" readonly value=${value}
             @focus=${(event) => event.target.select()}>
      <typo3-copy-to-clipboard class="btn btn-default" title="Kopieren" text=${value}>
        <typo3-backend-icon identifier="actions-clipboard" size="small"></typo3-backend-icon>
      </typo3-copy-to-clipboard>
    </div>
  </div>
`;

const show = (data) => {
  Modal.advanced({
    title: 'Instanz verbinden',
    severity: SeverityEnum.ok,
    size: Modal.sizes.medium,
    content: html`
      <p>Beides im Backend-Modul <strong>Caretaker2</strong> der Instanz eintragen.
      Der Code ist ${data.validMinutes} Minuten gültig und lässt sich genau einmal einlösen.</p>
      ${field('Adresse des Hubs', data.hubUrl)}
      ${field('Code', data.code)}
    `,
    buttons: [
      { text: 'Schließen', btnClass: 'btn-default', active: true, name: 'close', trigger: (event, modal) => modal.hideModal() },
    ],
  });
};

document.querySelectorAll('[data-caretaker2-enroll]').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    button.disabled = true;

    new AjaxRequest(button.dataset.caretaker2Enroll).post({})
      .then(async (response) => show(await response.resolve()))
      .catch(() => Notification.error(
        'Code konnte nicht erzeugt werden',
        'Bitte erneut versuchen. Bleibt es dabei, steht der Grund im Log des Hubs.',
      ))
      .finally(() => { button.disabled = false; });
  });
});
