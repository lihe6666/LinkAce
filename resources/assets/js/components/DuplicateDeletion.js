export default class DuplicateDeletion {

  constructor ($el) {
    this.$el = $el;
    this.$form = $el.querySelector('#duplicate-delete-form');

    this.init();
  }

  init () {
    this.$el.querySelectorAll('[data-duplicate-delete]').forEach($button => {
      $button.addEventListener('click', this.onDelete.bind(this));
    });
  }

  onDelete (event) {
    const $button = event.currentTarget;
    const $scope = $button.closest('[data-duplicate-group]') || this.$el;

    const ids = [];
    $scope.querySelectorAll('.duplicate-check:checked').forEach($check => {
      ids.push($check.dataset.id);
    });

    if (ids.length === 0) {
      return;
    }

    if (typeof $button.dataset.confirmation !== 'undefined' && confirm($button.dataset.confirmation) === false) {
      return;
    }

    this.$form.querySelector('[name="models"]').value = ids.join(',');
    this.$form.submit();
  }
}
