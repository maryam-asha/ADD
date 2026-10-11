<?php

namespace Tests\Unit\Support\Forms;

use App\Support\Forms\FieldType;
use App\Support\Forms\FieldValueRouter;
use PHPUnit\Framework\TestCase;

class FieldValueRouterTest extends TestCase
{
    private FieldValueRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new FieldValueRouter;
    }

    public function test_scalar_types_land_in_the_text_column(): void
    {
        foreach ([FieldType::Text, FieldType::Textarea, FieldType::Date, FieldType::Number] as $type) {
            $columns = $this->router->route($type, 'value');

            $this->assertSame('value', $columns['value_text'], $type->value);
            $this->assertNull($columns['value_json'], $type->value);
            $this->assertNull($columns['value_file'], $type->value);
        }
    }

    public function test_json_types_land_in_the_json_column(): void
    {
        $checkbox = $this->router->route(FieldType::Checkbox, ['a', 'b']);
        $this->assertSame(['a', 'b'], $checkbox['value_json']);
        $this->assertNull($checkbox['value_text']);

        // Select and Rating are scalar but stored as JSON — a deliberate
        // quirk every existing row depends on.
        $this->assertSame('m', $this->router->route(FieldType::Select, 'm')['value_json']);
        $this->assertSame(4, $this->router->route(FieldType::Rating, 4)['value_json']);
    }

    public function test_files_land_in_the_file_column(): void
    {
        $columns = $this->router->route(FieldType::File, 'service-requests/1/a.pdf');

        $this->assertSame('service-requests/1/a.pdf', $columns['value_file']);
        $this->assertNull($columns['value_text']);
        $this->assertNull($columns['value_json']);
    }

    public function test_resolve_prefers_file_then_json_then_text(): void
    {
        $this->assertSame('f', $this->router->resolve('t', ['j'], 'f'));
        $this->assertSame(['j'], $this->router->resolve('t', ['j'], null));
        $this->assertSame('t', $this->router->resolve('t', null, null));
        $this->assertNull($this->router->resolve(null, null, null));
    }

    public function test_every_type_round_trips(): void
    {
        $cases = [
            [FieldType::Text, 'hello'],
            [FieldType::Textarea, 'long text'],
            [FieldType::Date, '2026-01-01'],
            [FieldType::Number, '42'],
            [FieldType::Select, 'm'],
            [FieldType::Rating, 5],
            [FieldType::Checkbox, ['a', 'b']],
            [FieldType::File, 'path/to.pdf'],
        ];

        foreach ($cases as [$type, $value]) {
            $c = $this->router->route($type, $value);

            $this->assertSame(
                $value,
                $this->router->resolve($c['value_text'], $c['value_json'], $c['value_file']),
                $type->value,
            );
        }
    }
}
