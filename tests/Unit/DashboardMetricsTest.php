<?php

namespace Tests\Unit;

use App\Support\DashboardMetrics;
use PHPUnit\Framework\TestCase;

/**
 * The pure median used for the players dashboard "team median" column
 * (issue #17).
 */
class DashboardMetricsTest extends TestCase
{
    public function test_median_of_an_odd_count_is_the_middle_value(): void
    {
        $this->assertSame(3.0, DashboardMetrics::median([5, 1, 3]));
    }

    public function test_median_of_an_even_count_is_the_mean_of_the_two_middle_values(): void
    {
        $this->assertSame(4.0, DashboardMetrics::median([7, 1, 5, 3]));
    }

    public function test_median_of_a_single_value_is_that_value(): void
    {
        $this->assertSame(9.0, DashboardMetrics::median([9]));
    }

    public function test_median_of_an_empty_list_is_null(): void
    {
        $this->assertNull(DashboardMetrics::median([]));
    }

    public function test_median_ignores_original_key_order(): void
    {
        $this->assertSame(20.0, DashboardMetrics::median([30 => 10, 10 => 30, 20 => 20]));
    }
}
