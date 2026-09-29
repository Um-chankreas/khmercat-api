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

class RestaurantManageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->stranger = User::factory()->create(['username' => 'stranger']);
        $this->restaurant = Restaurant::create(['name' => 'Old name', 'status' => 'active']);
        $this->restaurant->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
    }

    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_owner_can_edit_details_including_category(): void
    {
        $cat = RestaurantCategory::create(['name' => 'Khmer Food']);

        $this->putJson("/api/restaurants/{$this->restaurant->id}", [
            'name' => 'Angkor Bites',
            'description' => 'Best amok in town',
            'category_id' => $cat->id,
            'phone' => '012 345 678',
            'opening_time' => '08:00',
            'closing_time' => '21:00',
        ], $this->as($this->owner))
            ->assertOk()
            ->assertJsonPath('data.name', 'Angkor Bites')
            ->assertJsonPath('data.category.name', 'Khmer Food')
            ->assertJsonPath('data.phone', '012 345 678')
            ->assertJsonPath('data.videos_count', 0);
    }

    public function test_non_member_cannot_edit_or_change_photos(): void
    {
        $this->putJson("/api/restaurants/{$this->restaurant->id}", ['name' => 'Hijacked'], $this->as($this->stranger))
            ->assertForbidden();

        $this->post("/api/restaurants/{$this->restaurant->id}/avatar",
            ['avatar' => UploadedFile::fake()->image('a.jpg')],
            $this->as($this->stranger) + ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame('Old name', $this->restaurant->fresh()->name);
    }

    public function test_owner_can_replace_logo_and_cover(): void
    {
        $res = $this->post("/api/restaurants/{$this->restaurant->id}/avatar",
            ['avatar' => UploadedFile::fake()->image('logo.png')],
            $this->as($this->owner) + ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertStringContainsString("restaurants/{$this->restaurant->id}/avatar.png?v=", $res->json('data.profile_picture'));
        Storage::disk('public')->assertExists("restaurants/{$this->restaurant->id}/avatar.png");

        $this->post("/api/restaurants/{$this->restaurant->id}/cover",
            ['cover' => UploadedFile::fake()->image('cover.jpg')],
            $this->as($this->owner) + ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotNull($this->restaurant->fresh()->cover_picture);
    }

    public function test_owner_can_set_and_clear_social_links(): void
    {
        $url = "/api/restaurants/{$this->restaurant->id}";

        $this->putJson($url, [
            'facebook_url' => 'https://facebook.com/angkorbites',
            'tiktok_url' => '@angkorbites',
            'telegram_username' => '@angkor_bites',
        ], $this->as($this->owner))
            ->assertOk()
            ->assertJsonPath('data.facebook_url', 'https://facebook.com/angkorbites')
            ->assertJsonPath('data.tiktok_url', '@angkorbites')
            ->assertJsonPath('data.telegram_username', '@angkor_bites');

        $this->putJson($url, ['facebook_url' => null], $this->as($this->owner))
            ->assertOk()->assertJsonPath('data.facebook_url', null);
    }

    public function test_invalid_social_links_are_rejected(): void
    {
        $this->putJson("/api/restaurants/{$this->restaurant->id}", [
            'facebook_url' => 'not a url',
            'tiktok_url' => 'has spaces in it',
            'telegram_username' => '@x',
        ], $this->as($this->owner))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['facebook_url', 'tiktok_url', 'telegram_username']);
    }
}
