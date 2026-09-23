<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantCategory extends Model
{
    protected $fillable = [
        'name',
        'icon',
    ];

    public function restaurants()
    {
        return $this->hasMany(Restaurant::class, 'category_id');
    }
}
