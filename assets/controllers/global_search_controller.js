import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["searchBar", "searchResults"];

    connect() {
        this.ignoreNextClick = false; // Prevents conflict on initial click
        this.handleOutsideClick = this.handleOutsideClick.bind(this);
    }

    toggleSearchBar(event) {
        event.preventDefault(); // Prevent default link behavior

        if (this.isSearchBarOpen()) {
            this.closeSearchBar();
        } else {
            this.openSearchBar();
        }
    }

    openSearchBar() {
        // Add "search-overlay" class to <body>
        document.body.classList.add("search-overlay");
        // Remove "not-shown" class from the search bar
        this.searchBarTarget.classList.remove("not-shown");
        // Ignore the next click to avoid immediate close
        this.ignoreNextClick = true;
        // Add event listener to detect clicks outside the search bar
        document.addEventListener("click", this.handleOutsideClick);
    }

    closeSearchBar() {
        // Remove "search-overlay" class from <body>
        document.body.classList.remove("search-overlay");
        // Add "not-shown" class to the search bar
        this.searchBarTarget.classList.add("not-shown");
        // Remove event listener for outside clicks
        document.removeEventListener("click", this.handleOutsideClick);
    }

    handleOutsideClick(event) {
        if (this.ignoreNextClick) {
            this.ignoreNextClick = false; // Reset the flag for subsequent clicks
            return;
        }

        const isClickInsideSearch =
            this.searchBarTarget.contains(event.target) ||
            this.searchResultsTargets.some((target) => target.contains(event.target));

        if (!isClickInsideSearch) {
            this.closeSearchBar();
        }
    }

    isSearchBarOpen() {
        return !this.searchBarTarget.classList.contains("not-shown");
    }
}
