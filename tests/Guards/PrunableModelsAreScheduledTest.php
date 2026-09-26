<?php

namespace Tests\Guards;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * `model:prune` with no `--model` only discovers prunable models under
 * app/Models, and every model here lives under app/Domain — so a bare
 * schedule prunes nothing at all, silently. The scheduled command names its
 * models instead, and this fails the moment a new Prunable model is not on
 * that list.
 */
class PrunableModelsAreScheduledTest extends TestCase
{
    use ScansSourceFiles;

    public function test_every_prunable_model_is_named_in_the_scheduled_prune(): void
    {
        $prune = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'model:prune'));

        $this->assertNotNull($prune, 'model:prune is not scheduled at all.');

        $prunable = $this->prunableModels();
        $this->assertNotEmpty($prunable, 'The scan found no prunable models — the guard is looking in the wrong place.');

        $missing = array_values(array_filter(
            $prunable,
            fn (string $class) => ! str_contains((string) $prune->command, $class),
        ));

        $this->assertSame([], $missing, "Add these to the --model list in routes/console.php:\n".implode("\n", $missing));
    }

    /**
     * @return list<class-string>
     */
    private function prunableModels(): array
    {
        $classes = [];

        foreach ($this->phpFilesIn(app_path('Domain')) as $contents) {
            if (preg_match('/^\s{4}use\s[^;]*\b(Mass)?Prunable\b[^;]*;/m', $contents)
                && preg_match('/^namespace\s+([^;]+);/m', $contents, $ns)
                && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $contents, $class)) {
                $classes[] = $ns[1].'\\'.$class[1];
            }
        }

        return $classes;
    }
}
