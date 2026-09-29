<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page (photo) of a restaurant's menu.
 */
class RestaurantMenuImage extends Model
{
    protected $fillable = ['restaurant_id', 'path', 'url', 'position'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
