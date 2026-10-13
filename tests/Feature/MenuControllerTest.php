<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use App\Models\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MenuControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->cashier = User::factory()->create([
            'email' => 'cashier@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
        ]);

        Storage::fake('public');
    }

    // ==================== CATEGORIES ====================

    public function test_admin_can_access_menu_index(): void
    {
        Category::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('admin.menu.index'));

        $response->assertStatus(200);
        $response->assertSee('Kelola Menu');
    }

    public function test_guest_cannot_access_menu_index(): void
    {
        $response = $this->get(route('admin.menu.index'));
        $response->assertRedirect();
    }

    public function test_cashier_cannot_access_menu_index(): void
    {
        $response = $this->actingAs($this->cashier)->get(route('admin.menu.index'));
        $response->assertStatus(403);
    }

    public function test_admin_can_create_category(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.menu.categories.store'), [
            'name' => 'Makanan',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('categories', ['name' => 'Makanan']);
    }

    public function test_admin_cannot_create_duplicate_category(): void
    {
        Category::factory()->create(['name' => 'Makanan']);

        $response = $this->actingAs($this->admin)->post(route('admin.menu.categories.store'), [
            'name' => 'Makanan',
        ], [
            'Accept' => 'application/json'
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_admin_can_update_category(): void
    {
        $category = Category::factory()->create(['name' => 'Old Name', 'sort_order' => 1]);

        $response = $this->actingAs($this->admin)->put(route('admin.menu.categories.update', $category), [
            'name' => 'New Name',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response->assertJson(['success' => true]);
        $category->refresh();
        $this->assertEquals('New Name', $category->name);
        $this->assertEquals(2, $category->sort_order);
    }

    public function test_admin_can_delete_category_without_items(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->admin)->delete(route('admin.menu.categories.destroy', $category));

        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_admin_cannot_delete_category_with_items(): void
    {
        $category = Category::factory()->create();
        MenuItem::factory()->create(['category_id' => $category->id]);

        $response = $this->actingAs($this->admin)->delete(route('admin.menu.categories.destroy', $category));

        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_can_reorder_categories(): void
    {
        $cat1 = Category::factory()->create(['sort_order' => 1]);
        $cat2 = Category::factory()->create(['sort_order' => 2]);
        $cat3 = Category::factory()->create(['sort_order' => 3]);

        $response = $this->actingAs($this->admin)->post(route('admin.menu.categories.reorder'), [
            'order' => [$cat3->id, $cat1->id, $cat2->id],
        ]);

        $response->assertJson(['success' => true]);

        $cat1->refresh();
        $cat2->refresh();
        $cat3->refresh();

        $this->assertEquals(2, $cat1->sort_order);
        $this->assertEquals(3, $cat2->sort_order);
        $this->assertEquals(1, $cat3->sort_order);
    }

    // ==================== MENU ITEMS ====================

    public function test_admin_can_access_items_index(): void
    {
        $category = Category::factory()->create();
        MenuItem::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->actingAs($this->admin)->get(route('admin.menu.items.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_create_menu_item(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('admin.menu.items.store'), [
            'category_id' => $category->id,
            'name' => 'Nasi Goreng',
            'price_cents' => 1500000,
            'stock' => 20,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('menu_items', [
            'name' => 'Nasi Goreng',
            'price_cents' => 1500000,
            'stock' => 20,
        ]);
    }

    public function test_admin_can_create_menu_item_with_unlimited_stock(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('admin.menu.items.store'), [
            'category_id' => $category->id,
            'name' => 'Air Mineral',
            'price_cents' => 500000,
            'stock' => -1,
            'is_active' => true,
        ]);

        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('menu_items', [
            'name' => 'Air Mineral',
            'stock' => -1,
        ]);
    }

    public function test_admin_can_update_menu_item(): void
    {
        $category = Category::factory()->create();
        $item = MenuItem::factory()->create([
            'category_id' => $category->id,
            'name' => 'Old Name',
            'price_cents' => 1000000,
            'stock' => 10,
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.menu.items.update', $item), [
            'category_id' => $category->id,
            'name' => 'New Name',
            'price_cents' => 2000000,
            'stock' => 15,
            'is_active' => true,
        ]);

        $response->assertJson(['success' => true]);
        $item->refresh();
        $this->assertEquals('New Name', $item->name);
        $this->assertEquals(2000000, $item->price_cents);
        $this->assertEquals(15, $item->stock);
    }

    public function test_admin_can_delete_menu_item(): void
    {
        $category = Category::factory()->create();
        $item = MenuItem::factory()->create(['category_id' => $category->id]);

        $response = $this->actingAs($this->admin)->delete(route('admin.menu.items.destroy', $item));

        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('menu_items', ['id' => $item->id]);
    }

    public function test_admin_can_reorder_menu_items(): void
    {
        $category = Category::factory()->create();
        $item1 = MenuItem::factory()->create(['category_id' => $category->id, 'sort_order' => 1]);
        $item2 = MenuItem::factory()->create(['category_id' => $category->id, 'sort_order' => 2]);
        $item3 = MenuItem::factory()->create(['category_id' => $category->id, 'sort_order' => 3]);

        $response = $this->actingAs($this->admin)->post(route('admin.menu.items.reorder'), [
            'category_id' => $category->id,
            'order' => [$item3->id, $item1->id, $item2->id],
        ]);

        $response->assertJson(['success' => true]);

        $item1->refresh();
        $item2->refresh();
        $item3->refresh();

        $this->assertEquals(2, $item1->sort_order);
        $this->assertEquals(3, $item2->sort_order);
        $this->assertEquals(1, $item3->sort_order);
    }

    public function test_items_index_filters_by_category(): void
    {
        $cat1 = Category::factory()->create(['name' => 'Makanan']);
        $cat2 = Category::factory()->create(['name' => 'Minuman']);
        MenuItem::factory()->create(['category_id' => $cat1->id, 'name' => 'Nasi Goreng']);
        MenuItem::factory()->create(['category_id' => $cat2->id, 'name' => 'Es Teh']);

        $response = $this->actingAs($this->admin)->get(route('admin.menu.items.index', ['category_id' => $cat1->id]), [
            'X-Requested-With' => 'XMLHttpRequest'
        ]);

        $response->assertStatus(200);
        $response->assertSee('Nasi Goreng');
        $response->assertDontSee('Es Teh');
    }

    public function test_items_index_search(): void
    {
        $category = Category::factory()->create();
        MenuItem::factory()->create(['category_id' => $category->id, 'name' => 'Nasi Goreng Spesial']);
        MenuItem::factory()->create(['category_id' => $category->id, 'name' => 'Nasi Goreng Biasa']);
        MenuItem::factory()->create(['category_id' => $category->id, 'name' => 'Mie Goreng']);

        $response = $this->actingAs($this->admin)->get(route('admin.menu.items.index', ['search' => 'Spesial']), [
            'X-Requested-With' => 'XMLHttpRequest'
        ]);

        $response->assertSee('Nasi Goreng Spesial');
        $response->assertDontSee('Nasi Goreng Biasa');
        $response->assertDontSee('Mie Goreng');
    }

    public function test_category_sort_order_auto_increment(): void
    {
        Category::factory()->create(['sort_order' => 1]);
        Category::factory()->create(['sort_order' => 2]);

        $response = $this->actingAs($this->admin)->post(route('admin.menu.categories.store'), [
            'name' => 'New Category',
        ]);

        $response->assertJson(['success' => true]);
        $newCat = Category::where('name', 'New Category')->first();
        $this->assertEquals(3, $newCat->sort_order);
    }

    public function test_menu_item_sort_order_auto_increment_per_category(): void
    {
        $category = Category::factory()->create();
        MenuItem::factory()->create(['category_id' => $category->id, 'sort_order' => 1]);
        MenuItem::factory()->create(['category_id' => $category->id, 'sort_order' => 2]);

        $response = $this->actingAs($this->admin)->post(route('admin.menu.items.store'), [
            'category_id' => $category->id,
            'name' => 'New Item',
            'price_cents' => 1000000,
            'stock' => 5,
        ]);

        $response->assertJson(['success' => true]);
        $newItem = MenuItem::where('name', 'New Item')->first();
        $this->assertEquals(3, $newItem->sort_order);
    }
}