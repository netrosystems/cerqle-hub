<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p style="margin-top: 8px; font-size: 12px; color: #8a8490;">
    This is an automated service email from {{ config('app.name') }}.<br>
    @if(config('app.url'))
    <a href="{{ config('app.url') }}" style="color: #6d6471; text-decoration: underline;">{{ preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')) }}</a>
    &nbsp;&middot;&nbsp;
    <a href="{{ rtrim(config('app.url'), '/') }}/privacy" style="color: #6d6471; text-decoration: underline;">Privacy</a>
    @endif
</p>
</td>
</tr>
</table>
</td>
</tr>
