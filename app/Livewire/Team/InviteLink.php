<?php

namespace App\Livewire\Team;

use App\Models\TeamInvitation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Livewire\Component;

class InviteLink extends Component
{
    use AuthorizesRequests;

    public string $email;

    // Avail: invitations default to Guest; company emails join as members on their own.
    public string $role = 'guest';

    /** @var list<int|string> Projects a guest may see. */
    public array $guestProjectIds = [];

    /** '30', '60', '90', 'custom' or 'none' (no expiry). */
    public string $accessDuration = '30';

    public ?string $accessUntil = null;

    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'role' => 'required|string|in:owner,admin,member,guest',
            'guestProjectIds' => 'array',
            'guestProjectIds.*' => 'integer',
            'accessDuration' => 'required|in:30,60,90,custom,none',
            'accessUntil' => $this->role === 'guest' && $this->accessDuration === 'custom'
                ? 'required|date|after:today'
                : 'nullable',
        ];
    }

    protected $messages = [
        'accessUntil.required' => 'Pick the date access ends.',
        'accessUntil.after' => 'Pick a date after today.',
    ];

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
        $this->accessDuration = (string) config('avail.guest.default_days');
    }

    public function viaEmail()
    {
        $this->generateInviteLink(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generateInviteLink(sendEmail: false);
    }

    private function invitationUrl(string $routeName, array $parameters): string
    {
        $fqdn = instanceSettings()->fqdn;
        if (filled($fqdn)) {
            return rtrim($fqdn, '/').route($routeName, $parameters, false);
        }

        return route($routeName, $parameters);
    }

    private function generateInviteLink(bool $sendEmail = false)
    {
        // Avail: validate outside the try so the form shows field errors (e.g. a missing end date).
        $this->validate();
        try {
            $this->authorize('manageInvitations', currentTeam());

            // Prevent privilege escalation: users cannot invite someone with higher privileges
            $userRole = auth()->user()->role();
            if (is_null($userRole) || ($userRole === 'member' && in_array($this->role, ['admin', 'owner']))) {
                throw new \Exception('Members cannot invite admins or owners.');
            }
            if ($userRole === 'admin' && $this->role === 'owner') {
                throw new \Exception('Admins cannot invite owners.');
            }

            $this->email = strtolower($this->email);

            $member_emails = currentTeam()->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of ".currentTeam()->name.'.');
            }
            $uuid = new_public_id(32);
            $link = $this->invitationUrl('team.invitation.show', ['uuid' => $uuid]);
            // Avail: Clerk is the only login. No account is created here: the invitee signs in
            // with Clerk (which creates the account), then accepts on the invitation page.
            $invitation = TeamInvitation::ownedByCurrentTeam()->whereEmail($this->email)->first();
            if (! is_null($invitation)) {
                $invitationValid = $invitation->isValid();
                if ($invitationValid) {
                    return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                } else {
                    $invitation->delete();
                }
            }

            $isGuest = $this->role === 'guest';
            $invitation = TeamInvitation::firstOrCreate([
                'team_id' => currentTeam()->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
                'avail_project_ids' => $isGuest
                    ? \App\Models\Project::where('team_id', currentTeam()->id)
                        ->whereIn('id', collect($this->guestProjectIds)->map(fn ($id) => (int) $id)->all())
                        ->pluck('id')->all()
                    : null,
                'avail_access_days' => $isGuest && in_array($this->accessDuration, ['30', '60', '90'], true) ? (int) $this->accessDuration : null,
                'avail_access_until' => $isGuest && $this->accessDuration === 'custom' ? $this->accessUntil : null,
            ]);
            auditLog('ui.team_invitation.created', [
                'team_id' => currentTeam()->id,
                'invitation_uuid' => $invitation->uuid,
                'invitation_email' => $invitation->email,
                'role' => $invitation->role,
                'via' => $invitation->via,
            ]);
            if ($sendEmail) {
                $mail = new MailMessage;
                $mail->view('emails.invitation-link', [
                    'team' => currentTeam()->name,
                    'invitation_link' => $link,
                    'invitation' => $invitation,
                ]);
                $mail->subject('You have been invited to '.currentTeam()->name.' on '.config('app.name').'.');
                send_user_an_email($mail, $this->email);
                $this->dispatch('success', 'Invitation sent via email.');
                $this->dispatch('refreshInvitations');

                return;
            } else {
                $this->dispatch('success', 'Invitation link generated.');
                $this->dispatch('refreshInvitations');
            }
            $this->reset('email', 'guestProjectIds', 'accessUntil');
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }
    }

    public function render()
    {
        return view('livewire.team.invite-link', [
            'guestProjects' => \App\Models\Project::where('team_id', currentTeam()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
