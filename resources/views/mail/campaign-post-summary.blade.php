<x-mail::message>
@if ($isTest)
<p class="test-banner">TEST SEND: this message was not sent to the live mailing list.</p>
@endif

<p><span class="badge">{{ $snapshot['channel_label'] }}</span></p>

# {{ $snapshot['title'] }}

<p class="meta">{{ $snapshot['author'] }}@if (!empty($snapshot['published_at'])) · {{ \Illuminate\Support\Carbon::parse($snapshot['published_at'])->timezone('Europe/London')->format('j M Y') }}@endif</p>

@if (!empty($snapshot['hero_image']))
<a href="{{ $trackedReadMoreUrl }}"><img src="{{ url($snapshot['hero_image']) }}" class="hero-image" width="506" alt="{{ $snapshot['hero_image_alt'] ?? '' }}"></a>
@endif

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
You are receiving this because you subscribed to an APES Newsroom newsletter.<br>
Manage your [preferences]({{ $preferencesUrl }}) or [unsubscribe]({{ $unsubscribeUrl }}).
</x-mail::subcopy>
</x-mail::message>
