<x-mail::message>
@if ($isTest)
<p class="test-banner">TEST SEND: this message was not sent to the live mailing list.</p>
@endif

<p><span class="badge">{{ $snapshot['channel_label'] }}</span></p>

@if ($fragments['intro_html'] !== '')
{!! $fragments['intro_html'] !!}

@endif
# {{ $snapshot['title'] }}

<p class="meta">{{ $snapshot['author'] }}@if (!empty($snapshot['published_at'])) · {{ \Illuminate\Support\Carbon::parse($snapshot['published_at'])->timezone('Europe/London')->format('j M Y') }}@endif</p>

@if ($fragments['show_hero'] && !empty($snapshot['hero_image']))
<a href="{{ $trackedReadMoreUrl }}"><img src="{{ url($snapshot['hero_image']) }}" class="hero-image" width="506" alt="{{ $snapshot['hero_image_alt'] ?? '' }}"></a>
@endif

@if ($fragments['show_full_post'] && !empty($snapshot['html']))
@if ($fragments['show_excerpt'] && !empty($snapshot['excerpt']))
<p class="standfirst">{{ $snapshot['excerpt'] }}</p>

@endif
{!! $snapshot['html'] !!}
@elseif (!empty($snapshot['excerpt']))
{{ $snapshot['excerpt'] }}
@endif

<x-mail::button :url="$trackedReadMoreUrl">
{{ $fragments['read_more_label'] }}
</x-mail::button>

@if ($fragments['sign_off_html'] !== '')
{!! $fragments['sign_off_html'] !!}
@endif

@if (!empty($openPixelUrl))
<img src="{{ $openPixelUrl }}" width="1" height="1" alt="" />
@endif

<x-mail::subcopy>
You are receiving this because you subscribed to an APES Newsroom newsletter.<br>
Manage your [preferences]({{ $preferencesUrl }}) or [unsubscribe]({{ $unsubscribeUrl }}).
</x-mail::subcopy>
</x-mail::message>
