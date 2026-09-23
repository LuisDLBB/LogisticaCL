<?php

namespace Tests\Unit;

use App\Models\CourierMovement;
use PHPUnit\Framework\TestCase;

class CourierMovementWeightTest extends TestCase
{
    public function test_real_weight_takes_priority_over_transformed_weight(): void
    {
        $this->assertSame(7, CourierMovement::pesoFinal(7, 5));
        $this->assertSame(5, CourierMovement::pesoFinal(5, 7));
        $this->assertSame(5, CourierMovement::pesoFinal(5, 0));
    }

    public function test_it_falls_back_to_transformed_weight_then_one_kilo(): void
    {
        $this->assertSame(5, CourierMovement::pesoFinal(5, null));
        $this->assertSame(8, CourierMovement::pesoFinal(null, 8));
        $this->assertSame(8, CourierMovement::pesoFinal(0, 8));
        $this->assertSame(1, CourierMovement::pesoFinal(null, 0));
        $this->assertSame(1, CourierMovement::pesoFinal(0, 0));
        $this->assertSame(1, CourierMovement::pesoFinal(null, null));
    }
}
