function setRequiredWithin(container, required) {
  container.querySelectorAll('[data-required-for]').forEach((field) => {
    field.required = required && field.dataset.requiredFor === container.dataset.targetFields;
  });
}

function initTargetForm() {
  const form = document.querySelector('[data-target-create-form]');
  if (!form) return;

  const type = form.querySelector('[data-target-type]');
  const description = form.querySelector('[data-target-form-description]');
  const submit = form.querySelector('[data-target-submit]');
  const authType = form.querySelector('[data-webhook-auth-type]');
  const authHeader = form.querySelector('[data-webhook-auth-header]');
  const authSecret = form.querySelector('[data-webhook-auth-secret]');
  const editing = form.hasAttribute('data-target-editing');

  const syncAuthentication = () => {
    const webhookVisible = type.value === 'generic_webhook';
    const needsSecret = webhookVisible && authType.value !== 'none';
    authHeader.hidden = !webhookVisible || authType.value !== 'api_key';
    authSecret.hidden = !needsSecret;
    authSecret.querySelector('input').required = needsSecret && !editing;
    authHeader.querySelector('input').required = webhookVisible && authType.value === 'api_key';
  };

  const syncType = () => {
    const selected = type.value;
    form.querySelectorAll('[data-target-fields]').forEach((fields) => {
      fields.hidden = fields.dataset.targetFields !== selected;
      setRequiredWithin(fields, !fields.hidden);
    });
    if (editing) form.querySelector('[name="token"]').required = false;
    const endpoint = selected === 'generic_webhook';
    description.textContent = endpoint
      ? 'Send mapped capture data to an HTTPS endpoint. Authentication secrets are encrypted.'
      : 'Send tasks to the configured Prsm installation. The token needs the task:create scope.';
    submit.textContent = editing ? 'Save target' : endpoint ? 'Add endpoint' : 'Add Prsm target';
    syncAuthentication();
  };

  type.addEventListener('change', syncType);
  authType.addEventListener('change', syncAuthentication);
  syncType();
}

function initActionForm() {
  const target = document.querySelector('[data-action-target]');
  const fields = document.querySelector('[data-webhook-template-fields]');
  const template = fields?.querySelector('[data-webhook-template]');
  const contentType = fields?.querySelector('[data-webhook-content-type]');
  const bodyLabel = fields?.querySelector('[data-webhook-body-label]');
  const bodyHelp = fields?.querySelector('[data-webhook-body-help]');
  const omitEmpty = fields?.querySelector('[data-webhook-omit-empty]');
  const omitEmptyLabel = fields?.querySelector('[data-webhook-omit-empty-label]');
  if (!target || !fields || !template || !contentType || !bodyLabel || !bodyHelp || !omitEmpty || !omitEmptyLabel) return;

  const bodies = new Map(
    [...fields.querySelectorAll('[data-webhook-default-body]')]
      .map((field) => [field.dataset.webhookDefaultBody, field.value]),
  );
  let activeContentType = contentType.value;
  bodies.set(activeContentType, template.value);

  const syncContentType = () => {
    const nextContentType = contentType.value;
    if (nextContentType !== activeContentType) {
      bodies.set(activeContentType, template.value);
      activeContentType = nextContentType;
      template.value = bodies.get(activeContentType) ?? '';
    }

    const plainText = activeContentType === 'text/plain';
    const multipart = activeContentType === 'multipart/form-data';
    bodyLabel.textContent = plainText ? 'Text body' : multipart ? 'Form fields (JSON)' : 'JSON body';
    bodyHelp.textContent = plainText
      ? 'Variables are inserted as text; arrays are serialized as JSON.'
      : multipart
        ? 'Each top-level key becomes a form field; arrays are serialized as JSON.'
        : 'Variables used as a complete JSON value retain their native type.';
    template.rows = plainText ? 8 : 13;
    omitEmpty.hidden = plainText;
    omitEmptyLabel.textContent = multipart ? 'Omit empty form fields' : 'Omit empty object fields';
  };

  const syncTarget = () => {
    const selected = target.selectedOptions[0];
    const webhook = selected?.dataset.targetType === 'generic_webhook';
    fields.hidden = !webhook;
    template.required = webhook;
  };
  target.addEventListener('change', syncTarget);
  contentType.addEventListener('change', syncContentType);
  fields.querySelectorAll('[data-template-variable]').forEach((button) => {
    button.addEventListener('click', () => {
      const token = `{{${button.dataset.templateVariable}}}`;
      template.setRangeText(token, template.selectionStart, template.selectionEnd, 'end');
      template.focus();
    });
  });
  syncContentType();
  syncTarget();
}

export function initAutomationSettings() {
  initTargetForm();
  initActionForm();
}
