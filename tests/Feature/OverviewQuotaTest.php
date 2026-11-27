<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\Email;
use App\Models\Provider;
use App\Routing\QuotaManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OverviewQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function sentVia(Provider $p, CarbonImmutable $at, int $times = 1): void
    {
        foreach (range(1, $times) as $_) {
            $e = Email::create(['from' => 'a@b.test', 'to' => ['c@d.test'], 'subject' => 'S', 'html' => '<p>x</p>', 'status' => 'sent', 'provider_id' => $p->id]);
            $e->forceFill(['created_at' => $at, 'sent_at' => $at->addSecond()])->saveQuietly();
        }
    }

    public function test_capacity_takes_the_tightest_limit_for_the_window(): void
    {
        $this->assertSame(100, $this->provider('hourly', ['limit_per_hour' => 100, 'limit_per_day' => 450])->capacity('hour'));
        $this->assertSame(450, $this->provider('hourly day', ['limit_per_hour' => 100, 'limit_per_day' => 450])->capacity('day'), 'a daily limit below 24 x hourly wins');
        $this->assertSame(2400, $this->provider('hourly only', ['limit_per_hour' => 100])->capacity('day'));
        $this->assertSame(50, $this->provider('day only', ['limit_per_day' => 50])->capacity('hour'), 'an hour cannot exceed the daily limit');
        $this->assertSame(600, $this->provider('minute', ['limit_per_minute' => 10])->capacity('hour'));
        $this->assertNull($this->provider('none')->capacity('hour'));
        $this->assertNull($this->provider('none day')->capacity('day'));
    }

    public function test_chart_capacity_adds_up_active_providers_and_counts_unlimited_ones(): void
    {
        $this->actingAs($this->owner());
        $this->provider('A', ['limit_per_hour' => 100, 'limit_per_day' => 450]);
        $this->provider('B', ['limit_per_day' => 50]);
        $this->provider('C');
        $this->provider('Off', ['limit_per_hour' => 999, 'is_active' => false]);

        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('capacity', 150)
            ->assertViewHas('unlimited', 1);

        Livewire::test(Overview::class)->set('range', 7)
            ->assertViewHas('capacity', 500)
            ->assertViewHas('unlimited', 1);
    }

    public function test_each_bar_knows_how_many_emails_every_provider_delivered(): void
    {
        $this->actingAs($this->owner());
        $a = $this->provider('A', ['limit_per_hour' => 100]);
        $b = $this->provider('B', ['limit_per_hour' => 100]);
        $this->sentVia($a, CarbonImmutable::today()->addHours(2)->addMinutes(5), 3);
        $this->sentVia($b, CarbonImmutable::today()->addHours(2)->addMinutes(40), 2);
        $this->sentVia($a, CarbonImmutable::today()->addHours(5), 1);

        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('days', fn ($days) => $days[2]['by'] === [$a->id => 3, $b->id => 2]
                && $days[2]['sent'] === 5
                && $days[5]['by'] === [$a->id => 1]
                && $days[0]['by'] === []);

        Livewire::test(Overview::class)->set('range', 7)
            ->assertViewHas('days', fn ($days) => $days->last()['by'] === [$a->id => 4, $b->id => 2]);
    }

    public function test_the_axis_always_reaches_the_quota_so_the_limit_stays_visible(): void
    {
        $this->actingAs($this->owner());
        $a = $this->provider('A', ['limit_per_hour' => 1000]);

        // volume far below the quota: the axis still reaches it
        $this->sentVia($a, CarbonImmutable::today()->addHour(), 2);
        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('capacity', 1000)
            ->assertViewHas('axis', fn ($axis) => $axis['top'] >= 1000)
            ->assertSee('Quota 1,000/h');

        // no volume at all: same
        Email::query()->delete();
        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('axis', fn ($axis) => $axis['top'] >= 1000)
            ->assertSee('Quota 1,000/h');

        // volume above the quota: the axis grows with the bars
        $this->sentVia($a, CarbonImmutable::today()->addHour(), 1200);
        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('axis', fn ($axis) => $axis['top'] >= 1200);
    }

    public function test_the_quota_line_follows_the_selected_range(): void
    {
        $this->actingAs($this->owner());
        $this->provider('A', ['limit_per_hour' => 100, 'limit_per_day' => 450]);

        Livewire::test(Overview::class)->set('range', 1)->assertSee('Quota 100/h')->assertSee('Quota per hour');
        Livewire::test(Overview::class)->set('range', 7)->assertSee('Quota 450/day')->assertSee('Quota per day');
        Livewire::test(Overview::class)->set('range', 30)->assertSee('Quota 450/day');
    }

    public function test_providers_near_their_quota_raise_a_warning(): void
    {
        $this->actingAs($this->owner());
        $quota = app(QuotaManager::class);
        $busy = $this->provider('Busy', ['limit_per_hour' => 10]);
        $calm = $this->provider('Calm', ['limit_per_hour' => 10]);
        foreach (range(1, 9) as $_) {
            $quota->reserve($busy);
        }
        $quota->reserve($calm);

        $component = Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('nearLimit', fn ($rows) => $rows->count() === 1 && $rows[0]['name'] === 'Busy' && $rows[0]['pct'] === 90)
            ->assertViewHas('quotaRows', fn ($rows) => $rows->pluck('name')->all() === ['Busy', 'Calm'] && $rows[0]['left'] === 1);

        $component->assertSee('1 provider near the sending quota')->assertSee('Quota right now')->assertSee('1 left');
    }

    public function test_a_provider_at_its_limit_is_reported_as_full(): void
    {
        $this->actingAs($this->owner());
        $p = $this->provider('Full', ['limit_per_hour' => 2]);
        app(QuotaManager::class)->reserve($p);
        app(QuotaManager::class)->reserve($p);

        Livewire::test(Overview::class)
            ->assertViewHas('quotaRows', fn ($rows) => $rows[0]['full'] === true && $rows[0]['pct'] === 100 && $rows[0]['left'] === 0)
            ->assertSee('at or near the sending quota')
            ->assertSee('full');
    }

    public function test_no_warning_when_usage_is_low_or_there_are_no_limits(): void
    {
        $this->actingAs($this->owner());
        $quota = app(QuotaManager::class);
        $a = $this->provider('A', ['limit_per_hour' => 100]);
        $quota->reserve($a);
        $this->provider('Unlimited');

        Livewire::test(Overview::class)
            ->assertViewHas('nearLimit', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('quotaRows', fn ($rows) => $rows->count() === 1)
            ->assertDontSee('near the sending quota');
    }

    public function test_the_page_renders_for_every_range_with_providers_and_limits(): void
    {
        $this->actingAs($this->owner());
        $a = $this->provider('A', ['limit_per_hour' => 100, 'limit_per_day' => 450]);
        $this->sentVia($a, CarbonImmutable::now()->subMinutes(5), 12);

        foreach ([1, 7, 14, 30] as $range) {
            $this->get("/dashboard?range={$range}")->assertOk()->assertSee('Quota right now');
        }
    }
}
