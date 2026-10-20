<label for="{{ $id }}">{{ $label }}</label>
<div class="password-field">
    <input id="{{ $id }}" type="password" name="{{ $name }}" required autocomplete="{{ $autocomplete }}" @if(isset($minlength)) minlength="{{ $minlength }}" @endif>
    <button class="password-toggle" type="button" aria-label="Show password" aria-controls="{{ $id }}" aria-pressed="false" title="Show password" onclick="togglePasswordVisibility(this)">
        <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.75"/></svg>
        <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 6.1c.5-.1.9-.1 1.4-.1 6 0 9.5 6 9.5 6a16 16 0 0 1-2.2 2.8M6.2 6.2C3.8 7.9 2.5 12 2.5 12s3.5 6 9.5 6c1.6 0 3-.4 4.2-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
    </button>
</div>
