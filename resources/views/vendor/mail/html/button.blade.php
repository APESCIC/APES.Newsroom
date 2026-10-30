@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    $fill = match ($color) {
        'success', 'green' => '#11613B',
        'error', 'red' => '#A12828',
        default => '#006E70',
    };
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="button-cell" align="center" bgcolor="{{ $fill }}" style="background-color: {{ $fill }}; border-radius: 9999px;">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
