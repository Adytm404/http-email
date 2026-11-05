<?php

namespace Tests\Feature;

use App\Models\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->apiKey();
        $this->provider('Primary');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'from' => 'Acme <hello@acme.test>',
            'to' => 'user@example.com',
            'subject' => 'Hello',
            'html' => '<p>Hi</p>',
        ], $overrides);
    }

    public function test_missing_key_returns_resend_style_401(): void
    {
        $this->postJson('/emails', $this->payload())
            ->assertStatus(401)
            ->assertExactJson([
                'statusCode' => 401,
                'name' => 'missing_api_key',
                'message' => 'Missing API key in the authorization header. Include the following header in the request: Authorization: Bearer YOUR_API_KEY',
            ]);
    }

    public function test_invalid_key_returns_403(): void
    {
        $this->withToken('re_wrong')->postJson('/emails', $this->payload())
            ->assertStatus(403)
            ->assertJson(['name' => 'invalid_api_key']);
    }

    public function test_send_queues_and_delivers(): void
    {
        $response = $this->withToken($this->token)->postJson('/emails', $this->payload())
            ->assertOk()
            ->assertJsonStructure(['id']);

        $email = Email::find($response->json('id'));
        $this->assertSame('sent', $email->status);
        $this->assertSame(['user@example.com'], $email->to);
        $this->assertSame('msg-Primary', $email->provider_message_id);
    }

    public function test_v1_alias_works(): void
    {
        $this->withToken($this->token)->postJson('/v1/emails', $this->payload())->assertOk();
    }

    public function test_validation_error_format(): void
    {
        $this->withToken($this->token)->postJson('/emails', $this->payload(['to' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJson(['statusCode' => 422, 'name' => 'validation_error']);

        $this->withToken($this->token)->postJson('/emails', $this->payload(['html' => null]))
            ->assertStatus(422)
            ->assertJson(['message' => 'Missing `html` or `text` field.']);
    }

    public function test_idempotency_key_returns_same_email(): void
    {
        $a = $this->withToken($this->token)->withHeader('Idempotency-Key', 'order-1')->postJson('/emails', $this->payload())->json('id');
        $b = $this->withToken($this->token)->withHeader('Idempotency-Key', 'order-1')->postJson('/emails', $this->payload())->json('id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Email::count());
    }

    public function test_batch(): void
    {
        $this->withToken($this->token)->postJson('/emails/batch', [
            $this->payload(['to' => 'a@example.com']),
            $this->payload(['to' => ['b@example.com', 'c@example.com']]),
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(2, Email::where('status', 'sent')->count());
    }

    public function test_batch_rejects_everything_when_one_item_is_invalid(): void
    {
        $this->withToken($this->token)->postJson('/emails/batch', [
            $this->payload(),
            $this->payload(['subject' => null]),
        ])->assertStatus(422);

        $this->assertSame(0, Email::count());
    }

    public function test_get_email(): void
    {
        $id = $this->withToken($this->token)->postJson('/emails', $this->payload())->json('id');

        $this->withToken($this->token)->getJson("/emails/{$id}")
            ->assertOk()
            ->assertJson(['object' => 'email', 'id' => $id, 'last_event' => 'delivered', 'provider' => 'Primary']);

        $this->withToken($this->token)->getJson('/emails/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404)->assertJson(['name' => 'not_found']);
    }

    public function test_sending_only_key_cannot_read(): void
    {
        [, $sendOnly] = $this->apiKey('sending_access');
        $id = $this->withToken($sendOnly)->postJson('/emails', $this->payload())->assertOk()->json('id');

        $this->withToken($sendOnly)->getJson("/emails/{$id}")
            ->assertStatus(401)->assertJson(['name' => 'restricted_api_key']);
    }

    public function test_schedule_and_cancel(): void
    {
        $id = $this->withToken($this->token)->postJson('/emails', $this->payload(['scheduled_at' => 'in 1 hour']))->json('id');

        $this->assertSame('scheduled', Email::find($id)->status);

        $this->withToken($this->token)->postJson("/emails/{$id}/cancel")->assertOk();
        $this->assertSame('canceled', Email::find($id)->status);

        $this->withToken($this->token)->postJson("/emails/{$id}/cancel")->assertStatus(422);
    }

    public function test_attachments_are_stored(): void
    {
        $id = $this->withToken($this->token)->postJson('/emails', $this->payload([
            'attachments' => [['filename' => 'invoice.txt', 'content' => base64_encode('hello')]],
        ]))->assertOk()->json('id');

        $email = Email::with('attachments')->find($id);
        $this->assertCount(1, $email->attachments);
        $this->assertSame(5, $email->attachments[0]->size);
    }
}
