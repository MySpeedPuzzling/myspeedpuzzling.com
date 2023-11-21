import { Controller } from '@hotwired/stimulus';

/*
 * This is an example Stimulus controller!
 *
 * Any element with a data-controller="hello" attribute will cause
 * this controller to be executed. The name "hello" comes from the filename:
 * hello_controller.js -> "hello"
 *
 * Delete this file or adapt it for your use!
 */
export default class extends Controller {
    connect() {
        alert('test');
/*
        document.addEventListener('DOMContentLoaded', function() {
            // Get the checkbox element
            var checkbox = document.getElementById('addPuzzle');

            // Function to toggle the visibility of forms
            function toggleForms() {
                var existingPuzzleForm = document.getElementById('existing-puzzle-form');
                var customPuzzleForm = document.getElementById('custom-puzzle-form');

                if (checkbox.checked) {
                    // Checkbox is checked: show customPuzzleForm and hide existingPuzzleForm
                    customPuzzleForm.style.display = 'block';
                    existingPuzzleForm.style.display = 'none';
                } else {
                    // Checkbox is not checked: show existingPuzzleForm and hide customPuzzleForm
                    customPuzzleForm.style.display = 'none';
                    existingPuzzleForm.style.display = 'block';
                }
            }

            // Call the function to set the initial state
            toggleForms();

            // Add event listener for checkbox changes
            checkbox.addEventListener('change', toggleForms);
        });
 */
    }
}
