import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["searchInput"];

    connect() {
        this.ignoreNextClick = false; // Prevents conflict on initial click
        this.handleOutsideClick = this.handleOutsideClick.bind(this);
    }

    toggleSearchBar(event) {
        event.preventDefault(); // Prevent default anchor behavior

        if (this.isSearchBarOpen()) {
            this.closeSearchBar();
        } else {
            this.openSearchBar();
        }
    }

    openSearchBar() {
        document.body.classList.add("global-search-shown"); // Add class to <body>
        this.searchInputTarget.focus(); // Focus the input field
        this.ignoreNextClick = true; // Prevent immediate close
        document.addEventListener("click", this.handleOutsideClick); // Add outside click listener
    }

    closeSearchBar(event) {
        if (event) {
            event.preventDefault(); // Prevent default behavior
        }

        document.body.classList.remove("global-search-shown"); // Remove class from <body>
        document.removeEventListener("click", this.handleOutsideClick); // Remove outside click listener
    }

    handleOutsideClick(event) {
        if (this.ignoreNextClick) {
            this.ignoreNextClick = false; // Reset the flag for subsequent clicks
            return;
        }

        const clickedOverlay = event.target.classList.contains("global-search-overlay");

        // Close if the click is on the overlay or outside the search elements
        if (clickedOverlay || !event.target.closest(".global-search")) {
            this.closeSearchBar();
        }
    }

    isSearchBarOpen() {
        return document.body.classList.contains("global-search-shown");
    }
}
