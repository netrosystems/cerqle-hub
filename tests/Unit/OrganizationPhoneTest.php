<?php

namespace Tests\Unit;

use App\Support\OrganizationPhone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrganizationPhoneTest extends TestCase
{
    public function test_it_normalizes_national_numbers_with_the_selected_country(): void
    {
        $this->assertSame('+8801712345678', OrganizationPhone::normalize('01712 345678', 'BD'));
        $this->assertSame('+14155552671', OrganizationPhone::normalize('(415) 555-2671', 'US'));
    }

    public function test_it_splits_international_and_legacy_numbers_for_editing(): void
    {
        $this->assertSame(
            ['region' => 'US', 'national' => '(415) 555-2671'],
            OrganizationPhone::split('+14155552671', 'BD'),
        );
        $this->assertSame(
            ['region' => 'US', 'national' => '(415) 555-2671'],
            OrganizationPhone::split('1 415 555 2671', 'BD'),
        );
    }

    public function test_it_returns_null_for_an_empty_phone(): void
    {
        $this->assertNull(OrganizationPhone::normalize('', 'US'));
    }

    public function test_it_rejects_an_invalid_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrganizationPhone::normalize('123', 'US');
    }

    public function test_country_options_include_region_name_and_dial_code(): void
    {
        $bangladesh = collect(OrganizationPhone::countries('en'))->firstWhere('region', 'BD');

        $this->assertSame('+880', $bangladesh['dial_code']);
        $this->assertSame('Bangladesh', $bangladesh['name']);
    }

    public function test_it_can_choose_a_default_region_from_the_user_timezone(): void
    {
        $this->assertSame('BD', OrganizationPhone::regionForTimezone('Asia/Dhaka'));
        $this->assertSame('US', OrganizationPhone::regionForTimezone('America/New_York'));
    }
}
