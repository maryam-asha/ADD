<?php

namespace Add\Pm\Integrations;

use Add\Pm\Contracts\PeopleDirectory;
use Illuminate\Support\Facades\DB;

/**
 * The default PeopleDirectory: every row of the host's users table, by its
 * `name`. It reads the table through the query builder so the package needs
 * no User class of its own; a host with a richer notion of "person" binds
 * its own directory instead.
 */
class UserTablePeopleDirectory implements PeopleDirectory
{
    public function search(string $term, int $limit = 20): array
    {
        $query = DB::table($this->table())->select(['id', 'name'])->orderBy('name')->orderBy('id')->limit($limit);

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return $query->get()->map(fn ($row) => $this->person($row))->all();
    }

    public function find(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($this->table())->select(['id', 'name'])->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => $this->person($row)])
            ->all();
    }

    public function isPerson(int $id): bool
    {
        return DB::table($this->table())->where('id', $id)->exists();
    }

    private function table(): string
    {
        $model = config('auth.providers.users.model');

        return $model !== null && class_exists($model) ? (new $model)->getTable() : 'users';
    }

    /**
     * @return array{id:int,name:string,avatar_url:?string}
     */
    private function person(object $row): array
    {
        return ['id' => (int) $row->id, 'name' => (string) $row->name, 'avatar_url' => null];
    }
}
