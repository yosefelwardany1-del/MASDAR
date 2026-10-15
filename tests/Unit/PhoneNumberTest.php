<?php

namespace Tests\Unit;

use App\Services\WhatsApp\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_egyptian_local_number_gets_20(): void
    {
        $this->assertSame('+201012345678', PhoneNumber::normalise('01012345678'));
        $this->assertSame('+201012345678', PhoneNumber::normalise('010 1234 5678'));
    }

    public function test_arabic_indic_digits_normalise(): void
    {
        // Typed on an Arabic keyboard (U+0660–U+0669) and on a Persian one (U+06F0–U+06F9).
        $this->assertSame('+201012345678', PhoneNumber::normalise('٠١٠١٢٣٤٥٦٧٨'));
        $this->assertSame('+201012345678', PhoneNumber::normalise('۰۱۰۱۲۳۴۵۶۷۸'));
        $this->assertSame('+201012345678', PhoneNumber::normalise('+٢٠ ١٠١٢٣٤٥٦٧٨'));
    }

    public function test_an_international_number_keeps_its_own_code(): void
    {
        $this->assertSame('+971501234567', PhoneNumber::normalise('+971 50 123 4567'));
        $this->assertSame('+971501234567', PhoneNumber::normalise('00971501234567'));
    }

    public function test_garbage_returns_null(): void
    {
        $this->assertNull(PhoneNumber::normalise(null));
        $this->assertNull(PhoneNumber::normalise(''));
        $this->assertNull(PhoneNumber::normalise('not a phone'));
        $this->assertNull(PhoneNumber::normalise('12'));
    }

    public function test_a_local_number_with_no_default_code_is_refused_not_guessed(): void
    {
        config(['whatsapp.default_country_code' => '']);

        $this->assertNull(PhoneNumber::normalise('01012345678'));
    }
}
