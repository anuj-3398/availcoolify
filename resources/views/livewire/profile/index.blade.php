<div x-data="{
    emailModalOpen: @js($show_verification),
    openEmailModal() {
        this.emailModalOpen = true;
        this.$nextTick(() => this.$refs.newEmailInput?.focus());
    },
}"
    @close-email-change-modal.window="emailModalOpen = false">
    <x-slot:title>Profile</x-slot>
    <div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
        <section class="application-settings-section" x-data="{
            preview: null,
            processing: false,
            uploadError: null,
            async prepareAvatar(event) {
                const file = event.target.files?.[0];
                if (!file) return;
                this.processing = true;
                this.uploadError = null;

                try {
                    const dataUrl = await new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => resolve(reader.result);
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                    const image = await new Promise((resolve, reject) => {
                        const element = new Image();
                        element.onload = () => resolve(element);
                        element.onerror = reject;
                        element.src = dataUrl;
                    });
                    const cropSize = Math.min(image.naturalWidth, image.naturalHeight);
                    const canvas = document.createElement('canvas');
                    canvas.width = 256;
                    canvas.height = 256;
                    const context = canvas.getContext('2d');
                    context.fillStyle = '#ffffff';
                    context.fillRect(0, 0, 256, 256);
                    context.drawImage(
                        image,
                        (image.naturalWidth - cropSize) / 2,
                        (image.naturalHeight - cropSize) / 2,
                        cropSize,
                        cropSize,
                        0,
                        0,
                        256,
                        256,
                    );
                    const blob = await new Promise((resolve, reject) => {
                        canvas.toBlob(value => value ? resolve(value) : reject(new Error('JPEG compression failed')), 'image/jpeg', 0.8);
                    });
                    const previewUrl = URL.createObjectURL(blob);
                    const compressed = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                    this.$wire.upload('avatar', compressed, async () => {
                        try {
                            const uploaded = await this.$wire.uploadAvatar();
                            if (uploaded) {
                                if (this.preview) URL.revokeObjectURL(this.preview);
                                this.preview = previewUrl;
                            } else {
                                URL.revokeObjectURL(previewUrl);
                            }
                        } catch (error) {
                            URL.revokeObjectURL(previewUrl);
                            this.uploadError = 'The image could not be uploaded.';
                        } finally {
                            this.processing = false;
                        }
                    }, () => {
                        URL.revokeObjectURL(previewUrl);
                        this.processing = false;
                        this.uploadError = 'The image could not be uploaded.';
                    });
                } catch (error) {
                    this.processing = false;
                    this.uploadError = 'The image could not be processed in this browser.';
                }
            },
        }">
            <div class="application-settings-section-header">
                <div>
                    <h2>Profile picture</h2>
                    <p>Upload a JPG, PNG, or WebP image.</p>
                </div>
            </div>
            <div class="application-settings-section-body flex flex-col gap-4 sm:flex-row sm:items-center">
                <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-200 text-2xl font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg">
                    <img x-cloak x-show="preview" :src="preview" alt="Profile picture preview"
                        class="h-full w-full object-cover">
                    @if (auth()->user()->avatar_path)
                        <img src="{{ profile_avatar_url(auth()->user()) }}"
                            x-show="!preview" alt="{{ auth()->user()->name }}" class="h-full w-full object-cover">
                    @else
                        <span x-show="!preview">
                            {{ strtoupper(mb_substr(auth()->user()->name ?: auth()->user()->email, 0, 1)) }}
                        </span>
                    @endif
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <input x-ref="avatarInput" type="file" x-on:change="prepareAvatar($event)"
                            accept="image/jpeg,image/png,image/webp" class="hidden">
                        <x-forms.button type="button" x-on:click="$refs.avatarInput.click()"
                            x-bind:disabled="processing">
                            <span x-text="processing ? 'Uploading…' : 'Browse…'"></span>
                        </x-forms.button>
                        @if (auth()->user()->avatar_path)
                            <x-forms.button type="button" wire:click="removeAvatar" x-bind:disabled="processing"
                                isError>Remove</x-forms.button>
                        @endif
                    </div>
                    <p x-cloak x-show="uploadError" x-text="uploadError" class="text-xs text-red-500"></p>
                    @error('avatar')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div>
                        <h2>Profile details</h2>
                        <p>Your display name and verified sign-in address.</p>
                    </div>
                </div>
                <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                    <x-forms.input id="name" label="Name" required />
                    <div class="flex items-end gap-2">
                        <x-forms.input id="email" label="Email" readonly />
                        <x-forms.button @click="openEmailModal()" type="button"
                            :disabled="$uses_sso" x-bind:disabled="emailModalOpen || @js($uses_sso)">
                            Change
                        </x-forms.button>
                    </div>
                </div>
             </section>
         </form>

         @if ($uses_sso)
             <x-callout type="info" title="Email managed by SSO">
                 Signed in with SSO @if ($sso_provider_label) ({{ $sso_provider_label }}) @endif. Email is managed by your SSO provider.
             </x-callout>
         @endif

         @if (! $uses_sso)
         <template x-teleport="body">
            <div x-show="emailModalOpen" x-cloak
                class="fixed inset-0 z-99 flex h-screen w-screen items-center justify-center p-4">
                <div class="absolute inset-0 h-full w-full bg-black/55 backdrop-blur-[3px]"></div>
                <div x-show="emailModalOpen" x-trap.inert.noscroll="emailModalOpen"
                    class="application-settings-form application-settings-section relative w-full max-w-xl overflow-hidden"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                        <div>
                            <h3>{{ $show_verification ? 'Verify new email' : 'Change email' }}</h3>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                @if ($show_verification)
                                    Code sent to {{ $new_email ?: auth()->user()->pending_email }}.
                                @else
                                    A six-digit verification code will be sent to the new address.
                                @endif
                            </p>
                        </div>
                        <button type="button"
                            @click="@if ($show_verification) $wire.cancelEmailChange().then(() => emailModalOpen = false) @else emailModalOpen = false @endif"
                            class="icon-button shrink-0" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>

                    @if ($show_verification)
                        <form wire:submit="verifyEmailChange" class="application-settings-section-body space-y-4">
                            <x-forms.input id="email_verification_code" label="Verification code" required
                                inputmode="numeric" maxlength="6" />
                            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                                The code expires after
                                {{ config('constants.email_change.verification_code_expiry_minutes', 10) }} minutes.
                            </p>
                            <div class="flex justify-end gap-2">
                                <x-forms.button wire:click="resendVerificationCode" type="button">Resend code</x-forms.button>
                                <x-forms.button type="submit" isHighlighted>Verify email</x-forms.button>
                            </div>
                        </form>
                    @else
                        <form wire:submit="requestEmailChange" class="application-settings-section-body space-y-4">
                            <x-forms.input id="new_email" label="New email address" required type="email"
                                x-ref="newEmailInput" />
                            <div class="flex justify-end">
                                <x-forms.button type="submit" isHighlighted>Send code</x-forms.button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
         </template>
         @endif

        @if (session()->has('errors'))
            <x-callout type="danger" title="Profile update failed">
                Something went wrong. Please review the fields and try again.
            </x-callout>
        @endif
    </div>
</div>
