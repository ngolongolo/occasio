<form class="language-switcher" method="post" action="{{ route('language.update') }}" data-no-loader aria-label="{{ __('ui.language.label') }}">
    @csrf
    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
    <button type="submit" name="locale" value="en" @class(['is-active' => app()->isLocale('en')]) aria-pressed="{{ app()->isLocale('en') ? 'true' : 'false' }}">EN</button>
    <button type="submit" name="locale" value="sw" @class(['is-active' => app()->isLocale('sw')]) aria-pressed="{{ app()->isLocale('sw') ? 'true' : 'false' }}">SW</button>
</form>
