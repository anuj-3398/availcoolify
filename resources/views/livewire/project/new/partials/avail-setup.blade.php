{{-- Avail: set commands and environment variables before the first deploy (Vercel-style). --}}
@php($buildPack = $buildPack ?? 'railpack')

@if (in_array($buildPack, ['railpack', 'nixpacks'], true))
    <x-forms.collapsible title="Build and run commands" class="mt-1">
        <p class="text-xs text-neutral-500 dark:text-fg-dim">
            Leave a field empty to use what the build pack detects. Commands run from the base directory.
        </p>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-forms.input id="installCommand" label="Install command" placeholder="npm ci"
                helper="Installs dependencies, for example pnpm install --frozen-lockfile." />
            <x-forms.input id="buildCommand" label="Build command" placeholder="npm run build"
                helper="Builds the app, for example pnpm build." />
            <x-forms.input id="startCommand" label="Start command" placeholder="npm start"
                helper="Starts the app. Not used for static sites." />
        </div>
    </x-forms.collapsible>
@endif

<x-forms.collapsible title="Environment variables" class="mt-1">
    <x-forms.textarea id="setupEnvironment" rows="6" monospace
        label="Variables (.env format)"
        placeholder="API_URL=https://api.example.com&#10;OPENAI_API_KEY=sk-..."
        helper="One KEY=value per line. They're available while building and at runtime, and you can change them later under Environment Variables." />
    @unless (auth()->user()?->can('createAnyResource'))
        <p class="text-xs text-neutral-500 dark:text-fg-dim">
            Shared variables such as @{{team.KEY}} can only be added by an admin.
        </p>
    @endunless
</x-forms.collapsible>

<div class="mt-1">
    <x-forms.checkbox id="deployAfterCreate" label="Deploy right away"
        helper="Untick to create the app first and deploy it later, for example to review its settings." />
</div>
