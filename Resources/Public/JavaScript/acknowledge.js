import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
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
    this.typeField = form.querySelector('input[name="acknowledgeType"]');

    form.querySelectorAll('[data-caretaker2-ack]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        this.open(button.dataset.ackType || '', button.textContent.trim());
      });
    });
  }

  selectedCount() {
    return this.form.querySelectorAll('input[name="findings[]"]:checked').length;
  }

  open(type, label) {
    const count = this.selectedCount();

    if (type === '' && count === 0) {
      Notification.info(
        'Nichts ausgewählt',
        'Zeilen ankreuzen, oder einen der Knöpfe für eine ganze Gruppe nehmen.',
        6,
      );
      return;
    }

    const subject = type === ''
      ? (count === 1 ? 'einen Befund' : `${count} Befunde`)
      : label.replace(/^Alle /, '');

    Modal.advanced({
      title: 'Befunde quittieren',
      severity: SeverityEnum.warning,
      size: Modal.sizes.medium,
      content: html`
        <p>Sie quittieren <strong>${subject}</strong>. Quittierte Befunde
        verschwinden dauerhaft aus der Ampel — ohne Frist. Wird einer später
        schwerwiegender, taucht er von selbst wieder auf.</p>
        <div class="form-group">
          <label class="form-label" for="caretaker2-ack-note">
            Warum werden sie ignoriert?
          </label>
          <input type="text" class="form-control" id="caretaker2-ack-note"
                 placeholder="z.B. Kunde zahlt kein Major-Update, Neubau für Q2 geplant">
        </div>
      `,
      buttons: [
        { text: 'Abbrechen', btnClass: 'btn-default', name: 'cancel', trigger: (event, modal) => modal.hideModal() },
        {
          text: 'Quittieren',
          btnClass: 'btn-warning',
          name: 'confirm',
          active: true,
          trigger: (event, modal) => {
            const input = modal.querySelector('#caretaker2-ack-note');
            this.noteField.value = input ? input.value : '';
            this.typeField.value = type;
            modal.hideModal();
            this.form.submit();
          },
        },
      ],
    });
  }
}

document.querySelectorAll('form[data-caretaker2-findings]').forEach((form) => new AcknowledgeForm(form));
