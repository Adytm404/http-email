<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Email;
use App\Services\EmailIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailController extends Controller
{
    public function __construct(private readonly EmailIntake $intake) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->intake->normalize($request->json()->all());
        $email = $this->intake->create($data, $this->key($request), $this->idempotencyKey($request), $request->header('X-Route'));

        return response()->json(['id' => $email->id]);
    }

    public function batch(Request $request): JsonResponse
    {
        $items = $request->json()->all();
        if (! array_is_list($items) || $items === []) {
            throw ValidationException::withMessages(['body' => 'The request body must be a non-empty array of emails.']);
        }
        if (count($items) > 100) {
            throw ValidationException::withMessages(['body' => 'A batch can contain at most 100 emails.']);
        }

        // Validate everything first so a bad item rejects the whole batch.
        $normalized = [];
        foreach ($items as $i => $item) {
            $normalized[] = $this->intake->normalize(is_array($item) ? $item : [], (string) $i);
        }

        $key = $this->key($request);
        $base = $this->idempotencyKey($request);
        $route = $request->header('X-Route');

        $ids = [];
        foreach ($normalized as $i => $data) {
            $email = $this->intake->create($data, $key, $base !== null ? "{$base}:{$i}" : null, $route);
            $ids[] = ['id' => $email->id];
        }

        return response()->json(['data' => $ids]);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 20)));

        $query = Email::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($after = $request->query('after')) {
            $cursor = Email::find($after);
            if ($cursor) {
                $query->where('created_at', '<', $cursor->created_at);
            }
        }

        $rows = $query->limit($limit + 1)->get();

        return response()->json([
            'object' => 'list',
            'has_more' => $rows->count() > $limit,
            'data' => $rows->take($limit)->map(fn (Email $e) => $this->present($e, false))->values(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->present($this->find($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $email = $this->find($id);
        $at = $this->intake->parseSchedule((string) $request->json('scheduled_at', ''));

        if ($email->status !== 'scheduled') {
            return ApiError::make(422, 'validation_error', 'Only scheduled emails can be updated.');
        }
        if (! $at || ! $at->isFuture()) {
            return ApiError::make(422, 'validation_error', 'Invalid `scheduled_at` field. It must be a date in the future.');
        }

        $email->forceFill(['scheduled_at' => $at])->save();
        $this->intake->dispatch($email);

        return response()->json(['object' => 'email', 'id' => $email->id]);
    }

    public function cancel(string $id): JsonResponse
    {
        $email = $this->find($id);

        if ($email->status !== 'scheduled') {
            return ApiError::make(422, 'validation_error', 'Only scheduled emails can be canceled.');
        }

        $email->forceFill(['status' => 'canceled'])->save();

        return response()->json(['object' => 'email', 'id' => $email->id]);
    }

    private function present(Email $e, bool $full = true): array
    {
        $out = [
            'object' => 'email',
            'id' => $e->id,
            'to' => $e->to,
            'from' => $e->from,
            'created_at' => $e->created_at?->format('Y-m-d H:i:s.uP'),
            'subject' => $e->subject,
            'bcc' => $e->bcc,
            'cc' => $e->cc,
            'reply_to' => $e->reply_to,
            'last_event' => $e->lastEvent(),
            'scheduled_at' => $e->scheduled_at?->toIso8601String(),
            // Extensions beyond the Resend schema:
            'status' => $e->status,
            'provider' => $e->provider?->name,
            'provider_message_id' => $e->provider_message_id,
            'attempts' => $e->attempts,
            'sent_at' => $e->sent_at?->toIso8601String(),
            'failed_reason' => $e->failed_reason,
        ];

        if ($full) {
            $out['html'] = $e->html;
            $out['text'] = $e->text;
            $out['tags'] = $e->tags;
        }

        return $out;
    }

    private function find(string $id): Email
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            abort(404);
        }

        return Email::with('provider')->findOrFail($id);
    }

    private function key(Request $request): ?ApiKey
    {
        return $request->attributes->get('api_key');
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return $key !== null && $key !== '' ? mb_substr($key, 0, 255) : null;
    }
}
