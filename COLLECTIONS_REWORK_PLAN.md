# Puzzle Collections Rework Plan

## Overview
Rework the existing puzzle collection feature to support multiple collections per player with system-wide predefined collections and custom user collections.

## Progress Status
- ✅ **Step 1: Database Migration** - COMPLETED

---

## Step 1: Database Migration ✅ COMPLETED

### What was done:
- Created migration `Version20250812221620.php` to create `player_puzzle_collection_old` table
- Updated `PlayerPuzzleCollection` entity to use new table name
- Updated `GetPuzzleCollection.php` query to reference new table
- All 1606 existing records preserved and migrated successfully

### Files modified:
- `migrations/Version20250812221620.php`
- `src/Entity/PlayerPuzzleCollection.php`
- `src/Query/GetPuzzleCollection.php`

---

## Step 2: New Database Schema

### New Entities to Create:

#### PuzzleCollection Entity
```php
// src/Entity/PuzzleCollection.php
#[Entity]
class PuzzleCollection
{
    public UuidInterface $id;
    public Player $player;              // Owner of the collection
    public ?string $name;               // Null for system collections
    public CollectionType $type;        // enum: system/custom
    public ?SystemCollectionType $systemType; // enum for system collections
    public DateTimeImmutable $createdAt;
}
```

#### PuzzleCollectionItem Entity
```php
// src/Entity/PuzzleCollectionItem.php
#[Entity]
class PuzzleCollectionItem
{
    public UuidInterface $id;
    public PuzzleCollection $collection;
    public Puzzle $puzzle;
    public DateTimeImmutable $addedAt;
}
```

#### Enums to Create
```php
// src/Enum/CollectionType.php
enum CollectionType: string
{
    case SYSTEM = 'system';
    case CUSTOM = 'custom';
}

// src/Enum/SystemCollectionType.php
enum SystemCollectionType: string
{
    case MY_PUZZLE = 'my_puzzle';
    case WISHLIST = 'wishlist';
    case TODO_LIST = 'todo_list';
    case BORROWED = 'borrowed';
    case LENT_OUT = 'lent_out';
}
```

### Database Migration
Create migration to add new tables while preserving existing `player_puzzle_collection_old`.

---

## Step 3: System Collections

### Translation Keys to Add
```yaml
# translations/messages.en.yml
collections:
    system:
        my_puzzle: "My Puzzles"
        wishlist: "Wishlist"
        todo_list: "To Solve"
        borrowed: "Borrowed"
        lent_out: "Lent Out"
    actions:
        create_collection: "Create Collection"
        add_to_collection: "Add to Collection"
        choose_collection: "Choose Collection"

# translations/messages.cs.yml
collections:
    system:
        my_puzzle: "Moje puzzle"
        wishlist: "Seznam přání"
        todo_list: "K vyřešení"
        borrowed: "Vypůjčené"
        lent_out: "Půjčené"
    actions:
        create_collection: "Vytvořit sbírku"
        add_to_collection: "Přidat do sbírky"
        choose_collection: "Vybrat sbírku"
```

### System Collection Creation Service
Create service to ensure all players have system collections.

---

## Step 4: CQRS Implementation

### Commands to Create/Update:
- `AddPuzzleToCollection` (update existing)
- `RemovePuzzleFromCollection` (update existing)
- `CreateCustomCollection`
- `DeleteCustomCollection`
- `RenamePuzzleCollection`

### Queries to Create:
- `GetPlayerCollections` - Get all collections for a player
- `GetCollectionPuzzles` - Get puzzles in specific collection
- `GetPlayerSolvedPuzzles` - Virtual collection of solved puzzles
- Update `GetPuzzleCollection` to work with new structure

### Handlers:
Update existing handlers and create new ones for collection management.

---

## Step 5: Auto-removal Logic

### Event Listener
Create event listener that listens to `PuzzleSolved` event and removes puzzle from:
- Todo List collection
- Wishlist collection

```php
// src/EventListener/RemoveSolvedPuzzleFromCollections.php
#[AsEventListener(event: PuzzleSolved::class)]
readonly final class RemoveSolvedPuzzleFromCollections
{
    // Remove puzzle from todo_list and wishlist collections
}
```

---

## Step 6: UI/Controllers

### Controllers to Create/Update:

#### MyCollectionsController
- Route: `/my-collections`
- Show overview of all player's collections
- Special "Solved" link to solved puzzles view

#### CollectionDetailController  
- Route: `/collection/{collectionId}`
- Show puzzles in specific collection
- Add/remove puzzle functionality

#### Update AddPuzzleToCollectionController
- Add dropdown to select target collection
- Support creating new custom collections inline

#### CreateCollectionController
- Route: `/create-collection`
- Form to create custom collections

### Templates to Create/Update:
- `my_collections.html.twig` - Collections overview
- `collection_detail.html.twig` - Collection puzzle listing
- `_collection_selector.html.twig` - Dropdown component
- Update `puzzle_detail.html.twig` for collection selector

---

## Step 7: Migration Command

### Console Command
Create command to migrate existing `player_puzzle_collection_old` data:

```php
// src/Command/MigrateCollectionsCommand.php
php bin/console app:migrate-collections
```

This command will:
1. Create system collections for all existing players
2. Move all puzzles from `player_puzzle_collection_old` to "My Puzzles" system collection
3. Provide progress feedback and error handling

---

## Step 8: Testing & Validation

### Tests to Create/Update:
- Unit tests for new entities and enums
- Integration tests for CQRS commands/queries  
- Controller tests for new endpoints
- Event listener tests for auto-removal logic
- Migration command tests

### Validation Checklist:
- [ ] All existing collection functionality preserved
- [ ] New multi-collection system works
- [ ] System collections created for all players
- [ ] Auto-removal on puzzle solve works
- [ ] UI allows collection management
- [ ] Migration command works correctly
- [ ] All tests pass
- [ ] PHPStan analysis clean
- [ ] Performance impact minimal

---

## Implementation Notes

### Database Strategy:
1. Keep `player_puzzle_collection_old` table until migration complete
2. New system runs parallel to old system initially
3. Migration command moves data when ready
4. Drop old table in final cleanup migration

### Suggested Implementation Order:
1. ✅ Database migration (COMPLETED)
2. Create new entities and enums
3. Create database migration for new tables
4. Implement CQRS layer
5. Create UI and controllers
6. Add auto-removal event listener
7. Create migration command
8. Comprehensive testing
9. Deploy and run migration command
10. Clean up old table and code

### Risk Mitigation:
- Each step deployable independently
- Old system remains functional until migration
- Extensive testing at each step
- Console command allows controlled data migration
- Rollback plan for each migration step

---

## Files to Modify/Create

### New Files:
- `src/Entity/PuzzleCollection.php`
- `src/Entity/PuzzleCollectionItem.php` 
- `src/Enum/CollectionType.php`
- `src/Enum/SystemCollectionType.php`
- `src/Repository/PuzzleCollectionRepository.php`
- `src/Repository/PuzzleCollectionItemRepository.php`
- `src/Query/GetPlayerCollections.php`
- `src/Query/GetCollectionPuzzles.php`
- `src/Query/GetPlayerSolvedPuzzles.php`
- `src/Message/CreateCustomCollection.php`
- `src/Message/DeleteCustomCollection.php`
- `src/MessageHandler/CreateCustomCollectionHandler.php`
- `src/MessageHandler/DeleteCustomCollectionHandler.php`
- `src/Controller/MyCollectionsController.php`
- `src/Controller/CollectionDetailController.php`
- `src/Controller/CreateCollectionController.php`
- `src/EventListener/RemoveSolvedPuzzleFromCollections.php`
- `src/Command/MigrateCollectionsCommand.php`
- `src/Service/SystemCollectionManager.php`
- `templates/my_collections.html.twig`
- `templates/collection_detail.html.twig`
- `templates/_collection_selector.html.twig`
- `migrations/Version[NEW].php` (for new tables)

### Files to Update:
- `src/Message/AddPuzzleToCollection.php`
- `src/Message/RemovePuzzleFromCollection.php`
- `src/MessageHandler/AddPuzzleToCollectionHandler.php`
- `src/MessageHandler/RemovePuzzleFromCollectionHandler.php`
- `src/Controller/AddPuzzleToCollectionController.php`
- `src/Controller/RemovePuzzleFromCollectionController.php`
- `templates/puzzle_detail.html.twig`
- `translations/messages.en.yml`
- `translations/messages.cs.yml`

This plan provides a complete roadmap for implementing the enhanced puzzle collections feature while maintaining backward compatibility and minimizing risk during deployment.