import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { lll } from '@typo3/core/lit-helper.js';
import { html } from 'lit';

/**
 * Shows a fresh enrollment code in a modal.
 *
 * Fetched rather than posted so the instance list stays where it is. The
 * button remains a real submit button, so without JavaScript the server-side
 * path still works.
 */
/**
 * Copies through the window that owns the element.
 *
 * Modal resolves to the top frame's instance and appends the dialog to the top
 * document, so anything inside it lives there and not in this module's frame.
 * <typo3-copy-to-clipboard> would render but stay inert, because importing the
 * module only registers the custom element in this frame's registry. Reading
 * the clipboard API off the owning window keeps the call in the same document
 * as the click that triggered it.
 */
const copy = (event, value) => {
  const clipboard = event.target.ownerDocument.defaultView?.navigator?.clipboard;

  if (!clipboard) {
    Notification.warning(lll('js.copy.unavailable'), lll('js.copy.manually'), 6);
    return;
  }

  clipboard.writeText(value)
    .then(() => Notification.success(lll('js.copy.done'), '', 2))
    .catch(() => Notification.warning(lll('js.copy.failed'), lll('js.copy.manually'), 6));
};

const field = (label, value) => html`
  <div class="form-group">
    <label class="form-label">${label}</label>
    <div class="input-group">
      <input type="text" class="form-control" readonly value=${value}
             @focus=${(event) => event.target.select()}>
      <button type="button" class="btn btn-default"
              @click=${(event) => copy(event, value)}>${lll('js.copy')}</button>
    </div>
  </div>
`;

const show = (data) => {
  Modal.advanced({
    title: lll('list.enroll.title'),
    severity: SeverityEnum.ok,
    size: Modal.sizes.medium,
    content: html`
      <p>${lll('js.enroll.intro', data.validMinutes)}</p>
      ${field(lll('list.enroll.hubUrl'), data.hubUrl)}
      ${field(lll('list.enroll.code'), data.code)}
    `,
    buttons: [
      { text: lll('js.close'), btnClass: 'btn-default', active: true, name: 'close', trigger: (event, modal) => modal.hideModal() },
    ],
  });
};

document.querySelectorAll('[data-caretaker2-enroll]').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    button.disabled = true;

    new AjaxRequest(button.dataset.caretaker2Enroll).post({})
      .then(async (response) => show(await response.resolve()))
      .catch(() => Notification.error(lll('js.enroll.failed'), lll('js.enroll.failedBody')))
      .finally(() => { button.disabled = false; });
  });
});
