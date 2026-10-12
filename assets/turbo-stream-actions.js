import { StreamActions } from "@hotwired/turbo"
import { inboxBadgeStreamAction, reapplyAfterRestore } from "./inbox_badge.js"

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

// The Inbox badge's realtime push (docs/features/navigation INB-02): numbers only, applied to every .inbox-badge and
// its Inbox link (assets/inbox_badge.js)
// Usage: <turbo-stream action="inbox_badge" targets=".inbox-badge" for-you="1" conversations="2" requests="0" at="1760000000"></turbo-stream>
StreamActions.inbox_badge = function() {
    inboxBadgeStreamAction(this)
}

// After a snapshot restore the page shows the numbers it was left with - the last push is re-applied when newer
document.addEventListener("turbo:load", reapplyAfterRestore)
