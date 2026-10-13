<x-layouts.app :title="'Kelola Menu'">
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Kelola Menu</h1>
                <p class="mt-1 text-gray-600">Kategori dan item menu kantin</p>
            </div>
        </div>

        <!-- Main Layout: Categories Sidebar + Items Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="flex flex-col lg:flex-row">
                <!-- Categories Sidebar -->
                <div class="lg:w-64 border-r border-gray-200 bg-gray-50 p-4" x-data="categoriesSidebar()">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">Kategori</h2>
                        <button @click="openCategoryModal()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">+ Tambah</button>
                    </div>

                    <div x-show="loading" x-transition class="text-center py-8 text-gray-500">
                        Memuat kategori...
                    </div>

                    <ul x-show="!loading" class="space-y-1" x-ref="categoryList">
                        <template x-for="cat in categories" :key="cat.id">
                            <li :class="{ 'bg-white': activeCategoryId == cat.id }">
                                <button @click="selectCategory(cat.id)"
                                        :data-category-id="cat.id"
                                        :class="activeCategoryId == cat.id ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100'"
                                        class="w-full px-3 py-2 rounded-lg text-left transition-colors flex items-center justify-between gap-2">
                                    <span class="truncate" x-text="cat.name"></span>
                                    <span class="text-xs text-gray-400 whitespace-nowrap" x-text="cat.menu_items_count || 0"></span>
                                </button>
                                <div class="flex gap-1 px-2 pb-2" x-show="activeCategoryId == cat.id" x-transition>
                                    <button @click.stop="editCategory(cat)" class="flex-1 text-xs px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Edit</button>
                                    <button @click.stop="deleteCategory(cat)" class="flex-1 text-xs px-2 py-1 bg-red-50 text-red-700 rounded hover:bg-red-100">Hapus</button>
                                </div>
                            </li>
                        </template>
                    </ul>

                    <div x-show="!loading && categories.length === 0" class="text-center py-8 text-gray-500">
                        Belum ada kategori. Klik "Tambah" untuk memulai.
                    </div>
                </div>

                <!-- Items Panel -->
                <div class="flex-1 min-h-[500px]" x-data="itemsPanel()">
                    <div x-show="loading" class="p-8 text-center text-gray-500">Memuat item...</div>
                    <div x-show="!loading" x-html="itemsHtml"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div x-data="categoryModal()" x-init="init()" class="fixed inset-0 z-50 hidden" x-show="show" x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @keydown.escape.window="close()">
        <div class="fixed inset-0 bg-black bg-opacity-50" @click="close()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-md w-full" x-transition:enter="transform transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transform transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.outside="close()">
                <form @submit.prevent="submit()">
                    <input type="hidden" name="category_id" x-ref="categoryId">
                    <div class="p-6">
                        <h3 x-text="editing ? 'Edit Kategori' : 'Tambah Kategori'" class="text-lg font-semibold text-gray-900 mb-4"></h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nama Kategori <span class="text-red-500">*</span></label>
                                <input type="text" name="name" x-ref="nameInput" required maxlength="100" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Urutan</label>
                                <input type="number" name="sort_order" min="0" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <label class="ml-2 text-sm text-gray-700">Aktif</label>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                        <button type="button" @click="close()" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Batal</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700" :disabled="submitting">
                            <span x-show="!submitting" x-text="editing ? 'Simpan' : 'Tambah'"></span>
                            <span x-show="submitting">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Item Modal -->
    <div x-data="itemModal()" x-init="init()" class="fixed inset-0 z-50 hidden" x-show="show" x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @keydown.escape.window="close()">
        <div class="fixed inset-0 bg-black bg-opacity-50" @click="close()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" x-transition:enter="transform transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" @click.outside="close()">
                <form @submit.prevent="submit()" enctype="multipart/form-data">
                    <input type="hidden" name="item_id" x-ref="itemId">
                    <div class="p-6">
                        <h3 x-text="editing ? 'Edit Item Menu' : 'Tambah Item Menu'" class="text-lg font-semibold text-gray-900 mb-4"></h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Kategori <span class="text-red-500">*</span></label>
                                <select name="category_id" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Pilih Kategori</option>
                                    <template x-for="cat in categories" :key="cat.id">
                                        <option :value="cat.id" :selected="cat.id == selectedCategoryId" x-text="cat.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nama Item <span class="text-red-500">*</span></label>
                                <input type="text" name="name" required maxlength="255" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Harga (Rupiah) <span class="text-red-500">*</span></label>
                                    <input type="number" name="price" required min="0" step="100" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Contoh: 15000">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Stok (-1 = Unlimited) <span class="text-red-500">*</span></label>
                                    <input type="number" name="stock" required min="-1" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="-1">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Gambar</label>
                                <input type="file" name="image" accept="image/*" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-xs text-gray-500">Maks 2MB. Akan di-resize ke lebar max 800px.</p>
                                <template x-if="editing && currentImage">
                                    <div class="mt-2 flex items-center gap-2">
                                        <img :src="currentImage" class="w-16 h-16 object-cover rounded">
                                        <label class="flex items-center text-sm text-gray-700">
                                            <input type="checkbox" name="delete_image" value="1" class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                            <span class="ml-2">Hapus gambar saat menyimpan</span>
                                        </label>
                                    </div>
                                </template>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Urutan</label>
                                    <input type="number" name="sort_order" min="0" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div class="flex items-end">
                                    <label class="flex items-center">
                                        <input type="checkbox" name="is_active" value="1" checked class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">Aktif</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                        <button type="button" @click="close()" class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">Batal</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700" :disabled="submitting">
                            <span x-show="!submitting" x-text="editing ? 'Simpan' : 'Tambah'"></span>
                            <span x-show="submitting">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Categories Sidebar Component
        function categoriesSidebar() {
            return {
                categories: @json($categories),
                activeCategoryId: null,
                loading: false,

                async init() {
                    // Select first category by default, or use server-side selectedCategoryId
                    const initialCategoryId = @js($selectedCategoryId ?? ($categories->first()?->id));
                    if (initialCategoryId) {
                        this.selectCategory(initialCategoryId);
                    }
                },

                selectCategory(categoryId) {
                    this.activeCategoryId = categoryId;
                    // Trigger items panel to reload
                    const itemsPanel = document.querySelector('[x-data="itemsPanel()"]');
                    if (itemsPanel) {
                        itemsPanel.__x.$data.loadItems(categoryId);
                    }
                },

                openCategoryModal(category = null) {
                    const modal = document.querySelector('[x-data="categoryModal()"]');
                    if (modal) {
                        modal.__x.$data.open(category);
                    }
                },

                editCategory(cat) {
                    this.openCategoryModal(cat);
                },

                async deleteCategory(cat) {
                    if (!confirm('Yakin ingin menghapus kategori "' + cat.name + '"?')) return;
                    try {
                        const response = await fetch('{{ route("admin.menu.categories.destroy", ":id") }}'.replace(':id', cat.id), {
                            method: 'DELETE',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                        });
                        const data = await response.json();
                        if (data.success) {
                            this.categories = this.categories.filter(c => c.id !== cat.id);
                            if (this.activeCategoryId === cat.id) {
                                this.activeCategoryId = this.categories[0]?.id || null;
                                this.selectCategory(this.activeCategoryId);
                            }
                        } else {
                            alert(data.message);
                        }
                    } catch (e) {
                        alert('Gagal menghapus kategori');
                    }
                }
            }
        }

        // Items Panel Component
        function itemsPanel() {
            return {
                itemsHtml: '',
                loading: true,
                currentCategoryId: null,

                async loadItems(categoryId = null) {
                    this.loading = true;
                    this.currentCategoryId = categoryId;
                    try {
                        const url = new URL('{{ route("admin.menu.items.index") }}', window.location.origin);
                        if (categoryId) {
                            url.searchParams.set('category_id', categoryId);
                        }
                        const response = await fetch(url.toString(), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        this.itemsHtml = await response.text();
                    } catch (e) {
                        console.error(e);
                        this.itemsHtml = '<div class="p-8 text-center text-red-500">Gagal memuat item</div>';
                    } finally {
                        this.loading = false;
                    }
                }
            }
        }

        // Category Modal Component
        function categoryModal() {
            return {
                show: false,
                editing: false,
                submitting: false,
                categories: @json($categories),

                init() {
                    this.$watch('show', (val) => {
                        if (val) {
                            this.$nextTick(() => this.$refs.nameInput?.focus());
                        }
                    });
                },

                open(category = null) {
                    this.editing = !!category;
                    this.show = true;
                    this.submitting = false;

                    this.$refs.categoryId.value = category?.id || '';
                    this.$refs.nameInput.value = category?.name || '';
                    this.$refs.nameInput.form.querySelector('[name="sort_order"]').value = category?.sort_order || '';
                    this.$refs.nameInput.form.querySelector('[name="is_active"]').checked = category?.is_active ?? true;
                },

                close() {
                    this.show = false;
                    this.editing = false;
                    this.$refs.nameInput.form.reset();
                },

                async submit() {
                    this.submitting = true;
                    const formData = new FormData(this.$refs.nameInput.form);
                    const url = this.editing
                        ? '{{ route("admin.menu.categories.update", ":id") }}'.replace(':id', this.$refs.categoryId.value)
                        : '{{ route("admin.menu.categories.store") }}';
                    const method = this.editing ? 'PUT' : 'POST';
                    formData.append('_method', method);

                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData,
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await response.json();
                        if (data.success) {
                            this.close();
                            // Reload categories
                            const sidebar = document.querySelector('[x-data="categoriesSidebar()"]');
                            if (sidebar) {
                                const resp = await fetch('{{ route("admin.menu.index") }}');
                                const html = await resp.text();
                                // Parse categories from response or reload page
                                window.location.reload();
                            }
                        } else {
                            alert(data.message || 'Gagal menyimpan kategori');
                        }
                    } catch (e) {
                        alert('Terjadi kesalahan');
                    } finally {
                        this.submitting = false;
                    }
                }
            }
        }

        // Item Modal Component
        function itemModal() {
            return {
                show: false,
                editing: false,
                submitting: false,
                categories: @json($categories),
                selectedCategoryId: null,
                currentImage: null,

                init() {},

                open(item = null) {
                    this.editing = !!item;
                    this.show = true;
                    this.submitting = false;
                    this.currentImage = item?.image_url ? '{{ asset("storage/") }}' + item.image_url : null;

                    this.$refs.itemId.value = item?.id || '';
                    this.$refs.itemId.form.querySelector('[name="name"]').value = item?.name || '';
                    this.$refs.itemId.form.querySelector('[name="price"]').value = item?.price_cents ? (item.price_cents / 100) : '';
                    this.$refs.itemId.form.querySelector('[name="stock"]').value = item?.stock ?? -1;
                    this.$refs.itemId.form.querySelector('[name="sort_order"]').value = item?.sort_order || '';
                    this.$refs.itemId.form.querySelector('[name="is_active"]').checked = item?.is_active ?? true;

                    this.selectedCategoryId = item?.category_id || '';
                    this.$nextTick(() => {
                        this.$refs.itemId.form.querySelector('[name="category_id"]').value = this.selectedCategoryId;
                    });
                },

                close() {
                    this.show = false;
                    this.editing = false;
                    this.currentImage = null;
                    this.$refs.itemId.form.reset();
                    this.$refs.itemId.form.querySelector('[name="is_active"]').checked = true;
                },

                async submit() {
                    this.submitting = true;
                    const formData = new FormData(this.$refs.itemId.form);
                    // Convert price to cents
                    const priceInput = this.$refs.itemId.form.querySelector('[name="price"]');
                    if (priceInput.value) {
                        formData.set('price_cents', Math.round(parseFloat(priceInput.value) * 100));
                    }
                    formData.delete('price');

                    const url = this.editing
                        ? '{{ route("admin.menu.items.update", ":id") }}'.replace(':id', this.$refs.itemId.value)
                        : '{{ route("admin.menu.items.store") }}';
                    const method = this.editing ? 'PUT' : 'POST';
                    formData.append('_method', method);

                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData,
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await response.json();
                        if (data.success) {
                            this.close();
                            // Reload items panel
                            const panel = document.querySelector('[x-data="itemsPanel()"]');
                            if (panel) {
                                panel.__x.$data.loadItems(panel.__x.$data.currentCategoryId);
                            }
                        } else {
                            alert(data.message || 'Gagal menyimpan item');
                        }
                    } catch (e) {
                        alert('Terjadi kesalahan');
                    } finally {
                        this.submitting = false;
                    }
                }
            }
        }
    </script>
</x-layouts.app>