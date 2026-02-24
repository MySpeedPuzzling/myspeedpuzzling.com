import { Modal } from 'bootstrap';

document.addEventListener('turbo:frame-load', (event) => {
    if (event.target.id === 'feedbackForm') {
        if (!window.location.pathname.includes('/feedback')) {
            let myModal = new Modal(document.getElementById('feedbackModal'));
            myModal.show();
        }
    }
});
