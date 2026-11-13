<x-mail::message>
# {{ $issue->subject() }}

- **Twin:** {{ $issue->twin }} ({{ $issue->project }})
@if ($issue->by)
- **By:** {{ $issue->by }}
@endif
@if ($issue->assignee)
- **Assigned to:** {{ $issue->assignee }}
@endif
@if ($issue->dueOn)
- **Due:** {{ $issue->dueOn->format('j F Y') }}
@endif

<x-mail::button :url="$issue->url">
{{ $issue->linkLabel() }}
</x-mail::button>

@if ($toAssignee)
{{ __('You get this because :name gave you the issue in :app.', ['name' => $issue->by ?? __('someone'), 'app' => config('app.name')]) }}
@else
{{ __('You get this because the address is on one of the project\'s notification channels in :app.', ['app' => config('app.name')]) }}
@endif
</x-mail::message>
