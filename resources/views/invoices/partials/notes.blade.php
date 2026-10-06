@if($wording['introText'])<div class="note-box">@if($wording['introHeading'])<p class="note-title">{{ $wording['introHeading'] }}</p>@endif<p>{{ $wording['introText'] }}</p></div>@endif
@if($wording['customerNote'])<div class="note-box">@if($wording['customerNoteHeading'])<p class="note-title">{{ $wording['customerNoteHeading'] }}</p>@endif<p>{{ $wording['customerNote'] }}</p></div>@endif
@if($wording['terms'])<div class="terms">@if($wording['termsHeading'])<strong>{{ $wording['termsHeading'] }}</strong><br>@endif{!! nl2br(e($wording['terms'])) !!}</div>@endif
