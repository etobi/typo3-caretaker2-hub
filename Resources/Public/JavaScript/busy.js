import Icons from '@typo3/backend/icons.js';

/**
 * Keeps a form from being sent twice while the hub is still busy with it.
 *
 * "Refresh data" waits for the instance to collect and push, which takes
 * seconds, and a button that does nothing visible invites a second click.
 * Once the form is on its way, its buttons are disabled and the one that
 * was clicked shows a spinner. The docheader buttons live outside the form
 * and point at it through their form attribute, so they are collected by
 * that rather than by descent.
 */
const buttonsOf = (form) => Array.from(
  form.ownerDocument.querySelectorAll(`button[type="submit"][form="${form.id}"]`)
).concat(Array.from(form.querySelectorAll('button[type="submit"]')));

const showBusy = async (form, submitter) => {
  buttonsOf(form).forEach((button) => { button.disabled = true; });

  if (!submitter) {
    return;
  }

  const spinner = await Icons.getIcon('spinner-circle', Icons.sizes.small);
  const icon = submitter.querySelector('.icon, typo3-backend-icon');
  if (icon) {
    icon.outerHTML = spinner;
  } else {
    submitter.insertAdjacentHTML('afterbegin', spinner + ' ');
  }
};

const restore = (form) => {
  delete form.dataset.caretaker2Sent;
  buttonsOf(form).forEach((button) => { button.disabled = false; });
};

const forms = Array.from(document.querySelectorAll('form[data-caretaker2-busy]'));

forms.forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.dataset.caretaker2Sent === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.caretaker2Sent = '1';

    const submitter = event.submitter || buttonsOf(form)[0];

    // After the browser has collected the form data: a button disabled while
    // the submit event runs does not send its name and value, and the server
    // would not know which action was meant.
    window.setTimeout(() => showBusy(form, submitter), 0);
  });
});

// Coming back through the browser's history restores the page as it was
// left, disabled buttons and all.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    forms.forEach(restore);
  }
});
