<?php

namespace Add\Hr\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Every package model lists the columns it accepts in $fillable and keeps
 * Eloquent's default guard, so a host passing raw request data to create()
 * or update() cannot write a column the model does not offer.
 */
class ModelsDeclareFillableTest extends TestCase
{
    public function test_every_model_declares_fillable_and_guards_the_rest(): void
    {
        $offenders = [];

        foreach ($this->models() as $class) {
            $model = new $class;

            if ($model->getFillable() === [] || $model->getGuarded() !== ['*']) {
                $offenders[] = $class;
            }
        }

        $this->assertNotEmpty($this->models());
        $this->assertSame([], $offenders, 'Declare $fillable instead of $guarded on: '.implode(', ', $offenders));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $src = realpath(__DIR__.'/../../src');
        $models = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Add\\Hr\\'.str_replace(['/', '.php'], ['\\', ''], substr(str_replace('\\', '/', $file->getPathname()), strlen($src) + 1));

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }
}
