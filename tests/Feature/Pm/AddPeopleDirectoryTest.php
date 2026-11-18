<?php

namespace Tests\Feature\Pm;

use Add\Hr\Core\Models\Employee;
use Add\Pm\Contracts\PeopleDirectory;
use App\Domain\Identity\Models\User;
use App\Domain\Pm\AddPeopleDirectory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * In ADD only accounts with an active HR employee record can be put on a
 * project: a member of the public is never offered.
 */
class AddPeopleDirectoryTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    public function test_only_staff_on_record_are_offered(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $staff = User::factory()->create(['name' => 'Nour', 'name_ar' => 'نور الحلبي', 'name_en' => 'Nour Halabi']);
        $this->hireFor($staff);
        $left = User::factory()->create(['name' => 'Nour Gone', 'name_ar' => 'نور غادر', 'name_en' => 'Nour Gone']);
        Employee::query()->where('id', $this->hireFor($left)->id)->update(['status' => 'terminated']);
        $member = User::factory()->create(['name' => 'Nour Member', 'name_ar' => 'نور عضو', 'name_en' => 'Nour Member']);

        $directory = app(PeopleDirectory::class);
        $this->assertInstanceOf(AddPeopleDirectory::class, $directory);

        app()->setLocale('en');
        $found = $directory->search('Nour');
        $this->assertSame([['id' => $staff->id, 'name' => 'Nour Halabi', 'avatar_url' => null]], $found);

        app()->setLocale('ar');
        $this->assertSame('نور الحلبي', $directory->search('الحلبي')[0]['name']);
        $this->assertSame('نور الحلبي', $directory->find([$staff->id, $left->id, $member->id])[$staff->id]['name']);

        $this->assertTrue($directory->isPerson($staff->id));
        $this->assertFalse($directory->isPerson($left->id));
        $this->assertFalse($directory->isPerson($member->id));
        $this->assertSame([$staff->id], array_keys($directory->find([$staff->id, $left->id, $member->id])));

        Sanctum::actingAs($staff, ['dashboard']);
        $this->getJson('/api/v1/pm/people?q=Nour')->assertOk()->assertJsonCount(1, 'data');
    }
}
