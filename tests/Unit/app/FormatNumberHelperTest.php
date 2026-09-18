<?php

namespace Unit\app;

use Unit\TestCase;

/**
 * format_number() is the intl-free stand-in for Illuminate\Support\Number::format(...,
 * maxPrecision: n). The report templates used the Laravel helper, which throws when ext-intl
 * is absent — and Leantime neither requires intl nor ships it in its Docker images, so the
 * project report page 500'd in production. These tests pin the formatting semantics.
 */
class FormatNumberHelperTest extends TestCase
{
    public function test_rounds_to_at_most_one_fraction_digit_and_trims_trailing_zeros(): void
    {
        $this->assertSame('0', format_number(0.0));
        $this->assertSame('1', format_number(1.0));
        $this->assertSame('1.2', format_number(1.24));
        $this->assertSame('1.3', format_number(1.25));
        $this->assertSame('5', format_number(5));
        $this->assertSame('-3.5', format_number(-3.5));
    }

    public function test_uses_thousands_separators(): void
    {
        $this->assertSame('1,000', format_number(1000.0));
        $this->assertSame('1,234.6', format_number(1234.56));
    }

    public function test_max_precision_argument(): void
    {
        $this->assertSame('1,000', format_number(999.6, 0));
        $this->assertSame('1,000', format_number(1000.0, 2));
        $this->assertSame('12.35', format_number(12.35, 2));
    }

    public function test_does_not_require_the_intl_extension(): void
    {
        if (extension_loaded('intl')) {
            $this->markTestSkipped('intl is loaded; the no-intl guarantee cannot be asserted here.');
        }

        $this->assertSame('1,234.6', format_number(1234.56));
    }
}
