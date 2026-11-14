<?php

namespace App\Domain\Pm;

use Add\Pm\Contracts\PeopleDirectory;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * In ADD only staff can be put on a project: accounts with an HR employee
 * record that has not been terminated. A member of the public is never
 * offered, however their name matches. Names come in the request's language
 * (name_ar / name_en), else the stored name. The host may read HR's table;
 * the project-management package never does. Accounts carry no avatar.
 */
class AddPeopleDirectory implements PeopleDirectory
{
    public function search(string $term, int $limit = 20): array
    {
        $query = $this->staff();

        if ($term !== '') {
            $query->nameLike($term);
        }

        return $query->orderBy($this->nameColumn())->orderBy('users.id')->limit($limit)->get()
            ->map(fn (User $user) => $this->person($user))->values()->all();
    }

    public function find(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->staff()->whereIn('users.id', $ids)->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $this->person($user)])->all();
    }

    public function isPerson(int $id): bool
    {
        return $this->staff()->where('users.id', $id)->exists();
    }

    /** @return Builder<User> */
    private function staff(): Builder
    {
        return User::query()->select('users.*')->whereExists(fn ($employees) => $employees
            ->selectRaw('1')->from('hr_employees')
            ->whereColumn('hr_employees.user_id', 'users.id')
            ->where('hr_employees.status', '!=', 'terminated'));
    }

    private function nameColumn(): string
    {
        return app()->getLocale() === 'ar' ? 'users.name_ar' : 'users.name_en';
    }

    /**
     * @return array{id:int,name:string,avatar_url:?string}
     */
    private function person(User $user): array
    {
        $name = app()->getLocale() === 'ar' ? $user->name_ar : $user->name_en;

        return ['id' => $user->id, 'name' => (string) ($name ?: $user->name), 'avatar_url' => null];
    }
}
