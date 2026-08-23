<?php

namespace Tests\Feature;

use App\Services\ProjectCompletion;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * What a person in Manila reads off a UTC clock.
 *
 * Storage stays UTC on purpose - see App\Support\BusinessTime - so for the
 * last eight hours of every Manila day the server's date is still yesterday.
 * Everything a person sees, and every bound measured against "today", has to
 * be read on the office clock instead, or the two disagree in exactly the
 * hours when work is being closed out.
 *
 * 16:30 UTC is the hour that catches it: half past midnight in Manila, on the
 * following date.
 */
class BusinessTimeDisplayTest extends TestCase
{
    private const UTC_EVENING = '2026-08-23 16:30:00';

    private const MANILA_DATE = '2026-08-24';

    public function test_a_stored_instant_is_read_on_the_office_clock(): void
    {
        $stored = CarbonImmutable::parse(self::UTC_EVENING, 'UTC');

        $this->assertSame('2026-08-24 00:30', BusinessTime::at($stored)?->format('Y-m-d H:i'));
        $this->assertSame('Aug 24, 2026', BusinessTime::format($stored));
    }

    public function test_an_absent_value_falls_back_rather_than_failing(): void
    {
        $this->assertNull(BusinessTime::at(null));
        $this->assertSame('—', BusinessTime::format(null));
        $this->assertSame('N/A', BusinessTime::format(null, 'M d, Y', 'N/A'));
    }

    public function test_today_is_the_offices_today_not_the_servers(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::UTC_EVENING, 'UTC'));

        $this->assertSame('2026-08-23', CarbonImmutable::now()->toDateString());
        $this->assertSame(self::MANILA_DATE, BusinessTime::today()->toDateString());
    }

    /**
     * The regression the display fix would otherwise have created: the picker
     * offers the office's today, so the validator has to accept it. Bounded on
     * UTC this is "the future" and a lead finishing work in the evening is
     * turned away from the date they are standing in.
     */
    public function test_a_completion_date_may_be_the_offices_today(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::UTC_EVENING, 'UTC'));

        $validator = Validator::make(
            ['completion_date' => self::MANILA_DATE],
            ['completion_date' => app(ProjectCompletion::class)->rules()['completion_date']]
        );

        $this->assertFalse($validator->fails(), 'The office\'s today was rejected as a future date.');
    }

    public function test_a_completion_date_past_the_offices_today_is_still_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::UTC_EVENING, 'UTC'));

        $validator = Validator::make(
            ['completion_date' => '2026-08-25'],
            ['completion_date' => app(ProjectCompletion::class)->rules()['completion_date']]
        );

        $this->assertTrue($validator->fails());
    }
}
