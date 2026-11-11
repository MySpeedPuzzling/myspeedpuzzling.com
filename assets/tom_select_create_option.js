// The "add it as new" row of the brand and puzzle pickers, Tom Select's `option_create`. A card of its own above the
// results - it used to be a grey line that read like a hint, and players searched for a way to add a puzzle right
// past it. `label` is "Add as a new puzzle" / "Add as a new brand", `input` what the player typed (escaped here).
export const renderCreateOption = (label, input, escape) => `<div class="create create--new">`
    + `<span class="create__icon" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>`
    + `<span class="create__text"><span class="create__label">${escape(label)}</span>`
    + `<span class="create__name">${escape(input)}</span></span>`
    + `</div>`;

// Fields built by Symfony UX Autocomplete with `create` get the same card from a `data-create-label` attribute on the
// input (set in the form type) - runs after the field's own controllers, so the attribute is the last word.
export const installCreateOptionLabels = (root = document) => {
    root.addEventListener('autocomplete:pre-connect', (event) => {
        const label = event.target?.dataset?.createLabel;
        const render = event.detail?.options?.render;

        if (label && render) {
            render.option_create = (data, escape) => renderCreateOption(label, data.input, escape);
        }
    });
};
