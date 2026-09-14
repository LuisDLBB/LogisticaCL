<?php

namespace Tests\Unit;

use App\Models\CourierMovement;
use PHPUnit\Framework\TestCase;

class CourierMovementFechaTest extends TestCase
{
    public function test_it_extracts_fecha_from_a_tracking_number(): void
    {
        $fecha = CourierMovement::fechaFromTrackingNumber('4N202607013618-526');

        $this->assertNotNull($fecha);
        $this->assertSame('2026-07-01', $fecha->toDateString());
    }

    public function test_it_returns_null_when_the_tracking_date_is_invalid(): void
    {
        $fecha = CourierMovement::fechaFromTrackingNumber('4N202613013618-526');

        $this->assertNull($fecha);
    }
}
