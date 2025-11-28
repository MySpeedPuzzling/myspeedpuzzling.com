# Symfony UX + Hotwire Architecture Guide

## Overview

This document describes architectural patterns for building dynamic, efficient UIs in Symfony applications using Hotwire (Turbo + Stimulus) and Symfony UX Live Components. The goal is to create maintainable, performant applications that work without JavaScript (progressive enhancement) while providing rich interactivity when JS is available.

---

## Problem Statement (Current State)

### Issues to Address

1. **Modal Proliferation**: Pages render many hidden modals (e.g., 20 edit modals + 20 delete confirmation modals), causing:
   - Bloated HTML responses
   - Slow initial page loads
   - Memory overhead

2. **N+1 Component Problem**: Using Live Components for repeated UI elements (e.g., wishlist buttons on list items) causes:
   - Multiple component instances (20 items = 20 components)
   - Repeated database queries (each component queries independently)
   - Poor scalability

3. **Custom Event Spaghetti**: Excessive custom Stimulus controllers and event listeners to coordinate:
   - Modal open/close
   - DOM updates after actions (remove item, update counts)
   - State synchronization between components

4. **Tight Coupling**: Logic scattered across multiple Live Components, Stimulus controllers, and templates without clear boundaries.

---

## Target Architecture

### Core Principle: Hybrid Approach

Use the right tool for each job:

| Concern | Technology | Why |
|---------|------------|-----|
| Lazy-load modal content | Turbo Frame | Single frame, content loaded on demand |
| DOM updates after actions | Turbo Stream | Surgical updates, no full page reload |
| Complex form interactivity | Live Component | Real-time validation, dependent fields |
| Simple UI behavior (open/close) | Stimulus | Lightweight, declarative |
| List rendering | Server-side Twig | Single query, no N+1 |

### Architecture Layers

```
┌─────────────────────────────────────────────────────────────────┐
│ Layer 1: Controller                                             │
│ - Route handling                                                │
│ - Request type detection (Turbo Frame vs normal)                │
│ - Template selection                                            │
│ - Context passing                                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 2: Templates (Wrappers)                                   │
│ - Modal wrapper (_modal.html.twig)                              │
│ - Full page wrapper (full_page.html.twig)                       │
│ - Both embed the SAME component                                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 3: Live Component                                         │
│ - Form logic and validation                                     │
│ - Context-aware response handling                               │
│ - Returns Turbo Streams OR redirects based on context           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 4: Turbo Streams                                          │
│ - Update specific DOM elements                                  │
│ - Remove deleted items                                          │
│ - Update counters                                               │
│ - Clear modal frame (triggers close)                            │
└─────────────────────────────────────────────────────────────────┘
```

---

## Key Concepts

### Turbo Frame vs Turbo Stream

This distinction is critical to understand:

#### Turbo Frame
- **Purpose**: Define where response content should be loaded
- **How it works**: Link/form with `data-turbo-frame="frame-id"` loads response into that frame
- **Requires**: `<turbo-frame id="...">` wrapper on both the page AND in the response
- **Use when**: Lazy loading content (modals, tabs, deferred sections)

```html
<!-- Page has a frame -->
<turbo-frame id="modal-frame"></turbo-frame>

<!-- Link targets that frame -->
<a href="/item/1/edit" data-turbo-frame="modal-frame">Edit</a>

<!-- Response MUST have matching frame -->
<turbo-frame id="modal-frame">
    <form>...</form>
</turbo-frame>
```

#### Turbo Stream
- **Purpose**: Server-initiated DOM manipulation
- **How it works**: Server returns `<turbo-stream>` elements with actions (append, prepend, replace, update, remove)
- **Requires**: Target elements only need an `id` attribute (NO turbo-frame wrapper needed)
- **Use when**: Updating page after form submission (delete item, update counter, show flash)

```html
<!-- Page has elements with IDs (no special wrapper) -->
<span id="items-count">5 items</span>
<div id="item-123" class="item-card">...</div>

<!-- Server returns streams to manipulate them -->
<turbo-stream action="remove" target="item-123"></turbo-stream>
<turbo-stream action="update" target="items-count">
    <template>4 items</template>
</turbo-stream>
```

#### Decision Matrix

| Scenario | Use Frame? | Use Stream? |
|----------|------------|-------------|
| Load modal content on click | ✅ Frame | ❌ |
| Remove deleted item from list | ❌ | ✅ Stream |
| Update counter after action | ❌ | ✅ Stream |
| Close modal after action | ❌ | ✅ Stream (clear frame) |
| Lazy load sidebar content | ✅ Frame | ❌ |
| Show flash message | ❌ | ✅ Stream |

---

## Pattern: Single Global Modal

### Structure

```twig
{# base.html.twig #}
<div id="modal-container" 
     class="hidden" 
     data-controller="modal"
     data-modal-frame-target="frame">
    
    <div class="modal-backdrop" data-action="click->modal#close"></div>
    
    <div class="modal-content">
        <!-- THE ONLY TURBO FRAME NEEDED FOR MODALS -->
        <turbo-frame id="modal-frame" data-modal-target="frame">
        </turbo-frame>
    </div>
</div>
```

### Triggering the Modal

```twig
{# Any page #}
<a href="{{ path('item_edit', {id: item.id}) }}"
   data-turbo-frame="modal-frame"
   data-modal-context="list"
   data-action="click->modal#open">
    Edit
</a>
```

### Modal Content Response

```twig
{# item/_edit_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Edit {{ item.name }}</h2>
        <button data-action="modal#close">&times;</button>
    </div>
    
    {{ component('ItemForm', {item: item, context: context}) }}
</turbo-frame>
```

### Closing the Modal

The modal closes when the frame becomes empty. After successful action, return a stream that clears it:

```twig
<turbo-stream action="update" target="modal-frame">
    <template></template>
</turbo-stream>
```

The Stimulus controller detects empty frame and hides the modal container.

---

## Pattern: Context-Aware Responses

### Problem

Same action (edit, delete) invoked from different pages (list, detail) requires different responses:
- From list: Update item in place, update count, stay on page
- From detail: Redirect to list or refresh detail

### Solution: Pass Context

#### Step 1: Mark the Link with Context

```twig
{# list.html.twig #}
<a href="{{ path('item_delete_confirm', {id: item.id}) }}"
   data-turbo-frame="modal-frame"
   data-modal-context="list">Delete</a>

{# detail.html.twig #}
<a href="{{ path('item_delete_confirm', {id: item.id}) }}"
   data-turbo-frame="modal-frame"
   data-modal-context="detail">Delete</a>
```

#### Step 2: Stimulus Injects Context into Form

```javascript
// modal_controller.js
open(event) {
    this.currentContext = event.currentTarget.dataset.modalContext || 'default'
    this.containerTarget.classList.remove('hidden')
}

submitStart(event) {
    const form = event.target
    let input = form.querySelector('input[name="_context"]')
    if (!input) {
        input = document.createElement('input')
        input.type = 'hidden'
        input.name = '_context'
        form.appendChild(input)
    }
    input.value = this.currentContext
}
```

#### Step 3: Live Component Uses Context

```php
#[AsLiveComponent]
class ItemForm extends AbstractController
{
    #[LiveProp]
    public string $context = 'list';
    
    #[LiveAction]
    public function save(EntityManagerInterface $em): Response
    {
        // ... validation and save logic ...
        
        return match($this->context) {
            'detail' => $this->redirectToRoute('item_show', ['id' => $this->item->getId()]),
            'list' => $this->renderTurboStream('item/_update_stream.html.twig', [
                'item' => $this->item,
            ]),
        };
    }
}
```

---

## Pattern: Progressive Enhancement

### Principle

Every feature must work without JavaScript. Turbo and Stimulus enhance, not enable.

### Implementation

#### Controller Detects Request Type

```php
#[Route('/item/{id}/edit', name: 'item_edit')]
public function edit(Request $request, Item $item): Response
{
    $isTurboFrame = $request->headers->has('Turbo-Frame');
    
    $template = $isTurboFrame
        ? 'item/_edit_modal.html.twig'    // Just the frame content
        : 'item/edit.html.twig';           // Full page
    
    return $this->render($template, ['item' => $item]);
}
```

#### Two Templates, Same Component

```twig
{# item/_edit_modal.html.twig - For Turbo Frame requests #}
<turbo-frame id="modal-frame">
    {{ component('ItemForm', {item: item, context: 'list'}) }}
</turbo-frame>
```

```twig
{# item/edit.html.twig - For normal requests (new tab, no JS) #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Edit {{ item.name }}</h1>
    {{ component('ItemForm', {item: item, context: 'detail'}) }}
    <a href="{{ path('item_list') }}">Back to list</a>
{% endblock %}
```

---

## Pattern: Efficient List Rendering

### Problem

Using Live Components for repeated elements (wishlist buttons) causes N+1 queries.

### Solution: Single Query, Data Attributes

#### Controller Fetches All Data Once

```php
public function list(): Response
{
    $items = $this->itemRepository->findAll();
    
    // Single query: get all wishlisted IDs for current user
    $wishlistItemIds = $this->wishlistRepository->getItemIdsForUser($this->getUser());
    
    return $this->render('item/list.html.twig', [
        'items' => $items,
        'wishlistItemIds' => $wishlistItemIds,
    ]);
}
```

#### Template Uses Simple Logic

```twig
{% for item in items %}
    <div id="item-{{ item.id }}" class="item-card">
        {{ item.name }}
        
        <a href="{{ path('wishlist_toggle', {id: item.id}) }}"
           data-turbo-frame="modal-frame"
           class="{{ item.id in wishlistItemIds ? 'wishlisted' : '' }}">
            {{ item.id in wishlistItemIds ? 'Remove from' : 'Add to' }} Wishlist
        </a>
    </div>
{% endfor %}
```

No Live Component needed for each item. State comes from server, updates via Turbo Stream.

---

## Pattern: Turbo Stream Responses

### Standard Stream Template

```twig
{# item/_delete_stream.html.twig #}

{# Remove the item from DOM #}
<turbo-stream action="remove" target="item-{{ deletedId }}"></turbo-stream>

{# Update counter #}
<turbo-stream action="update" target="items-count">
    <template>{{ count }} items</template>
</turbo-stream>

{# Close modal by clearing frame #}
<turbo-stream action="update" target="modal-frame">
    <template></template>
</turbo-stream>

{# Conditionally show empty state #}
{% if isEmpty %}
<turbo-stream action="update" target="items-list-container">
    <template>
        {% include 'item/_empty_state.html.twig' %}
    </template>
</turbo-stream>
{% endif %}
```

### Returning Streams from Controller

```php
use Symfony\UX\Turbo\TurboBundle;

public function delete(Request $request, Item $item): Response
{
    $id = $item->getId();
    $this->em->remove($item);
    $this->em->flush();
    
    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
    
    return $this->render('item/_delete_stream.html.twig', [
        'deletedId' => $id,
        'count' => $this->itemRepository->count([]),
        'isEmpty' => $this->itemRepository->count([]) === 0,
    ]);
}
```

### Returning Streams from Live Component

```php
#[LiveAction]
public function delete(): Response
{
    $id = $this->item->getId();
    $this->em->remove($this->item);
    $this->em->flush();
    
    return $this->render('item/_delete_stream.html.twig', [
        'deletedId' => $id,
        'count' => $this->itemRepository->count([]),
    ]);
}
```

---

## Gotchas and Anti-Patterns

### ❌ Don't: Wrap Everything in Turbo Frames

```twig
{# WRONG - unnecessary frames #}
{% for item in items %}
    <turbo-frame id="item-{{ item.id }}">
        <div class="item-card">...</div>
    </turbo-frame>
{% endfor %}
```

Only use frames when you need **frame navigation behavior**. For stream targets, just use `id`:

```twig
{# CORRECT - streams only need IDs #}
{% for item in items %}
    <div id="item-{{ item.id }}" class="item-card">...</div>
{% endfor %}
```

### ❌ Don't: Create Live Component for Each List Item

```twig
{# WRONG - N+1 problem #}
{% for item in items %}
    {{ component('WishlistButton', {item: item}) }}
{% endfor %}
```

Instead, fetch state once in controller and pass to template:

```twig
{# CORRECT - single query #}
{% for item in items %}
    <a class="{{ item.id in wishlistIds ? 'active' : '' }}">...</a>
{% endfor %}
```

### ❌ Don't: Forget Progressive Enhancement

```php
// WRONG - only works with Turbo
public function edit(Item $item): Response
{
    return $this->render('item/_edit_modal.html.twig', ['item' => $item]);
}
```

Always handle both cases:

```php
// CORRECT - works with and without JS
public function edit(Request $request, Item $item): Response
{
    $template = $request->headers->has('Turbo-Frame')
        ? 'item/_edit_modal.html.twig'
        : 'item/edit.html.twig';
    
    return $this->render($template, ['item' => $item]);
}
```

### ❌ Don't: Mix Concerns in Live Components

```php
// WRONG - component handling routing logic
#[LiveAction]
public function save(): Response
{
    if ($this->request->headers->has('Turbo-Frame')) {
        // ...
    }
}
```

Component should receive context as prop, not detect it:

```php
// CORRECT - context passed in
#[LiveProp]
public string $context = 'list';

#[LiveAction]
public function save(): Response
{
    return match($this->context) {
        'list' => $this->renderStream(...),
        'detail' => $this->redirect(...),
    };
}
```

### ❌ Don't: Listen for `turbo:frame-load` to Open Modal

```javascript
// WRONG - fires when frame is cleared too
data-action="turbo:frame-load->modal#open"
```

Instead, open on click and close when frame is empty:

```javascript
// CORRECT
open(event) {
    this.containerTarget.classList.remove('hidden')
}

frameLoaded(event) {
    if (this.frameTarget.innerHTML.trim() === '') {
        this.close()
    }
}
```

---

## When to Use Live Components

### ✅ Good Use Cases

1. **Complex forms with real-time validation**
   - Dependent dropdowns
   - Async validation (username availability)
   - Dynamic form fields (add/remove)

2. **Filter forms**
   - Multiple interdependent filters
   - Live preview of results count

3. **Single-item detail pages**
   - Complex interactions isolated to one entity
   - No N+1 concern

### ❌ Avoid For

1. **Repeated elements in lists** - Use Turbo Streams instead
2. **Simple open/close UI** - Use Stimulus
3. **Navigation** - Use Turbo Frames
4. **Simple forms** - Standard forms + Turbo Stream response

---

## File Structure Recommendation

```
templates/
├── base.html.twig                    # Contains global modal frame
├── item/
│   ├── list.html.twig                # Full list page
│   ├── show.html.twig                # Full detail page
│   ├── edit.html.twig                # Full edit page (non-JS fallback)
│   ├── _list_item.html.twig          # Single item partial
│   ├── _edit_modal.html.twig         # Modal wrapper for edit
│   ├── _delete_confirm_modal.html.twig
│   ├── _edit_stream.html.twig        # Turbo Stream for edit success
│   ├── _delete_stream.html.twig      # Turbo Stream for delete success
│   └── _empty_state.html.twig        # Empty list state

src/
├── Controller/
│   └── ItemController.php            # Thin, handles routing + request type
├── Twig/
│   └── Components/
│       ├── ItemForm.php              # Form logic + validation
│       └── ItemDeleteConfirm.php     # Delete confirmation logic

assets/
└── controllers/
    └── modal_controller.js           # Open/close, context injection
```

---

## Summary Checklist

When implementing a new feature:

- [ ] Single modal frame in `base.html.twig`?
- [ ] Links use `data-turbo-frame="modal-frame"`?
- [ ] Context passed via `data-modal-context`?
- [ ] Controller returns different templates for Turbo vs normal requests?
- [ ] Modal template wraps content in `<turbo-frame id="modal-frame">`?
- [ ] Full page template extends base and works standalone?
- [ ] Live Component receives context as `#[LiveProp]`?
- [ ] Success response returns Turbo Streams (for list) or redirect (for detail)?
- [ ] Stream clears modal frame to trigger close?
- [ ] List items have `id` attributes (not wrapped in frames)?
- [ ] List data fetched with single query, not per-item components?
