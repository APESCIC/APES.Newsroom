<x-mail::message>
@if ($isTest)
**TEST SEND** — this message was not sent to the live mailing list.
@endif

# {{ $snapshot['title'] }}

**{{ $snapshot['channel_label'] }}** · {{ $snapshot['author'] }}@if (!empty($snapshot['published_at'])) · {{ \Illuminate\Support\Carbon::parse($snapshot['published_at'])->timezone('Europe/London')->format('j M Y') }}@endif

@if (!empty($snapshot['html']))
{!! $snapshot['html'] !!}
@elseif (!empty($snapshot['excerpt']))
{{ $snapshot['excerpt'] }}
@endif

<x-mail::button :url="$trackedReadMoreUrl">
Read the full story
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}

@if (!empty($openPixelUrl))
<img src="{{ $openPixelUrl }}" width="1" height="1" alt="" />
@endif

<x-mail::subcopy>
APES CIC · 40 Morris Street, St Helens, Merseyside, WA9 3EN · 01744 374 015<br>
Manage your [preferences]({{ $preferencesUrl }}) or [unsubscribe]({{ $unsubscribeUrl }}).
</x-mail::subcopy>
</x-mail::message>
