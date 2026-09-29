<?php

use App\Models\Restaurant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['status' => 'ok', 'service' => config('app.name')]);
});

// Public menu page for a restaurant — what its QR code opens, so diners can
// scan it at the table and read the menu without installing the app.
Route::get('menu/{restaurant}', function (int $restaurant) {
    $model = Restaurant::with('category')->published()->findOrFail($restaurant);

    return view('menu', [
        'restaurant' => $model,
        'images' => $model->menuImages()->get(),
    ]);
})->whereNumber('restaurant')->name('restaurants.menu.web');
