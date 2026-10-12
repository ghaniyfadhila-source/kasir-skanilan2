# Ticket 04: Menu Management (Category + Item)

## Objective
Admin CRUD for menu categories and items with drag-sort ordering, image upload, and stock tracking.

## Scope
- [ ] Controller: `Admin\MenuController` (nested resource: categories + items)
- [ ] Routes:
  - `admin.categories.*` (index, store, update, destroy, reorder)
  - `admin.menu-items.*` (index, create, store, edit, update, destroy, reorder)
- [ ] Views:
  - `admin/menu/index.blade.php` — Split view: left sidebar categories (draggable), right panel items table
  - `admin/menu/partials/category-form.blade.php` — Modal: name, sort_order, is_active
  - `admin/menu/partials/item-form.blade.php` — Modal: category select, name, price (cents), image upload, stock, is_active
- [ ] Drag-sort: Alpine.js + SortableJS (CDN) for categories and items within category
- [ ] Image upload:
  - Store in `storage/app/public/menu-images/`
  - Resize to max 800px width (Intervention Image / GD)
  - Serve via `/storage/menu-images/{filename}` (symlink `php artisan storage:link`)
- [ ] Stock: integer, -1 = unlimited, decrement on transaction (later ticket)
- [ ] Price input: Rupiah format (12.500) → store as cents (1250000)

## Acceptance Criteria
- Categories reorder persists `sort_order`
- Items reorder within category persists `sort_order`
- Image upload works, old image deleted on replace
- Price validation: > 0, integer cents
- Soft delete? No — hard delete with confirmation (check no transactions reference)

## Technical Notes
- `MenuItem::getPriceAttribute()` → returns cents, `getFormattedPriceAttribute()` → "Rp 12.500"
- `Category::scopeActive()` / `MenuItem::scopeActive()`
- Image filename: `{menu_item_id}_{timestamp}.{ext}`
- Use `intervention/image` or native GD (PHP 8.3 has GD)

## Dependencies
- Ticket 01 (Category, MenuItem models + migrations)
- Ticket 02 (admin shell)

## API Impact
- `/api/console/menu` returns nested structure: `{categories: [{items: [...]}]}` — only active