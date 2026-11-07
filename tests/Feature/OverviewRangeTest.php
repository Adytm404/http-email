<?php

namespace Tests\Feature;

use App\Livewire\Overview;
use App\Models\Email;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OverviewRangeTest extends TestCase
{
    use RefreshDatabase;

    private function sent(CarbonImmutable $createdAt, int $seconds): Email
    {
        $e = Email::create(['from' => 'a@b.test', 'to' => ['c@d.test'], 'subject' => 'S', 'html' => '<p>x</p>', 'status' => 'sent']);
        $e->forceFill(['created_at' => $createdAt, 'sent_at' => $createdAt->addSeconds($seconds)])->saveQuietly();

        return $e;
    }

    public function test_today_is_a_range_option_and_the_page_renders(): void
    {
        $this->actingAs($this->owner());

        $this->assertArrayHasKey(1, Overview::RANGES);
        $this->get('/dashboard?range=1')->assertOk()->assertSee('Today')->assertSee('Volume per hour');
    }

    public function test_average_delivery_follows_the_selected_range(): void
    {
        $this->actingAs($this->owner());
        $today = CarbonImmutable::today()->addMinute();
        $this->sent($today, 4);
        $this->sent($today, 6);
        $this->sent(CarbonImmutable::today()->subDays(3)->addHour(), 100);

        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('avgSeconds', fn ($v) => abs($v - 5.0) < 0.01)
            ->assertViewHas('sent', 2);

        Livewire::test(Overview::class)->set('range', 7)
            ->assertViewHas('avgSeconds', fn ($v) => abs($v - 110 / 3) < 0.01)
            ->assertViewHas('sent', 3);
    }

    public function test_today_chart_has_24_hourly_buckets_with_the_right_counts(): void
    {
        $this->actingAs($this->owner());
        $this->sent(CarbonImmutable::today()->addHours(3)->addMinutes(5), 2);
        $this->sent(CarbonImmutable::today()->addHours(3)->addMinutes(40), 2);
        $this->sent(CarbonImmutable::today()->subDay()->addHours(3), 2);

        Livewire::test(Overview::class)->set('range', 1)
            ->assertViewHas('days', fn ($days) => $days->count() === 24
                && $days[3]['sent'] === 2
                && $days->sum('sent') === 2
                && $days[3]['label'] === '03');
    }

    public function test_unknown_range_falls_back_to_seven_days(): void
    {
        $this->actingAs($this->owner());

        Livewire::withQueryParams(['range' => 5])->test(Overview::class)->assertSet('range', 7);
    }
}
