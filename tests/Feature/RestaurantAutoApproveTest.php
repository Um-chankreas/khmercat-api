<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantAutoApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_restaurant_is_approved_and_public_straight_away(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $category = RestaurantCategory::create(['name' => 'Khmer Food']);

        $response = $this->post('/api/restaurants/create', [
            'name' => 'Angkor Bites',
            'category_id' => $category->id,
            'profile_picture' => UploadedFile::fake()->image('logo.jpg'),
        ], [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.restaurant.status', Restaurant::STATUS_APPROVED);

        $restaurant = Restaurant::findOrFail($response->json('data.restaurant.id'));
        $this->assertNotNull($restaurant->approved_at);
        // Nobody approved it by hand.
        $this->assertNull($restaurant->approved_by);
        $this->assertTrue($restaurant->isPublic());
    }
}
