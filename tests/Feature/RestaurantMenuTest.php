<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RestaurantMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->create(['username' => 'owner']);
        $this->restaurant = Restaurant::create(['name' => 'Angkor Bites', 'status' => 'active']);
        $this->restaurant->users()->attach($this->owner->id, ['role' => 'owner', 'status' => 'accepted']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'Accept' => 'application/json'];
    }

    private function upload(User $user, int $count = 2)
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = UploadedFile::fake()->image("page{$i}.jpg");
        }

        return $this->post("/api/restaurants/{$this->restaurant->id}/menu", ['images' => $files], $this->headers($user));
    }

    public function test_owner_uploads_pages_and_anyone_can_view_them_in_order(): void
    {
        $this->upload($this->owner, 2)->assertOk()->assertJsonCount(2, 'data.images');
        $this->upload($this->owner, 1)->assertOk();

        $res = $this->getJson("/api/restaurants/{$this->restaurant->id}/menu")->assertOk();
        $this->assertSame([1, 2, 3], array_column($res->json('data.images'), 'position'));
        $this->assertArrayNotHasKey('path', $res->json('data.images.0'));
        $this->assertStringEndsWith("/menu/{$this->restaurant->id}", $res->json('data.menu_url'));

        $this->getJson("/api/restaurants/{$this->restaurant->id}")
            ->assertJsonPath('data.menu_images_count', 3);
    }

    public function test_owner_can_delete_a_page_and_its_file(): void
    {
        $id = $this->upload($this->owner, 1)->json('data.images.0.id');
        $path = RestaurantMenuImage::find($id)->path;
        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/restaurants/{$this->restaurant->id}/menu/{$id}", [], $this->headers($this->owner))
            ->assertOk()->assertJsonCount(0, 'data.images');
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_members_cannot_edit_the_menu(): void
    {
        $stranger = User::factory()->create(['username' => 'stranger']);
        $this->upload($stranger)->assertForbidden();
    }

    public function test_menu_is_capped_at_twenty_pages(): void
    {
        $this->upload($this->owner, 20)->assertOk();
        $this->upload($this->owner, 1)->assertStatus(422);
    }

    public function test_public_web_menu_page_renders(): void
    {
        $this->upload($this->owner, 1);

        $this->get("/menu/{$this->restaurant->id}")
            ->assertOk()
            ->assertSee('Angkor Bites')
            ->assertSee('Menu page 1');

        $this->get('/menu/999999')->assertNotFound();
    }
}
