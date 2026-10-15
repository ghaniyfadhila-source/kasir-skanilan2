<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MenuItem extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $keyType = 'uuid';
    public $incrementing = false;

    public function auditPrefix(): string
    {
        return 'menu';
    }

    protected $fillable = [
        'category_id',
        'name',
        'price_cents',
        'stock',
        'image_url',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}