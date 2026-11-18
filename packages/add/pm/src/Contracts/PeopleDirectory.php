<?php

namespace Add\Pm\Contracts;

/**
 * Where the package looks people up. Projects keep only user ids; names and
 * pictures stay with the host, which decides who may be put on a project at
 * all (ADD: staff with an active employee record).
 *
 * A person is `{id, name, avatar_url}`.
 */
interface PeopleDirectory
{
    /**
     * People whose name matches the term, alphabetically; an empty term
     * lists the first of everyone.
     *
     * @return list<array{id:int,name:string,avatar_url:?string}>
     */
    public function search(string $term, int $limit = 20): array;

    /**
     * The people among these ids, keyed by id; unknown ids are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id:int,name:string,avatar_url:?string}>
     */
    public function find(array $ids): array;

    /** Whether this id is someone who may be put on a project. */
    public function isPerson(int $id): bool;
}
