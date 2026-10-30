@props(['url'])
<tr>
<td class="header" align="center">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/apes-logo-masthead.png') }}" class="logo" width="120" height="87" alt="{{ trim($slot) !== '' ? trim($slot) : config('app.name') }}">
</a>
</td>
</tr>
