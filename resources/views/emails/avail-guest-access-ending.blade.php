<x-emails.layout>
Your guest access to "{{ $team }}" on AvailCoolify ends on {{ $date }}.

@if (count($projects) > 0)
Projects you can see: {{ implode(', ', $projects) }}.
@endif

If you still need access after that date, ask the admin who invited you to extend it.

[Open AvailCoolify]({{ $url }})
</x-emails.layout>
