<?php

namespace Tests\Unit;

use App\Models\CourierMovement;
use PHPUnit\Framework\TestCase;

class CourierMovementWeightTest extends TestCase
{
    public function test_it_uses_the_lower_real_or_transformed_weight(): void
    {
        $this->assertSame(5, CourierMovement::pesoFinal(7, 5));
        $this->assertSame(5, CourierMovement::pesoFinal(5, 7));
    }

    public function test_it_leaves_final_weight_empty_when_a_weight_is_missing(): void
    {
        $this->assertNull(CourierMovement::pesoFinal(5, null));
    }
}
