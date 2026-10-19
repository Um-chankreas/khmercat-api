<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantHoliday;
use App\Models\User;
use App\Models\VideoReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantHolidayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $a;

    private Restaurant $b;

    private Restaurant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'owner']);
        $make = fn ($name) => Restaurant::create([
            'name' => $name,
            'status' => Restaurant::STATUS_APPROVED,
            'is_published' => true,
            'opening_time' => '00:00:00',
            'closing_time' => '00:00:00', // open around the clock
        ]);
        $this->a = $make('Angkor Bites 1');
        $this->b = $make('Angkor Bites 2');
        $this->other = $make('Someone Else');
        foreach ([$this->a, $this->b] as $r) {
            $r->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
        }
    }

    /** The JWT guard and parser keep the first request's user and token. */
    private function as(User $user): array
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $id) {
            $this->app->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function postHoliday(array $overrides = [], ?User $as = null)
    {
        return $this->postJson('/api/restaurants/holidays', array_merge([
            'restaurant_ids' => [$this->a->id, $this->b->id],
            'date' => RestaurantHoliday::today(),
            'title' => 'Independence Day',
            'mode' => 'closed',
            'description' => 'Closed today, back tomorrow.',
        ], $overrides), $this->as($as ?? $this->owner));
    }

    public function test_posts_to_several_restaurants_and_closes_them_today(): void
    {
        $this->postHoliday()->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(2, VideoReview::where('type', VideoReview::TYPE_HOLIDAY_POSTER)->count());
        $this->getJson("/api/restaurants/{$this->a->id}", $this->as($this->owner))
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.today_holiday.title', 'Independence Day')
            ->assertJsonPath('data.upcoming_holiday.mode', 'closed');

        // Posting again for the same day updates it.
        $this->postHoliday(['mode' => 'special', 'opens_at' => '00:00', 'closes_at' => '00:00'])->assertOk();
        $this->assertSame(2, RestaurantHoliday::count());
        $this->assertSame(2, VideoReview::where('type', VideoReview::TYPE_HOLIDAY_POSTER)->count());
        $this->getJson("/api/restaurants/{$this->a->id}")->assertJsonPath('data.is_open', true);
    }

    public function test_poster_is_in_the_main_feed_but_not_in_video_grids(): void
    {
        $this->postHoliday(['restaurant_ids' => [$this->a->id]])->assertOk();

        $this->getJson('/api/videos/feed')
            ->assertOk()
            ->assertJsonPath('data.contents.0.type', VideoReview::TYPE_HOLIDAY_POSTER)
            ->assertJsonPath('data.contents.0.holiday.title', 'Independence Day');
        $this->getJson("/api/videos/feed?restaurant_id={$this->a->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.contents');
        $this->getJson('/api/users/owner/videos')->assertOk()->assertJsonCount(0, 'data.contents');
    }

    public function test_only_own_restaurants_and_only_the_active_one_when_acting_as_it(): void
    {
        $this->postHoliday(['restaurant_ids' => [$this->other->id]])->assertForbidden();

        $this->owner->forceFill(['active_restaurant_id' => $this->a->id])->save();
        $this->postHoliday()->assertForbidden();
        $this->postHoliday(['restaurant_ids' => [$this->a->id]])->assertOk();
    }

    public function test_special_hours_need_times_and_past_dates_are_rejected(): void
    {
        $this->postHoliday(['mode' => 'special'])->assertUnprocessable();
        $this->postHoliday(['date' => now()->subDays(2)->toDateString()])->assertUnprocessable();
    }

    public function test_team_removes_a_poster_and_its_feed_post(): void
    {
        $id = $this->postHoliday(['restaurant_ids' => [$this->a->id]])->json('data.0.id');

        $this->deleteJson("/api/holidays/{$id}", [], $this->as($this->owner))->assertOk();

        $this->assertSame(0, RestaurantHoliday::count());
        $this->assertSame(0, VideoReview::withTrashed()->where('type', VideoReview::TYPE_HOLIDAY_POSTER)->count());
    }

    public function test_a_notice_can_span_several_days(): void
    {
        $this->postHoliday(['restaurant_ids' => [$this->a->id], 'days' => 3])
            ->assertOk()
            ->assertJsonPath('data.0.end_date', now('Asia/Phnom_Penh')->addDays(2)->toDateString());

        $tz = Restaurant::BUSINESS_TIMEZONE;
        $this->assertFalse($this->a->fresh()->isOpenNow(now($tz)->addDays(2)->setTime(12, 0)));
        $this->assertTrue($this->a->fresh()->isOpenNow(now($tz)->addDays(3)->setTime(12, 0)));
        $this->postHoliday(['days' => 30])->assertUnprocessable();
    }
}
