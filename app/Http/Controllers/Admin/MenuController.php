<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    // ==================== CATEGORIES ====================

    public function categoriesIndex(Request $request)
    {
        $categories = Category::withCount('menuItems')
            ->orderBy('sort_order')
            ->get();

        $selectedCategoryId = $categories->first()?->id;

        return view('admin.menu.index', compact('categories', 'selectedCategoryId'));
    }

    public function categoriesStore(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $category = Category::create([
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? Category::max('sort_order') + 1,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Log::channel('audit')->info('Category created', [
            'category_id' => $category->id,
            'name' => $category->name,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dibuat.',
            'category' => $category,
        ]);
    }

    public function categoriesUpdate(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? $category->sort_order,
            'is_active' => $validated['is_active'] ?? $category->is_active,
        ]);

        Log::channel('audit')->info('Category updated', [
            'category_id' => $category->id,
            'changes' => $validated,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil diperbarui.',
            'category' => $category,
        ]);
    }

    public function categoriesDestroy(Category $category)
    {
        if ($category->menuItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori tidak dapat dihapus karena masih memiliki item menu.',
            ], 422);
        }

        $categoryId = $category->id;
        $categoryName = $category->name;
        $category->delete();

        Log::channel('audit')->info('Category deleted', [
            'category_id' => $categoryId,
            'name' => $categoryName,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }

    public function categoriesReorder(Request $request)
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'uuid', 'exists:categories,id'],
        ]);

        foreach ($request->order as $index => $categoryId) {
            Category::where('id', $categoryId)->update(['sort_order' => $index + 1]);
        }

        Log::channel('audit')->info('Categories reordered', [
            'order' => $request->order,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Urutan kategori berhasil diperbarui.',
        ]);
    }

    // ==================== MENU ITEMS ====================

    public function itemsIndex(Request $request, Category $category = null)
    {
        $query = MenuItem::with('category')->latest();

        $selectedCategoryId = $request->filled('category_id') && $request->category_id !== 'all' 
            ? $request->category_id 
            : ($category?->id);

        if ($selectedCategoryId) {
            $query->where('category_id', $selectedCategoryId);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('category', fn($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $items = $query->paginate(15)->withQueryString();
        $categories = Category::orderBy('sort_order')->get();

        if ($request->ajax()) {
            return view('admin.menu.partials.items-table', compact('items', 'categories', 'selectedCategoryId'))->render();
        }

        return view('admin.menu.index', compact('items', 'categories', 'category', 'selectedCategoryId'));
    }

    public function itemsCreate()
    {
        $categories = Category::where('is_active', true)->orderBy('sort_order')->get();
        return view('admin.menu.partials.item-form', compact('categories'));
    }

    public function itemsStore(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:-1'],
            'image' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->uploadImage($request->file('image'));
        }

        $item = MenuItem::create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'price_cents' => $validated['price_cents'],
            'stock' => $validated['stock'],
            'image_url' => $imageUrl,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? MenuItem::where('category_id', $validated['category_id'])->max('sort_order') + 1,
        ]);

        Log::channel('audit')->info('Menu item created', [
            'item_id' => $item->id,
            'name' => $item->name,
            'category_id' => $item->category_id,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item menu berhasil dibuat.',
            'item' => $item->load('category'),
        ]);
    }

    public function itemsEdit(MenuItem $item)
    {
        $categories = Category::where('is_active', true)->orderBy('sort_order')->get();
        return view('admin.menu.partials.item-form', compact('item', 'categories'));
    }

    public function itemsUpdate(Request $request, MenuItem $item)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:-1'],
            'image' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $imageUrl = $item->image_url;
        if ($request->hasFile('image')) {
            // Delete old image
            if ($item->image_url) {
                $this->deleteImage($item->image_url);
            }
            $imageUrl = $this->uploadImage($request->file('image'));
        }

        $item->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'price_cents' => $validated['price_cents'],
            'stock' => $validated['stock'],
            'image_url' => $imageUrl,
            'is_active' => $validated['is_active'] ?? $item->is_active,
            'sort_order' => $validated['sort_order'] ?? $item->sort_order,
        ]);

        Log::channel('audit')->info('Menu item updated', [
            'item_id' => $item->id,
            'changes' => $validated,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item menu berhasil diperbarui.',
            'item' => $item->load('category'),
        ]);
    }

    public function itemsDestroy(MenuItem $item)
    {
        $itemId = $item->id;
        $itemName = $item->name;

        // Delete image if exists
        if ($item->image_url) {
            $this->deleteImage($item->image_url);
        }

        $item->delete();

        Log::channel('audit')->info('Menu item deleted', [
            'item_id' => $itemId,
            'name' => $itemName,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item menu berhasil dihapus.',
        ]);
    }

    public function itemsReorder(Request $request)
    {
        $request->validate([
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'order' => ['required', 'array'],
            'order.*' => ['required', 'uuid', 'exists:menu_items,id'],
        ]);

        foreach ($request->order as $index => $itemId) {
            MenuItem::where('id', $itemId)->update(['sort_order' => $index + 1]);
        }

        Log::channel('audit')->info('Menu items reordered', [
            'category_id' => $request->category_id,
            'order' => $request->order,
            'admin_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Urutan item menu berhasil diperbarui.',
        ]);
    }

    // ==================== HELPER METHODS ====================

    private function uploadImage($file): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('menu-images', $filename, 'public');

        // Resize image using GD (Intervention Image not installed by default)
        $this->resizeImage(storage_path('app/public/' . $path), 800);

        return $path;
    }

    private function deleteImage(string $path): void
    {
        Storage::disk('public')->delete($path);
    }

    private function resizeImage(string $path, int $maxWidth): void
    {
        if (!file_exists($path)) {
            return;
        }

        $imageInfo = getimagesize($path);
        if (!$imageInfo) {
            return;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $mime = $imageInfo['mime'];

        if ($width <= $maxWidth) {
            return; // No resize needed
        }

        $newHeight = intval($height * ($maxWidth / $width));

        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => null,
        };

        if (!$source) {
            return;
        }

        $dest = imagecreatetruecolor($maxWidth, $newHeight);

        // Preserve transparency for PNG/WebP
        if (in_array($mime, ['image/png', 'image/webp'])) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
        }

        imagecopyresampled($dest, $source, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

        match ($mime) {
            'image/jpeg' => imagejpeg($dest, $path, 90),
            'image/png' => imagepng($dest, $path, 9),
            'image/webp' => imagewebp($dest, $path, 90),
        };

        imagedestroy($source);
        imagedestroy($dest);
    }
}