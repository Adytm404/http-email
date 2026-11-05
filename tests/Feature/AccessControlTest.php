<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Compose;
use App\Livewire\Roles;
use App\Livewire\Users;
use App\Models\Email;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function email(): Email
    {
        return Email::create(['from' => 'a@b.test', 'to' => ['c@d.test'], 'subject' => 'Secret plans', 'html' => '<p>TOP-SECRET-BODY</p>', 'status' => 'sent']);
    }

    public function test_viewer_role_is_limited(): void
    {
        $this->actingAs($this->userWithRole('Viewer'));
        $email = $this->email();

        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard/emails')->assertOk();
        $this->get("/dashboard/emails/{$email->id}")->assertOk()->assertDontSee('TOP-SECRET-BODY', false)->assertSee('Content hidden');
        $this->get("/dashboard/emails/{$email->id}/eml")->assertForbidden();

        foreach (['/dashboard/providers', '/dashboard/routing', '/dashboard/keys', '/dashboard/users', '/dashboard/roles', '/dashboard/settings', '/dashboard/send', '/dashboard/emails-export'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_sidebar_only_lists_allowed_pages(): void
    {
        $this->actingAs($this->userWithRole('Viewer'));

        $this->get('/dashboard')->assertSee('Emails')->assertDontSee('/dashboard/providers', false)->assertDontSee('/dashboard/users', false);
    }

    public function test_dynamic_role_permissions_take_effect(): void
    {
        $role = Role::create(['name' => 'Support', 'permissions' => ['view_emails']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        $this->get('/dashboard')->assertForbidden();
        $this->get('/dashboard/start')->assertRedirect(route('emails'));

        // Each real request loads the user fresh; mirror that for the acting user.
        $role->update(['permissions' => ['view_emails', 'manage_providers']]);
        $this->actingAs($user->fresh())->get('/dashboard/providers')->assertOk();
    }

    public function test_deactivated_user_is_signed_out(): void
    {
        $user = $this->owner(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_owner_manages_users_but_cannot_remove_last_owner(): void
    {
        $me = $this->owner();
        $this->actingAs($me);
        $viewer = Role::where('name', 'Viewer')->first();

        Livewire::test(Users::class)
            ->call('create')
            ->set('form.name', 'Sam')
            ->set('form.email', 'sam@example.com')
            ->set('form.role_id', $viewer->id)
            ->set('form.password', 'long-enough-pass')
            ->call('save')
            ->assertHasNoErrors();

        $sam = User::where('email', 'sam@example.com')->first();
        $this->assertSame($viewer->id, $sam->role_id);

        // Demoting the only owner (yourself) is refused.
        Livewire::test(Users::class)
            ->call('edit', $me->id)
            ->set('form.role_id', $viewer->id)
            ->call('save')
            ->assertHasErrors('form.role_id');

        Livewire::test(Users::class)->call('delete', $sam->id);
        $this->assertNull(User::find($sam->id));
    }

    public function test_roles_can_be_created_and_owner_role_is_protected(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(Roles::class)
            ->call('create')
            ->set('name', 'Auditor')
            ->set('permissions', ['view_emails', 'export_emails', 'not_a_permission'])
            ->call('save')
            ->assertHasErrors('permissions.2');

        Livewire::test(Roles::class)
            ->call('create')
            ->set('name', 'Auditor')
            ->set('permissions', ['view_emails', 'export_emails'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['view_emails', 'export_emails'], Role::where('name', 'Auditor')->first()->permissions);

        Livewire::test(Roles::class)->call('edit', Role::owner()->id)->assertForbidden();
        Livewire::test(Roles::class)->call('delete', Role::owner()->id);
        $this->assertNotNull(Role::owner());
    }

    public function test_user_without_permission_cannot_call_livewire_actions(): void
    {
        $this->actingAs($this->userWithRole('Viewer'));

        Livewire::test(Users::class)->assertForbidden();
    }

    public function test_login_is_locked_for_30_minutes_after_five_failures(): void
    {
        $this->owner(['email' => 'boss@example.com']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'boss@example.com')->set('password', 'wrong')->call('login')->assertHasErrors('email');
        }

        // Even the right password is refused while locked.
        Livewire::test(Login::class)->set('email', 'boss@example.com')->set('password', 'password')->call('login')
            ->assertHasErrors('email')
            ->assertSee('Too many failed sign-in attempts. Try again in 30 minutes.');
        $this->assertGuest();

        $this->travel(31)->minutes();
        Livewire::test(Login::class)->set('email', 'boss@example.com')->set('password', 'password')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_compose_queues_an_email(): void
    {
        $this->actingAs($this->owner());
        $this->provider('A');

        Livewire::test(Compose::class)
            ->set('from', 'Acme <hi@acme.test>')
            ->set('to', 'one@example.com, two@example.com')
            ->set('subject', 'From the dashboard')
            ->set('tags', 'category:test')
            ->call('send')
            ->assertHasNoErrors();

        $email = Email::where('subject', 'From the dashboard')->first();
        $this->assertSame(['one@example.com', 'two@example.com'], $email->to);
        $this->assertSame('sent', $email->status);
        $this->assertSame([['name' => 'category', 'value' => 'test']], $email->tags);
    }

    public function test_eml_download_and_csv_export(): void
    {
        $this->actingAs($this->owner());
        $email = $this->email();

        $eml = $this->get("/dashboard/emails/{$email->id}/eml")->assertOk();
        $this->assertStringContainsString('Subject: Secret plans', $eml->getContent());
        $this->assertStringContainsString('TOP-SECRET-BODY', $eml->getContent());

        $csv = $this->get('/dashboard/emails-export?status=sent')->assertOk()->streamedContent();
        $this->assertStringContainsString('Secret plans', $csv);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('login:boss@example.com|127.0.0.1');
        parent::tearDown();
    }
}
