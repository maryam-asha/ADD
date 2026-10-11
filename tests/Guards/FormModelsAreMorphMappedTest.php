<?php

namespace Tests\Guards;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Every model that owns a form or holds answers is stored by alias in
 * `forms.formable_type` / `form_submissions.subject_type`. A model that
 * picks up the trait but not an alias would silently write its full class
 * name instead — readable today, orphaned by the first namespace move.
 * The map is not enforced globally (see MorphMapServiceProvider for why),
 * so this is what stands in for that enforcement on the form engine.
 */
class FormModelsAreMorphMappedTest extends TestCase
{
    use ScansSourceFiles;

    private const TRAITS = ['InteractsWithForms', 'HasFormSubmissions'];

    public function test_every_form_owner_and_subject_has_a_morph_alias(): void
    {
        $unmapped = [];

        foreach ($this->formModels() as $class) {
            if (Relation::getMorphAlias($class) === $class) {
                $unmapped[] = $class;
            }
        }

        $this->assertNotEmpty($this->formModels(), 'The scan found no form models at all — the guard is looking in the wrong place.');
        $this->assertSame([], $unmapped, "Add these to MorphMapServiceProvider:\n".implode("\n", $unmapped));
    }

    public function test_every_alias_resolves_to_a_real_model(): void
    {
        foreach (Relation::morphMap() as $alias => $class) {
            $this->assertTrue(class_exists($class), "Morph alias \"{$alias}\" points at missing class {$class}.");
        }
    }

    /**
     * Sanctum tokens, notifications and spatie role rows already store User
     * by its class name; aliasing it would stop them resolving.
     */
    public function test_user_is_never_aliased_and_the_map_is_not_enforced(): void
    {
        $this->assertSame(User::class, Relation::getMorphAlias(User::class));
        $this->assertFalse(Relation::requiresMorphMap());
    }

    /**
     * @return list<class-string>
     */
    private function formModels(): array
    {
        $classes = [];

        foreach ($this->phpFilesIn(app_path('Domain')) as $contents) {
            if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $ns)
                || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $contents, $class)) {
                continue;
            }

            // A trait `use` inside the class body, e.g. `use HasFactory, HasFormSubmissions;`.
            foreach (self::TRAITS as $trait) {
                if (preg_match('/^\s{4}use\s[^;]*\b'.$trait.'\b[^;]*;/m', $contents)) {
                    $classes[] = $ns[1].'\\'.$class[1];
                    break;
                }
            }
        }

        return $classes;
    }
}
