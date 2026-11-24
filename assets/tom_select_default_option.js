// The default collection inside a collection picker (templates/collections/_collection_quick_pick.html.twig,
// docs/features/collections/default-collection.md): an input with `data-default-collection` gets that option listed
// first and marked with a "Default" badge (`data-default-label`), in the dropdown and as the chosen item.
export const installDefaultOptionBadges = (root = document) => {
    root.addEventListener('autocomplete:pre-connect', (event) => {
        const defaultId = event.target?.dataset?.defaultCollection;
        const label = event.target?.dataset?.defaultLabel;
        const options = event.detail?.options;

        if (!defaultId || !label || !options) {
            return;
        }

        if (Array.isArray(options.options)) {
            const index = options.options.findIndex((option) => option.value === defaultId);

            if (index > 0) {
                options.options.unshift(...options.options.splice(index, 1));
            }
        }

        const badge = (data, escape) => (data.value === defaultId
            ? ` <span class="badge bg-primary rounded-pill fs-xs fw-normal align-middle ms-1">${escape(label)}</span>`
            : '');

        options.render = options.render || {};
        options.render.option = (data, escape) => `<div>${escape(data.text)}${badge(data, escape)}</div>`;
        options.render.item = (data, escape) => `<div>${escape(data.text)}${badge(data, escape)}</div>`;
    });
};
