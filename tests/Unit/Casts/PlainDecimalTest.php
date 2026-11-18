<?php

namespace Tests\Unit\Casts;

use App\Casts\PlainDecimal;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlainDecimalTest extends TestCase
{
    private function cast(): PlainDecimal
    {
        return new PlainDecimal;
    }

    public function test_get_trims_trailing_zeros(): void
    {
        $model = new class extends Model {};

        foreach ([['300.00', '300'], ['150.50', '150.5'], ['0.00', '0'], ['12.75', '12.75'], [null, null]] as [$in, $out]) {
            $this->assertSame($out, $this->cast()->get($model, 'amount', $in, []));
        }
    }

    public function test_set_stores_two_decimals(): void
    {
        $model = new class extends Model {};

        $this->assertSame('300.00', $this->cast()->set($model, 'amount', '300', []));
        $this->assertSame('150.50', $this->cast()->set($model, 'amount', 150.5, []));
        $this->assertSame('12.00', $this->cast()->set($model, 'amount', 12, []));
        $this->assertNull($this->cast()->set($model, 'amount', null, []));
    }

    public function test_set_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cast()->set(new class extends Model {}, 'amount', 'abc', []);
    }
}
