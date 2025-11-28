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
│ - Route handling (GET and POST)                                 │
│ - Request type detection (Turbo Frame vs normal)                │
│ - Form validation and processing                                │
│ - Returns Turbo Streams on success                              │
│ - Returns appropriate template on GET or validation failure     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 2: Templates                                              │
│ - Modal wrapper (_modal.html.twig) for Turbo Frame requests     │
│ - Full page wrapper (edit.html.twig) for normal requests        │
│ - Stream templates (_success_stream.html.twig)                  │
│ - Both can optionally embed Live Component for real-time UX     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 3: Live Component (OPTIONAL)                              │
│ - Real-time validation feedback only                            │
│ - Does NOT handle form submission                               │
│ - Form action points to Controller, not LiveAction              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 4: Turbo Streams (returned by Controller)                 │
│ - Update specific DOM elements                                  │
│ - Remove deleted items                                          │
│ - Update counters                                               │
│ - Clear modal frame (triggers close)                            │
└─────────────────────────────────────────────────────────────────┘
```

### Critical Constraint: Live Components Cannot Return Turbo Streams

**Live Components and Turbo Streams are separate technologies that don't integrate directly.** When a LiveAction returns a Turbo Stream response, the Live Component JavaScript doesn't handle the `text/vnd.turbo-stream.html` content type correctly—it expects HTML for morphing updates.

This means:
- ❌ `#[LiveAction]` methods cannot return Turbo Stream responses
- ✅ Controllers can return Turbo Stream responses
- ✅ Live Components can be used for real-time validation UI
- ✅ Form submission should go to Controller, not LiveAction

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
     data-controller="modal">
    
    <div class="modal-backdrop" data-action="click->modal#backdropClick"></div>
    
    <div class="modal-content">
        <!-- THE ONLY TURBO FRAME NEEDED FOR MODALS -->
        <turbo-frame id="modal-frame" data-modal-target="frame">
        </turbo-frame>
    </div>
</div>
```

### Triggering the Modal

**Important:** Do NOT use `data-action="click->modal#open"` — it conflicts with Turbo's click handling. Instead, the Stimulus controller listens for `turbo:before-fetch-request` on the frame.

```twig
{# Any page - just target the frame, no click action needed #}
<a href="{{ path('item_edit', {id: item.id}) }}"
   data-turbo-frame="modal-frame">
    Edit
</a>
```

### Stimulus Modal Controller

```javascript
// assets/controllers/modal_controller.js
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    static targets = ["frame"]
    
    connect() {
        // Open modal when frame starts fetching content
        this.frameTarget.addEventListener('turbo:before-fetch-request', () => {
            this.open()
        })
        
        // Watch for frame becoming empty (close trigger)
        this.observer = new MutationObserver(() => {
            if (this.frameTarget.innerHTML.trim() === '') {
                this.close()
            }
        })
        this.observer.observe(this.frameTarget, { childList: true })
    }
    
    disconnect() {
        this.observer?.disconnect()
    }
    
    open() {
        this.element.classList.remove('hidden')
        document.body.classList.add('overflow-hidden')
    }
    
    close() {
        this.element.classList.add('hidden')
        document.body.classList.remove('overflow-hidden')
        this.frameTarget.innerHTML = ''
    }
    
    // Close on backdrop click
    backdropClick(event) {
        if (event.target === event.currentTarget) {
            this.close()
        }
    }
    
    // Close on Escape key (add data-action="keydown.esc@window->modal#closeOnEscape" to body)
    closeOnEscape(event) {
        this.close()
    }
}
```

### Modal Content Response

**Option A: Without Live Component (simpler)**

```twig
{# item/_edit_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Edit {{ item.name }}</h2>
        <button type="button" data-action="modal#close">&times;</button>
    </div>
    
    <div class="modal-body">
        {{ form_start(form, {
            action: path('item_edit', {id: item.id}),
            attr: {'data-turbo-frame': 'modal-frame'}
        }) }}
            {{ form_row(form.name) }}
            {{ form_row(form.description) }}
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="modal#close">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        {{ form_end(form) }}
    </div>
</turbo-frame>
```

**Option B: With Live Component (real-time validation)**

```twig
{# item/_edit_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Edit {{ item.name }}</h2>
        <button type="button" data-action="modal#close">&times;</button>
    </div>
    
    <div class="modal-body">
        {# Live Component for real-time validation, but form submits to CONTROLLER #}
        {{ component('ItemEditForm', {
            item: item,
            formAction: path('item_edit', {id: item.id})
        }) }}
    </div>
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

### Solution: Pass Context via Hidden Field

#### Step 1: Include Context in Form Template

```twig
{# item/_delete_confirm_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Delete {{ item.name }}?</h2>
    </div>
    
    <form action="{{ path('item_delete', {id: item.id}) }}" method="post">
        <input type="hidden" name="_token" value="{{ csrf_token('delete' ~ item.id) }}">
        <input type="hidden" name="context" value="{{ context }}">
        
        <p>Are you sure you want to delete this item?</p>
        
        <button type="button" data-action="modal#close">Cancel</button>
        <button type="submit">Delete</button>
    </form>
</turbo-frame>
```

#### Step 2: Controller Passes Context When Rendering Modal

```php
#[Route('/item/{id}/delete/confirm', name: 'item_delete_confirm', methods: ['GET'])]
public function deleteConfirm(Request $request, Item $item): Response
{
    $context = $request->query->get('context', 'list');
    
    return $this->render('item/_delete_confirm_modal.html.twig', [
        'item' => $item,
        'context' => $context,
    ]);
}
```

#### Step 3: Controller Uses Context for Response

```php
#[Route('/item/{id}/delete', name: 'item_delete', methods: ['POST'])]
public function delete(Request $request, Item $item, EntityManagerInterface $em): Response
{
    $context = $request->request->get('context', 'list');
    $id = $item->getId();
    
    $em->remove($item);
    $em->flush();
    
    // Different response based on context
    if ($context === 'detail') {
        $this->addFlash('success', 'Item deleted.');
        return $this->redirectToRoute('item_list', [], Response::HTTP_SEE_OTHER);
    }
    
    // List context: return Turbo Streams
    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
    return $this->render('item/_delete_stream.html.twig', [
        'deletedId' => $id,
        'count' => $this->itemRepository->count([]),
        'isEmpty' => $this->itemRepository->count([]) === 0,
    ]);
}
```

#### Step 4: Links Include Context

```twig
{# list.html.twig #}
<a href="{{ path('item_delete_confirm', {id: item.id, context: 'list'}) }}"
   data-turbo-frame="modal-frame">Delete</a>

{# detail.html.twig #}
<a href="{{ path('item_delete_confirm', {id: item.id, context: 'detail'}) }}"
   data-turbo-frame="modal-frame">Delete</a>
```
```

---

## Pattern: Progressive Enhancement

### Principle

Every feature must work without JavaScript. Turbo and Stimulus enhance, not enable.

### Complete Controller Pattern (GET + POST in Single Action)

This is the recommended pattern — a single controller action handles:
- GET requests (initial form load)
- POST requests (form submission)
- Turbo Frame requests (modal)
- Normal requests (full page)
- Validation failures (re-render form with errors)
- Success (Turbo Streams or redirect)

```php
<?php
// src/Controller/ItemController.php

namespace App\Controller;

use App\Entity\Item;
use App\Form\ItemType;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\UX\Turbo\TurboBundle;

class ItemController extends AbstractController
{
    #[Route('/item/{id}/edit', name: 'item_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request, 
        Item $item, 
        EntityManagerInterface $em,
        ItemRepository $itemRepository
    ): Response {
        $form = $this->createForm(ItemType::class, $item);
        $form->handleRequest($request);
        
        $isTurboFrameRequest = $request->headers->has('Turbo-Frame');
        
        // ─────────────────────────────────────────────────────────
        // FORM SUBMITTED
        // ─────────────────────────────────────────────────────────
        if ($form->isSubmitted()) {
            
            // ✅ VALID - Save and return appropriate response
            if ($form->isValid()) {
                $em->flush();
                
                // Turbo request → Return Turbo Streams
                if ($isTurboFrameRequest) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                    
                    return $this->render('item/_edit_success_stream.html.twig', [
                        'item' => $item,
                    ]);
                }
                
                // Normal request → Redirect
                $this->addFlash('success', 'Item updated successfully!');
                return $this->redirectToRoute('item_list', [], Response::HTTP_SEE_OTHER);
            }
            
            // ❌ INVALID - Re-render form with errors
            // For Turbo Frame: modal stays open with errors
            // For Normal: full page with errors
        }
        
        // ─────────────────────────────────────────────────────────
        // INITIAL GET REQUEST (or invalid POST)
        // ─────────────────────────────────────────────────────────
        
        // Turbo Frame request → Return just the modal content
        if ($isTurboFrameRequest) {
            return $this->render('item/_edit_modal.html.twig', [
                'item' => $item,
                'form' => $form,
            ]);
        }
        
        // Normal request → Return full page
        return $this->render('item/edit.html.twig', [
            'item' => $item,
            'form' => $form,
        ]);
    }
}
```

### Request Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         FORM SUBMISSION FLOW                                │
└─────────────────────────────────────────────────────────────────────────────┘

Click "Edit" link
       │
       ▼
┌──────────────────────────────────────────────────────────────────────────┐
│ GET /item/1/edit                                                          │
│                                                                           │
│ Controller detects:                                                       │
│   - Turbo-Frame header? → Return _modal.html.twig (just frame content)   │
│   - Normal request?     → Return edit.html.twig (full page)              │
└──────────────────────────────────────────────────────────────────────────┘
       │
       ▼
Modal opens with form (or full page loads)
       │
       ▼
User fills form, clicks Submit
       │
       ▼
┌──────────────────────────────────────────────────────────────────────────┐
│ POST /item/1/edit                                                         │
│                                                                           │
│ Controller validates form:                                                │
│                                                                           │
│   INVALID?                                                                │
│     - Turbo-Frame? → Return _modal.html.twig with errors (modal stays)   │
│     - Normal?      → Return edit.html.twig with errors                   │
│                                                                           │
│   VALID?                                                                  │
│     - Turbo request? → Return Turbo Streams (update DOM + close modal)   │
│     - Normal?        → Redirect to list                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Templates

#### Modal Template (Turbo Frame Response)

```twig
{# templates/item/_edit_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Edit {{ item.name }}</h2>
        <button type="button" data-action="modal#close">&times;</button>
    </div>
    
    <div class="modal-body">
        {{ form_start(form, {
            action: path('item_edit', {id: item.id}),
            attr: {'data-turbo-frame': 'modal-frame'}
        }) }}
            {{ form_row(form.name) }}
            {{ form_row(form.description) }}
            {{ form_row(form.price) }}
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="modal#close">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Save Changes
                </button>
            </div>
        {{ form_end(form) }}
    </div>
</turbo-frame>
```

#### Full Page Template (Non-JS Fallback)

```twig
{# templates/item/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <div class="container">
        <h1>Edit {{ item.name }}</h1>
        
        {{ form_start(form) }}
            {{ form_row(form.name) }}
            {{ form_row(form.description) }}
            {{ form_row(form.price) }}
            
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="{{ path('item_list') }}" class="btn btn-secondary">Cancel</a>
        {{ form_end(form) }}
    </div>
{% endblock %}
```

#### Turbo Stream Success Response

```twig
{# templates/item/_edit_success_stream.html.twig #}

{# 1. Update the item in the list #}
<turbo-stream action="replace" target="item-{{ item.id }}">
    <template>
        {% include 'item/_list_item.html.twig' with {item: item} %}
    </template>
</turbo-stream>

{# 2. Close modal by clearing the frame #}
<turbo-stream action="update" target="modal-frame">
    <template></template>
</turbo-stream>

{# 3. Optional: Show flash message #}
<turbo-stream action="prepend" target="flash-messages">
    <template>
        <div class="alert alert-success alert-dismissible fade show">
            Item updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </template>
</turbo-stream>
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
    
    // Check if this is a Turbo request
    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat() 
        || $request->headers->has('Turbo-Frame')) {
        
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
        
        return $this->render('item/_delete_stream.html.twig', [
            'deletedId' => $id,
            'count' => $this->itemRepository->count([]),
            'isEmpty' => $this->itemRepository->count([]) === 0,
        ]);
    }
    
    // Non-Turbo request: redirect
    $this->addFlash('success', 'Item deleted.');
    return $this->redirectToRoute('item_list', [], Response::HTTP_SEE_OTHER);
}
```

### ⚠️ Important: Live Components CANNOT Return Turbo Streams

Do NOT attempt to return Turbo Streams from a LiveAction:

```php
// ❌ THIS DOES NOT WORK
#[LiveAction]
public function delete(): Response
{
    // ... delete logic ...
    
    // This will NOT work - Live Component JS expects HTML, not streams
    return $this->render('item/_delete_stream.html.twig', [...]);
}
```

Instead, have forms submit to a Controller, not to LiveAction. See "Pattern: Form with Optional Live Component" below.
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

### ❌ Don't: Expect LiveAction to Return Turbo Streams

```php
// WRONG - Live Components cannot return Turbo Streams
#[LiveAction]
public function save(): Response
{
    // ... save logic ...
    return $this->render('item/_update_stream.html.twig', [...]);
}
```

Instead, have the form submit to a Controller:

```php
// CORRECT - form action points to controller, not LiveAction
// In your component template:
{{ form_start(form, {action: path('item_edit', {id: item.id})}) }}

// Controller handles the POST and returns Turbo Streams
```
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

## Pattern: Form Validation and Modal Behavior

### The Problem

When a form in a modal has validation errors, the modal must stay open and display errors. It should only close on successful submission.

### How It Works (Controller-Based)

The modal closes when `modal-frame` becomes empty. The Controller controls what gets returned:

- **Validation fails** → Re-render modal template with errors → Frame stays populated → Modal stays open
- **Validation passes** → Return Turbo Stream that clears frame → Modal closes

### Controller Handles Everything

```php
#[Route('/item/{id}/edit', name: 'item_edit', methods: ['GET', 'POST'])]
public function edit(Request $request, Item $item, EntityManagerInterface $em): Response
{
    $form = $this->createForm(ItemType::class, $item);
    $form->handleRequest($request);
    
    $isTurboFrameRequest = $request->headers->has('Turbo-Frame');
    
    if ($form->isSubmitted() && $form->isValid()) {
        $em->flush();
        
        if ($isTurboFrameRequest) {
            // Success: return Turbo Streams (updates DOM, clears modal)
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            return $this->render('item/_edit_success_stream.html.twig', [
                'item' => $item,
            ]);
        }
        
        return $this->redirectToRoute('item_list', [], Response::HTTP_SEE_OTHER);
    }
    
    // GET request OR validation failed: render form
    // For Turbo Frame: modal stays open with errors
    $template = $isTurboFrameRequest 
        ? 'item/_edit_modal.html.twig' 
        : 'item/edit.html.twig';
    
    return $this->render($template, [
        'item' => $item,
        'form' => $form,
    ]);
}
```

### Flow Diagram

```
User clicks Save
       │
       ▼
┌─────────────────────────────────────┐
│ Controller handles POST             │
└─────────────────────────────────────┘
       │
       ├── Validation FAILS
       │         │
       │         ▼
       │   Return: _modal.html.twig with form errors
       │   modal-frame content = <form with errors>
       │   Modal stays OPEN ✓
       │
       └── Validation PASSES
                 │
                 ▼
           Return: Turbo Streams (clears modal-frame)
           modal-frame content = empty
           Stimulus detects → Modal CLOSES ✓
```

### Optional: Add Live Component for Real-Time Validation

If you want real-time validation feedback (errors as user types), add a Live Component. **But the form still submits to the Controller, not to a LiveAction.**

#### Live Component (Validation UI Only)

```php
<?php
// src/Twig/Components/ItemEditForm.php

#[AsLiveComponent]
class ItemEditForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    
    #[LiveProp]
    public Item $item;
    
    #[LiveProp]
    public string $formAction = '';  // Controller URL
    
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(ItemType::class, $this->item, [
            // Form submits to CONTROLLER, not to LiveAction
            'action' => $this->formAction,
        ]);
    }
    
    // NO #[LiveAction] for saving!
    // Form POST goes to the controller which returns Turbo Streams
}
```

#### Component Template

```twig
{# templates/components/ItemEditForm.html.twig #}
<div {{ attributes }}>
    {{ form_start(form, {attr: {'data-turbo-frame': 'modal-frame'}}) }}
        
        {# data-model enables real-time validation on change #}
        <div class="mb-3">
            {{ form_label(form.name) }}
            {{ form_widget(form.name, {attr: {'data-model': 'on(change)|*'}}) }}
            {{ form_errors(form.name) }}
        </div>
        
        <div class="mb-3">
            {{ form_label(form.description) }}
            {{ form_widget(form.description, {attr: {'data-model': 'on(change)|*'}}) }}
            {{ form_errors(form.description) }}
        </div>
        
        <button type="submit">Save</button>
    {{ form_end(form) }}
</div>
```

#### Modal Template Uses Component

```twig
{# templates/item/_edit_modal.html.twig #}
<turbo-frame id="modal-frame">
    <div class="modal-header">
        <h2>Edit {{ item.name }}</h2>
        <button type="button" data-action="modal#close">&times;</button>
    </div>
    
    {{ component('ItemEditForm', {
        item: item,
        formAction: path('item_edit', {id: item.id})
    }) }}
</turbo-frame>
```

### Key Insight

> **The form's `action` attribute determines where it submits.** Set it to your Controller route, not to a LiveAction. The Live Component provides real-time validation UI, but the Controller handles the actual submission and returns Turbo Streams.

---

## When to Use Live Components

### ✅ Good Use Cases

1. **Real-time form validation UI** (not form submission handling)
    - Show errors as user types
    - Validate on field blur
    - But form still submits to Controller

2. **Dependent form fields**
    - Dynamic dropdowns that depend on other selections
    - Form fields that appear/hide based on other inputs

3. **Filter forms with live preview**
    - Show count of results as filters change
    - Update preview without page reload

4. **Complex single-entity interactions**
    - Detail pages with many interactive features
    - No N+1 concern (single entity)

### ❌ Avoid For

1. **Form submission handling** - Controllers should handle POST and return Turbo Streams
2. **Repeated elements in lists** - Use simple Twig + Turbo Streams
3. **Simple open/close UI** - Use Stimulus
4. **Navigation** - Use Turbo Frames
5. **Returning Turbo Streams** - Live Components can't do this properly

### Key Principle

> **Live Components are for UI interactivity, not for handling form submissions or returning Turbo Streams.** Use them to enhance the user experience with real-time feedback, but let Controllers handle the actual business logic and response generation.

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
│   ├── _edit_success_stream.html.twig    # Turbo Stream for edit success
│   ├── _delete_success_stream.html.twig  # Turbo Stream for delete success
│   └── _empty_state.html.twig        # Empty list state
├── components/                       # Live Component templates (optional)
│   └── ItemEditForm.html.twig        # Real-time validation UI only

src/
├── Controller/
│   └── ItemController.php            # Handles GET, POST, returns Turbo Streams
├── Twig/
│   └── Components/
│       └── ItemEditForm.php          # Optional: real-time validation UI only
│                                     # NO LiveAction for save!

assets/
└── controllers/
    └── modal_controller.js           # Open/close modal
```

---

## Summary Checklist

When implementing a new feature:

### Modal Setup
- [ ] Single modal frame in `base.html.twig` with Stimulus controller
- [ ] Modal controller listens to `turbo:before-fetch-request` to open
- [ ] Modal controller uses MutationObserver to close when frame is empty

### Links and Navigation
- [ ] Links use `data-turbo-frame="modal-frame"` (no click action needed)
- [ ] Context passed via query parameter: `path('route', {id: id, context: 'list'})`

### Controller Pattern
- [ ] Single controller action handles both GET and POST
- [ ] Controller detects `Turbo-Frame` header for request type
- [ ] Returns modal template for Turbo Frame GET requests
- [ ] Returns full page template for normal GET requests
- [ ] Returns Turbo Streams for successful Turbo Frame POST
- [ ] Returns redirect for successful normal POST
- [ ] Returns modal template with errors for invalid Turbo Frame POST

### Templates
- [ ] Modal template wraps content in `<turbo-frame id="modal-frame">`
- [ ] Form action points to controller route (not to LiveAction)
- [ ] Form includes `data-turbo-frame="modal-frame"` attribute
- [ ] Full page template extends base and works standalone (progressive enhancement)

### Turbo Streams
- [ ] Stream template updates relevant DOM elements
- [ ] Stream clears `modal-frame` to trigger close
- [ ] Stream returned from Controller (not from Live Component)

### Live Components (Optional)
- [ ] Used only for real-time validation UI
- [ ] Form action points to Controller, NOT to LiveAction
- [ ] NO `#[LiveAction]` methods that return Turbo Streams

### List Performance
- [ ] List data fetched with single query in controller
- [ ] State (wishlist, etc.) fetched as ID arrays, not per-item queries
- [ ] List items have `id` attributes for stream targeting
- [ ] No Live Components for repeated list elements
