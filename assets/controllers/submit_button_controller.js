import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["submit"];

    disableSubmitButton(event) {
        event.preventDefault();
        this.submitTarget.setAttribute('disabled', 'disabled');
        this.element.submit(); // Submit the form
    }
}

// <form data-controller="submit-button" data-action="submit->submit-button#disableSubmitButton">
//     <!-- Your form fields go here -->
//
//     <button type="submit" data-submit-button-target="submit">Submit</button>
// </form>
