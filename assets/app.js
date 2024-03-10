import './styles/app.scss';

// start the Stimulus application
import './bootstrap';

import 'simplebar'; // or "import SimpleBar from 'simplebar';" if you want to use it manually.
import 'simplebar/dist/simplebar.css';

// Twitter bootstrap
import 'bootstrap';

document.addEventListener('turbo:visit', (event) => {
    console.log('Visiting:', event.detail.url);
});

document.addEventListener('turbo:before-fetch-request', (event) => {
    console.log('Before fetch request:', event.detail.fetchOptions);
});

document.addEventListener('turbo:before-fetch-response', (event) => {
    console.log('Before fetch response:', event.detail.fetchResponse);
});
