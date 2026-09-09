import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { lll } from '@typo3/core/lit-helper.js';
import { html } from 'lit';

/**
 * Asks for a reason before waving findings through.
 *
 * The note is the point of acknowledging — in half a year the question is not
 * that somebody dismissed a finding but why. Asking in a modal keeps the
 * finding list where it was instead of reloading the page and jumping.
 */
class AcknowledgeForm {
  constructor(form) {
    this.form = form;
    this.noteField = form.querySelector('input[name="note"]');

    form.querySelectorAll('[data-caretaker2-ack]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        this.open();
      });
    });
  }

  boxes() {
    return Array.from(this.form.querySelectorAll('input[name="findings[]"]'));
  }

  selectedCount() {
    return this.boxes().filter((box) => box.checked).length;
  }

  open() {
    const count = this.selectedCount();

    if (count === 0) {
      Notification.info(lll('js.ack.nothingSelected'), lll('js.ack.nothingSelectedBody'), 6);
      return;
    }

    Modal.advanced({
      title: lll('js.ack.title'),
      severity: SeverityEnum.warning,
      size: Modal.sizes.medium,
      content: html`
        <p>${count === 1 ? lll('js.ack.introOne') : lll('js.ack.introMany', count)}</p>
        <div class="form-group">
          <label class="form-label" for="caretaker2-ack-note">${lll('js.ack.noteLabel')}</label>
          <input type="text" class="form-control" id="caretaker2-ack-note"
                 placeholder=${lll('js.ack.notePlaceholder')}>
        </div>
      `,
      buttons: [
        { text: lll('js.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: (event, modal) => modal.hideModal() },
        {
          text: lll('js.ack.confirm'),
          btnClass: 'btn-warning',
          name: 'confirm',
          active: true,
          trigger: (event, modal) => {
            const input = modal.querySelector('#caretaker2-ack-note');
            this.noteField.value = input ? input.value : '';
            modal.hideModal();
            this.form.submit();
          },
        },
      ],
    });
  }
}

document.querySelectorAll('form[data-caretaker2-findings]').forEach((form) => new AcknowledgeForm(form));
