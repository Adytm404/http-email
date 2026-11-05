<div>
    <x-ui.page-header eyebrow="Docs" title="One line to change"
        description="The API follows Resend's request and response format. Point an existing Resend integration at this server, or call it with any HTTP client." />

    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-4">
            <x-ui.card :padding="false" x-data="{ tab: 'curl', copied: false }">
                <div class="flex items-center justify-between gap-2 border-b border-line px-3 pt-2">
                    <div class="flex gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" role="tablist">
                        @foreach (['curl' => 'cURL', 'node' => 'Node.js', 'python' => 'Python', 'php' => 'PHP'] as $key => $label)
                            <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                                    class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-[13px] transition-colors"
                                    :class="tab === '{{ $key }}' ? 'border-accent font-medium text-ink' : 'border-transparent text-muted hover:text-ink'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <button type="button" class="mb-1.5 rounded-[4px] px-2 py-1 text-[12.5px] text-muted hover:bg-surface-2 hover:text-ink"
                            x-on:click="navigator.clipboard.writeText($refs[tab].innerText); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
                @foreach ($snippets as $key => $code)
                    <pre x-ref="{{ $key }}" x-show="tab === '{{ $key }}'" @if ($key !== 'curl') x-cloak @endif
                         class="overflow-x-auto rounded-b-2xl bg-[#0b1120] p-5 font-mono text-[12.5px] leading-relaxed text-slate-200">{{ $code }}</pre>
                @endforeach
            </x-ui.card>

            <x-ui.card :padding="false">
                <h2 class="px-6 pb-2 pt-5 text-[15px] font-semibold">Endpoints</h2>
                @foreach ($endpoints as [$method, $path, $desc])
                    <div class="flex flex-col gap-1 border-t border-line px-6 py-3 sm:flex-row sm:items-baseline sm:gap-4">
                        <code class="flex shrink-0 items-baseline gap-2 font-mono text-[13px] sm:w-56">
                            <span @class(['w-12 text-[11px] font-semibold', 'text-ok' => $method === 'GET', 'text-accent' => $method === 'POST', 'text-warn' => $method === 'PATCH'])>{{ $method }}</span>{{ $path }}
                        </code>
                        <p class="text-[13px] text-muted">{{ $desc }}</p>
                    </div>
                @endforeach
            </x-ui.card>

            <x-ui.card :padding="false">
                <h2 class="px-6 pb-2 pt-5 text-[15px] font-semibold">Request body</h2>
                @foreach ($fields as [$name, $type, $desc])
                    <div class="flex flex-col gap-1 border-t border-line px-6 py-3 sm:flex-row sm:gap-4">
                        <div class="shrink-0 sm:w-56">
                            <code class="font-mono text-[13px]">{{ $name }}</code>
                            <span class="ml-1.5 text-[12px] text-subtle">{{ $type }}</span>
                        </div>
                        <p class="text-[13px] text-muted">{{ $desc }}</p>
                    </div>
                @endforeach
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <div class="panel-gradient rounded-3xl p-5">
                <p class="text-[13px] text-white/75">Base URL</p>
                <p class="mt-4 break-all font-mono text-[15px]">{{ $base }}</p>
                <p class="mt-3 text-[12.5px] text-white/75">Also served under <span class="font-mono">/v1</span>.</p>
                <x-ui.button variant="white" size="sm" :href="route('keys')" class="mt-5">Create an API key</x-ui.button>
            </div>

            <x-ui.card>
                <h2 class="text-[15px] font-semibold">Headers</h2>
                <dl class="mt-3 space-y-3">
                    @foreach ($headers as [$name, $desc])
                        <div>
                            <dt class="font-mono text-[12.5px]">{{ $name }}</dt>
                            <dd class="text-[12.5px] text-muted">{{ $desc }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h2 class="text-[15px] font-semibold">Errors</h2>
                <p class="mt-1 text-[12.5px] text-muted">Same shape as Resend.</p>
                <pre class="mt-3 overflow-x-auto rounded-xl bg-canvas p-3 font-mono text-[12px]">{
  "statusCode": 422,
  "name": "validation_error",
  "message": "…"
}</pre>
                <ul class="mt-3 space-y-1 text-[12.5px] text-muted">
                    <li><span class="font-mono text-ink">401</span> missing_api_key, restricted_api_key</li>
                    <li><span class="font-mono text-ink">403</span> invalid_api_key</li>
                    <li><span class="font-mono text-ink">404</span> not_found</li>
                    <li><span class="font-mono text-ink">422</span> validation_error</li>
                    <li><span class="font-mono text-ink">429</span> rate_limit_exceeded</li>
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
