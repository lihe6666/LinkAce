import { getInstance } from '../lib/views';
import TagsSelect from './TagsSelect';

export default class AiTagsButton {

  constructor ($el) {
    this.$button = $el;
    this.$tags = document.querySelector('#tags');

    if (!this.$tags) {
      return;
    }

    this.tagSelect = getInstance(this.$tags, TagsSelect);
    this.$button.addEventListener('click', this.onClick.bind(this));
  }

  onClick () {
    const $form = this.$button.closest('form');

    if (!$form) {
      return;
    }

    const value = (name) => $form.querySelector(`[name="${name}"]`)?.value ?? '';

    this.setLoading(true);

    fetch(window.appData.routes.fetch.aiTags, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        _token: window.appData.user.token,
        title: value('title'),
        url: value('url'),
        description: value('description')
      })
    })
      .then(async (response) => {
        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.error ?? 'AI tagging failed');
        }

        return data;
      })
      .then((data) => {
        (data.tags ?? []).forEach((tag) => {
          this.tagSelect.select.addOption({value: tag, text: tag});
          this.tagSelect.select.createItem(tag);
        });
      })
      .catch(() => window.alert(this.$button.dataset.errorLabel ?? 'AI tagging failed.'))
      .finally(() => this.setLoading(false));
  }

  setLoading (loading) {
    if (typeof this.$button.dataset.defaultLabel === 'undefined') {
      this.$button.dataset.defaultLabel = this.$button.textContent;
    }

    this.$button.disabled = loading;
    this.$button.textContent = loading
      ? (this.$button.dataset.loadingLabel ?? '…')
      : this.$button.dataset.defaultLabel;
  }
}
