import { StreamActions } from "@hotwired/turbo"

// Custom Turbo Stream action to add a CSS class to an element
// Usage: <turbo-stream action="add_class" target="element-id" class-name="my-class"></turbo-stream>
StreamActions.add_class = function() {
    const targetId = this.getAttribute("target")
    const className = this.getAttribute("class-name")
    const element = document.getElementById(targetId)
    if (element && className) {
        element.classList.add(className)
    }
}

// Custom Turbo Stream action to remove a CSS class from an element
// Usage: <turbo-stream action="remove_class" target="element-id" class-name="my-class"></turbo-stream>
StreamActions.remove_class = function() {
    const targetId = this.getAttribute("target")
    const className = this.getAttribute("class-name")
    const element = document.getElementById(targetId)
    if (element && className) {
        element.classList.remove(className)
    }
}
