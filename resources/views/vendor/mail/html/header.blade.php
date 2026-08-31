@props(['url'])
<tr>
<td class="header" width="570">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel' || trim($slot) === config('app.name'))
<span style="font-size: 22px; font-weight: 700; letter-spacing: 3px; color: #43274d;">CERQLE</span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
