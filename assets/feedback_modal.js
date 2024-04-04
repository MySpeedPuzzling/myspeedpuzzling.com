import * as bootstrap from 'bootstrap';

document.addEventListener('turbo:frame-load', (event) => {
    console.log(event);

    if (event.target.id === 'feedbackForm') {
        if (!window.location.pathname.includes('/feedback')) {
            let myModal = new bootstrap.Modal(document.getElementById('feedbackModal'));
            myModal.show();
        }
    }
});
