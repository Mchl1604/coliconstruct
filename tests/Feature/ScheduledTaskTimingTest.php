<?php

namespace Tests\Feature;

use App\Models\Schedule as ScheduleModel;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * When the scheduled work actually runs.
 *
 * The times in routes/console.php are written as office hours - 8 AM for the
 * reminders, 3 AM for the sweep - but the scheduler reads them off whatever
 * clock it is given, and this application deliberately stores UTC. Left at
 * that default the 8 AM reminder about tomorrow's work fires at 4 PM in
 * Manila, as people are leaving, and a client's seven days lapse mid-afternoon
 * rather than overnight.
 *
 * `app.schedule_timezone` is what separates the two: the wall clock the jobs
 * are written against, without moving the clock the timestamps are written
 * against. Both halves are asserted here, because fixing one by breaking the
 * other would be the tempting mistake.
 */
class ScheduledTaskTimingTest extends TestCase
{
    public function test_timestamps_are_still_stored_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_the_scheduler_reads_the_office_clock(): void
    {
        $this->assertSame(ScheduleModel::BUSINESS_TIMEZONE, config('app.schedule_timezone'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function scheduledTimes(): array
    {
        return [
            'task reminders' => ['tasks:remind', '08:00'],
            'completion confirmations' => ['projects:process-completion-confirmations', '08:05'],
            'lapsed verification codes' => ['otp:purge-expired', '03:00'],
        ];
    }

    #[DataProvider('scheduledTimes')]
    public function test_each_job_fires_at_its_intended_manila_time(string $needle, string $expected): void
    {
        $event = $this->findEvent($needle);

        $this->assertNotNull($event, sprintf('No scheduled job matching "%s".', $needle));
        $this->assertSame(ScheduleModel::BUSINESS_TIMEZONE, (string) $event->timezone);

        $next = CarbonImmutable::instance(
            (new CronExpression($event->expression))->getNextRunDate(
                CarbonImmutable::now(ScheduleModel::BUSINESS_TIMEZONE)->toDateTime()
            )
        )->setTimezone(ScheduleModel::BUSINESS_TIMEZONE);

        $this->assertSame($expected, $next->format('H:i'));
    }

    private function findEvent(string $needle): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains($event->getSummaryForDisplay(), $needle)) {
                return $event;
            }
        }

        return null;
    }
}
