<?php

namespace Add\Pm\Tests\Feature;

use Add\Pm\Contracts\PeopleDirectory;
use Add\Pm\Pm;
use Add\Pm\Tests\Fixtures\User;
use Add\Pm\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The people directory the package looks names up in: by default every user
 * of the host, found by name.
 */
class PeopleTest extends TestCase
{
    public function test_people_are_searched_by_name(): void
    {
        $this->actingAsWith();
        $layla = User::query()->create(['name' => 'Layla Haddad', 'email' => 'l@example.test']);
        User::query()->create(['name' => 'Omar Aziz', 'email' => 'o@example.test']);

        $response = $this->getJson('/api/pm/people?q=layl')->assertOk();

        $this->assertSame(
            [['id' => $layla->id, 'name' => 'Layla Haddad', 'avatar_url' => null]],
            $response->json('data'),
        );
    }

    public function test_an_empty_search_lists_alphabetically_and_any_account_may_ask(): void
    {
        $this->actingAsWith();
        User::query()->create(['name' => 'Aaa First', 'email' => 'a@example.test']);

        $names = array_column($this->getJson('/api/pm/people')->assertOk()->json('data'), 'name');

        $this->assertSame('Aaa First', $names[0]);
        $this->assertSame($names, collect($names)->sort()->values()->all());
    }

    public function test_find_is_keyed_by_id_and_is_person_agrees(): void
    {
        $user = User::query()->create(['name' => 'Rima', 'email' => 'r@example.test']);
        $directory = app(PeopleDirectory::class);

        $this->assertSame([$user->id => ['id' => $user->id, 'name' => 'Rima', 'avatar_url' => null]], $directory->find([$user->id, 9999]));
        $this->assertTrue($directory->isPerson($user->id));
        $this->assertFalse($directory->isPerson(9999));
    }

    public function test_the_route_is_named_under_the_hosts_prefix(): void
    {
        $route = Route::getRoutes()->getByName('pm.people.index');

        $this->assertNotNull($route);
        $this->assertSame('api/pm/people', $route->uri());
        $this->assertTrue(method_exists(Pm::class, 'adminRoutes'));
    }
}
