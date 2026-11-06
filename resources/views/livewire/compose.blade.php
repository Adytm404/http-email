<div>
    <x-ui.page-header eyebrow="Emails" title="Send an email" description="Goes through the same queue, routing and failover as the API." />

    <form wire:submit="send" class="grid grid-cols-1 gap-4 2xl:grid-cols-[1fr_1fr]">
        <div class="space-y-4">
            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <label for="c-from" class="label">From</label>
                        <input id="c-from" wire:model="from" class="field" placeholder="Acme <hello@acme.com>">
                        @error('from') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <div class="flex items-center justify-between">
                            <label for="c-to" class="label">To</label>
                            <button type="button" wire:click="$toggle('showCopies')" class="mb-1.5 text-[12.5px] text-accent hover:underline">{{ $showCopies ? 'Hide' : 'Cc, Bcc, Reply-To' }}</button>
                        </div>
                        <input id="c-to" wire:model="to" class="field" placeholder="user@example.com, other@example.com">
                        @error('to') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    @if ($showCopies)
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <label for="c-cc" class="label">Cc</label>
                                <input id="c-cc" wire:model="cc" class="field">
                                @error('cc') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c-bcc" class="label">Bcc</label>
                                <input id="c-bcc" wire:model="bcc" class="field">
                                @error('bcc') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c-reply" class="label">Reply-To</label>
                                <input id="c-reply" wire:model="replyTo" class="field">
                                @error('replyTo') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                    <div>
                        <label for="c-subject" class="label">Subject</label>
                        <input id="c-subject" wire:model="subject" class="field">
                        @error('subject') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="flex items-center justify-between border-b border-line px-5 py-3">
                    <p class="text-[14px] font-semibold">Body</p>
                    <div class="inline-flex rounded-lg border border-line p-0.5 text-[12.5px]">
                        @foreach (['html' => 'HTML', 'text' => 'Plain text'] as $v => $l)
                            <button type="button" wire:click="$set('format', '{{ $v }}')" @class(['rounded-md px-2.5 py-1', 'bg-accent text-white' => $format === $v, 'text-muted' => $format !== $v])>{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="p-5">
                    @if ($format === 'html')
                        <label for="c-html" class="sr-only">HTML body</label>
                        <textarea id="c-html" wire:model.live.debounce.500ms="html" rows="14" class="field font-mono text-[12.5px] leading-relaxed" spellcheck="false"></textarea>
                        @error('html') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                        <label for="c-text" class="label mt-4">Plain-text version <span class="font-normal text-muted">(optional, improves deliverability)</span></label>
                    @else
                        <label for="c-text" class="sr-only">Plain-text body</label>
                    @endif
                    <textarea id="c-text" wire:model="text" rows="{{ $format === 'html' ? 4 : 14 }}" class="field text-[13.5px] leading-relaxed"></textarea>
                    @error('text') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="c-tags" class="label">Tags</label>
                        <input id="c-tags" wire:model="tags" class="field" placeholder="category:welcome">
                    </div>
                    <div>
                        <label for="c-route" class="label">Route</label>
                        <select id="c-route" wire:model="route" class="field">
                            <option value="">Automatic</option>
                            @foreach ($routes as $r)<option value="{{ $r->name }}">{{ $r->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="c-schedule" class="label">Send at</label>
                        <input id="c-schedule" wire:model="scheduleAt" type="datetime-local" class="field">
                        @error('scheduleAt') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="mt-3 text-[12.5px] text-muted">Times are in {{ config('app.timezone') }}. Leave "Send at" empty to send now.</p>
            </x-ui.card>

            <x-ui.card :padding="false" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-line px-5 py-3">
                    <p class="text-[14px] font-semibold">Preview</p>
                    <span class="text-[12px] text-muted">Rendered without scripts</span>
                </div>
                <div class="bg-white">
                    @if ($format === 'html' && trim($html) !== '')
                        <iframe srcdoc="{{ $html }}" sandbox referrerpolicy="no-referrer" class="h-[320px] w-full sm:h-[420px]" title="Preview"></iframe>
                    @else
                        <pre class="h-[320px] overflow-auto sm:h-[420px] whitespace-pre-wrap p-5 text-[13.5px] text-slate-800">{{ $text ?: 'Nothing to preview yet.' }}</pre>
                    @endif
                </div>
            </x-ui.card>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('emails')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="send">
                    <x-ui.icon name="send" class="size-4" />
                    <span wire:loading.remove wire:target="send">{{ $scheduleAt ? 'Schedule' : 'Send' }}</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </x-ui.button>
            </div>
        </div>
    </form>
</div>
