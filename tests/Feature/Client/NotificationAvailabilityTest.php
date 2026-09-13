<?php

namespace Tests\Feature\Client;

use App\Models\NotificationAvailability as Setting;
use App\Models\Workspace;
use App\Modules\Inbox\Services\AiAutomationSchedule;
use App\Services\NotificationAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['mode' => 'scheduled', 'timezone' => 'UTC', 'weekly_hours' => app(AiAutomationSchedule::class)->defaults(), 'revision' => 0];
    }

    public function test_save_reopen_retention_and_revision(): void
    {
        ['user' => $user] = $this->createSubscribedWorkspaceContext();
        $this->actingAs($user)->getJson('/app/settings/notification-availability')->assertOk()->assertJsonPath('mode', 'always')->assertJsonPath('revision', 0);
        $this->patchJson('/app/settings/notification-availability', $this->payload())->assertOk()->assertJsonPath('revision', 1);
        $this->patchJson('/app/settings/notification-availability', ['mode' => 'paused', 'revision' => 1])->assertOk()->assertJsonPath('mode', 'paused')->assertJsonPath('weekly_hours.0.start', '09:00');
        $this->patchJson('/app/settings/notification-availability', ['mode' => 'always', 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_schedules_are_separate_for_workspaces_and_members(): void
    {
        $a = $this->createSubscribedWorkspaceContext();
        $b = $this->createSubscribedWorkspaceContext();
        $service = app(NotificationAvailability::class);
        Setting::create(['user_id' => $a['user']->id, 'workspace_id' => $a['workspace']->id, ...$this->payload(), 'mode' => 'paused', 'revision' => 1]);
        $this->assertFalse($service->available($a['user'], $a['workspace']->id));
        $this->assertTrue($service->available($b['user'], $b['workspace']->id));
        $this->assertFalse($service->available($a['user'], $b['workspace']->id));
        // Inertia middleware repairs an inaccessible stale selection to an owned workspace.
        $this->actingAs($a['user'])->withSession(['current_workspace_id' => $b['workspace']->id])->patchJson('/app/settings/notification-availability', ['mode' => 'always', 'revision' => 0])->assertUnprocessable();
        $this->assertSame(1, Setting::count());
    }

    public function test_invalid_hours_timezone_and_overlap_are_rejected(): void
    {
        ['user' => $user] = $this->createSubscribedWorkspaceContext();
        $this->actingAs($user);
        $data = $this->payload();
        $this->patchJson('/app/settings/notification-availability', [...$data, 'timezone' => 'Invalid/Zone'])->assertUnprocessable();
        $data['weekly_hours'][0]['end'] = '09:00';
        $this->patchJson('/app/settings/notification-availability', $data)->assertUnprocessable();
        $data = $this->payload();
        foreach ($data['weekly_hours'] as &$day) {
            $day['enabled'] = false;
        }
        unset($day);
        $this->patchJson('/app/settings/notification-availability', $data)->assertUnprocessable();
        $data = $this->payload();
        $data['weekly_hours'][0] = ['enabled' => true, 'all_day' => false, 'start' => '22:00', 'end' => '10:00'];
        $this->patchJson('/app/settings/notification-availability', $data)->assertUnprocessable();
    }

    public function test_boundaries_overnight_all_day_and_dst(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $setting = Setting::create(['user_id' => $ctx['user']->id, 'workspace_id' => $ctx['workspace']->id, ...$this->payload()]);
        $service = app(NotificationAvailability::class);
        foreach (['2026-09-14 08:59' => false, '2026-09-14 09:00' => true, '2026-09-14 16:59' => true, '2026-09-14 17:00' => false, '2026-09-19 10:00' => false] as $at => $expected) {
            $this->assertSame($expected, $service->available($ctx['user'], $ctx['workspace']->id, CarbonImmutable::parse($at, 'UTC')));
        }
        $hours = array_fill(0, 7, ['enabled' => false, 'all_day' => false, 'start' => '09:00', 'end' => '17:00']);
        $hours[6] = ['enabled' => true, 'all_day' => false, 'start' => '22:00', 'end' => '02:00'];
        $setting->update(['weekly_hours' => $hours]);
        $this->assertTrue($service->available($ctx['user'], $ctx['workspace']->id, CarbonImmutable::parse('2026-09-14 01:59', 'UTC')));
        $this->assertFalse($service->available($ctx['user'], $ctx['workspace']->id, CarbonImmutable::parse('2026-09-14 02:00', 'UTC')));
        $hours[6] = ['enabled' => true, 'all_day' => false, 'start' => '01:00', 'end' => '03:00'];
        $setting->update(['weekly_hours' => $hours, 'timezone' => 'America/New_York']);
        foreach (['2026-11-01 05:30', '2026-11-01 06:30', '2026-03-08 07:00'] as $at) {
            $this->assertSame($at !== '2026-03-08 07:00', $service->available($ctx['user'], $ctx['workspace']->id, CarbonImmutable::parse($at, 'UTC')));
        }
        $hours[6]['all_day'] = true;
        $setting->update(['weekly_hours' => $hours]);
        $this->assertTrue($service->available($ctx['user'], $ctx['workspace']->id, CarbonImmutable::parse('2026-03-08 07:00', 'UTC')));
    }

    public function test_api_authentication_and_inactive_member(): void
    {
        $this->getJson('/api/v1/notification-availability')->assertUnauthorized();
        $ctx = $this->createSubscribedWorkspaceContext();
        Sanctum::actingAs($ctx['user']);
        $this->getJson('/api/v1/notification-availability')->assertOk()->assertJsonPath('workspace_id', $ctx['workspace']->id);
        $this->patchJson('/api/v1/notification-availability', $this->payload())->assertOk();
        $ctx['user']->update(['status' => 'inactive']);
        $this->assertFalse(app(NotificationAvailability::class)->available($ctx['user'], $ctx['workspace']->id));
    }

    public function test_same_member_has_independent_workspace_settings_and_deletion_cascades(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $other = Workspace::factory()->create(['owner_id' => $ctx['user']->id, 'client_id' => $ctx['client']->id]);
        $this->actingAs($ctx['user'])->withSession(['current_workspace_id' => $ctx['workspace']->id])
            ->patchJson('/app/settings/notification-availability', ['mode' => 'paused', 'revision' => 0])->assertOk();
        $this->withSession(['current_workspace_id' => $other->id])->getJson('/app/settings/notification-availability')
            ->assertOk()->assertJsonPath('mode', 'always');
        $this->patchJson('/app/settings/notification-availability', $this->payload())->assertOk();
        $this->assertSame(2, Setting::count());
        $other->delete();
        $this->assertSame(1, Setting::count());
        $ctx['user']->delete();
        $this->assertSame(0, Setting::count());
    }

    public function test_next_boundary_is_start_inclusive_and_all_day_overrides_incomplete_hours(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $this->travelTo(CarbonImmutable::parse('2026-09-14 08:59:30', 'UTC'));
        $this->actingAs($ctx['user'])->patchJson('/app/settings/notification-availability', $this->payload())
            ->assertOk()->assertJsonPath('active', false)->assertJsonPath('next_boundary', '2026-09-14T09:00:00+00:00');
        $data = $this->payload();
        $data['revision'] = 1;
        $data['weekly_hours'][0] = ['enabled' => true, 'all_day' => true, 'start' => '', 'end' => ''];
        $this->patchJson('/app/settings/notification-availability', $data)->assertOk()->assertJsonPath('active', true);
    }
}
