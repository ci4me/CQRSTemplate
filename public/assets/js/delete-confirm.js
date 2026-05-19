// Wire up data-confirm prompts on submit. Replaces inline onsubmit="..." which
// is incompatible with a strict Content-Security-Policy.
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    var message = form.getAttribute('data-confirm');
    if (!message) {
        return;
    }
    if (!window.confirm(message)) {
        event.preventDefault();
    }
});
