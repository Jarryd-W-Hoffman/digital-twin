<x-mail::message>
# {{ $alert->subject() }}

{{ $alert->message }}

- **Twin:** {{ $alert->twin }} ({{ $alert->project }})
- **Severity:** {{ ucfirst($alert->severity->value) }}
- **Asset and rule:** {{ $alert->asset }}, {{ $alert->rule }}
- **When:** {{ $alert->timing() }}

<x-mail::button :url="$alert->url">
{{ $alert->test ? __('Open the project') : __('Open the alert log') }}
</x-mail::button>

{{ __('You get this because the address is on one of the project\'s alert notification channels in :app.', ['app' => config('app.name')]) }}
</x-mail::message>
