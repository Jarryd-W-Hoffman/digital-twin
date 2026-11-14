<x-mail::message>
# {{ $report->subject() }}

@php($totals = $report->totals())
- **{{ __('Alerts') }}:** {{ __(':raised raised, :critical critical; :acknowledged acknowledged, :unacknowledged not; :open open now', ['raised' => $totals['alerts'], 'critical' => $totals['critical'], 'acknowledged' => $totals['acknowledged'], 'unacknowledged' => $totals['unacknowledged'], 'open' => $totals['openAlerts']]) }}
- **{{ __('Issues') }}:** {{ __(':raised raised, :closed closed; :open open, :overdue overdue', ['raised' => $totals['raisedIssues'], 'closed' => $totals['closedIssues'], 'open' => $totals['openIssues'], 'overdue' => $totals['overdue']]) }}

@foreach ($report->twins as $twin)
## [{{ $twin['name'] }}]({{ $twin['url'] }})

<x-mail::table>
| {{ __('Alerts') }} | | {{ __('Issues') }} | |
| :-- | --: | :-- | --: |
| {{ __('Raised') }} | {{ $twin['alerts']['raised'] }} | {{ __('Raised') }} | {{ $twin['issues']['raised'] }} |
| {{ __('Critical') }} | {{ $twin['alerts']['critical'] }} | {{ __('Closed') }} | {{ $twin['issues']['closed'] }} |
| {{ __('Acknowledged') }} | {{ $twin['alerts']['acknowledged'] }} | {{ __('Open') }} | {{ $twin['issues']['open'] }} |
| {{ __('Median to acknowledge') }} | {{ \App\Support\ReportMessage::minutes($twin['alerts']['medianAckMinutes']) }} | {{ __('Overdue') }} | {{ $twin['issues']['overdue'] }} |
| {{ __('Not acknowledged') }} | {{ $twin['alerts']['unacknowledged'] }} | | |
| {{ __('Open now') }} | {{ $twin['alerts']['open'] }} | | |
</x-mail::table>

@if ($twin['busiest'] !== [])
{{ __('Most alerts') }}: {{ collect($twin['busiest'])->map(fn ($busiest) => $busiest['asset'].' ('.$busiest['alerts'].')')->implode(', ') }}
@endif

@foreach ($twin['overdue'] as $issue)
- **{{ __('Overdue') }}:** [#{{ $issue['number'] }} {{ $issue['title'] }}]({{ $issue['url'] }}), {{ __('due :date', ['date' => $issue['dueOn']]) }}{{ $issue['assignee'] ? ', '.$issue['assignee'] : '' }}
@endforeach
@endforeach

<x-mail::button :url="$report->url">
{{ $report->linkLabel() }}
</x-mail::button>

{{ __('You get this because the address is on one of the project\'s notification channels in :app.', ['app' => config('app.name')]) }}
</x-mail::message>
