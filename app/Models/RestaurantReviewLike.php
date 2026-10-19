<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantReviewLike extends Model
{
    protected $fillable = ['restaurant_review_id', 'user_id'];
}
