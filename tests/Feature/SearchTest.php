<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private RestaurantCategory $khmer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->khmer = RestaurantCategory::create(['name' => 'Khmer Food']);
    }

    private function restaurant(array $attrs = []): Restaurant
    {
        return Restaurant::create(array_merge([
            'category_id' => $this->khmer->id,
            'name' => 'Angkor Bites',
            'status' => 'active',
        ], $attrs));
    }

    private function review(User $user, Restaurant $r, array $attrs = []): VideoReview
    {
        return VideoReview::create(array_merge([
            'user_id' => $user->id,
            'restaurant_id' => $r->id,
            'type' => VideoReview::TYPE_REVIEW,
            'caption' => 'Great food',
            'status' => 'ready',
            'video_url' => 'http://x/v.mp4',
        ], $attrs));
    }

    public function test_returns_rich_restaurant_video_and_user_results(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-28 05:00:00', 'UTC')); // 12:00 in Phnom Penh

        $reviewer = User::factory()->create([
            'username' => 'khmer_foodie', 'name' => 'Dara', 'bio' => 'Foodie & Reviewer', 'is_verified' => true,
        ]);
        $near = $this->restaurant([
            'name' => 'Khmer Flavors', 'latitude' => 11.5564, 'longitude' => 104.9282,
            'price_level' => 1, 'opening_time' => '08:00', 'closing_time' => '22:00',
        ]);
        $far = $this->restaurant([
            'name' => 'Sovanna BBQ', 'description' => 'Khmer BBQ', 'latitude' => 11.60, 'longitude' => 104.95,
            'is_sponsored' => true, 'service_type' => 'dine_in_delivery',
            'delivery_time_min' => 15, 'delivery_time_max' => 25,
            'opening_time' => '17:00', 'closing_time' => '02:00',
        ]);
        $this->review($reviewer, $near, ['rating' => 5, 'caption' => 'Best Khmer curry', 'views_count' => 10, 'duration_seconds' => 45]);
        $this->review($reviewer, $near, ['rating' => 4]);

        $res = $this->getJson('/api/search?q=Khmer&lat=11.5564&lng=104.9282&sort=nearest')->assertOk();
        $data = $res->json('data');

        $this->assertSame(['restaurants' => 2, 'videos' => 2, 'users' => 1], $data['counts']);

        $first = $data['restaurants'][0];
        $this->assertSame('Khmer Flavors', $first['name']);
        $this->assertEquals(0, $first['distance_km']);
        $this->assertEquals(4.5, $first['avg_rating']);
        $this->assertSame(2, $first['reviews_count']);
        $this->assertTrue($first['is_open']);
        $this->assertSame(1, $first['price_level']);
        $this->assertSame('Khmer Food', $first['category']['name']);
        $this->assertArrayNotHasKey('relevance', $first);

        $second = $data['restaurants'][1];
        $this->assertFalse($second['is_open']); // opens at 17:00
        $this->assertGreaterThan(4, $second['distance_km']);

        // Sponsored restaurant is recommended first.
        $this->assertSame('Sovanna BBQ', $data['recommended'][0]['name']);
        $this->assertSame('dine_in_delivery', $data['recommended'][0]['service_type']);

        $this->assertSame(45, $data['videos'][0]['duration_seconds']);
        $this->assertSame(10, $data['videos'][0]['views_count']);
        $this->assertSame('khmer_foodie', $data['videos'][0]['user']['username']);

        $this->assertSame('khmer_foodie', $data['users'][0]['username']);
        $this->assertSame(2, $data['users'][0]['reviews_count']);
        $this->assertTrue($data['users'][0]['is_verified']);
        $this->assertFalse($data['users'][0]['is_following']);
    }

    public function test_category_filter_works_without_a_keyword(): void
    {
        $other = RestaurantCategory::create(['name' => 'Western']);
        $this->restaurant(['name' => 'Amok House']);
        $this->restaurant(['name' => 'Burger Barn', 'category_id' => $other->id]);

        $data = $this->getJson('/api/search?category_id='.$this->khmer->id)->assertOk()->json('data');

        $this->assertSame(['Amok House'], array_column($data['restaurants'], 'name'));
        $this->assertSame([], $data['users']);
    }

    public function test_keyword_or_category_is_required(): void
    {
        $this->getJson('/api/search')->assertStatus(422);
    }

    public function test_overnight_opening_hours(): void
    {
        $r = new Restaurant(['opening_time' => '17:00:00', 'closing_time' => '02:00:00']);
        $this->assertTrue($r->isOpenNow(Carbon::parse('2026-09-28 18:00:00', 'UTC'))); // 01:00 local
        $this->assertFalse($r->isOpenNow(Carbon::parse('2026-09-28 05:00:00', 'UTC'))); // 12:00 local
        $this->assertNull((new Restaurant)->isOpenNow());
    }

    public function test_record_view_increments_count(): void
    {
        $user = User::factory()->create(['username' => 'u1']);
        $video = $this->review($user, $this->restaurant());

        $this->postJson("/api/videos/{$video->id}/view")->assertOk()->assertJsonPath('data.views_count', 1);
        $this->assertSame(1, $video->fresh()->views_count);
    }
}
