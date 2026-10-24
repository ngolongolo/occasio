@php
    $trustVerified = (bool) ($organiserIsVerified ?? false);
    $trustLabel = $trustVerified ? ($organiserVerificationLabel ?? 'Identity verified') : 'Standard organiser';
    $trustDescription = $trustVerified
        ? ($organiserVerificationDescription ?? 'Okesheni verified this organiser’s identity or organisation details. Verification does not guarantee the event, its quality or a refund.')
        : 'This organiser does not currently hold an Okesheni verification badge. Review the event and payment information before confirming.';
@endphp
<div class="organiser-trust {{ !empty($onDark) ? 'is-on-dark' : '' }}">
    <span class="organiser-trust-name">Hosted by {{ $organiserDisplayName ?? $event->host }}</span>
    <span class="organiser-trust-badge {{ $trustVerified ? 'is-verified' : 'is-standard' }}" tabindex="0" role="note" aria-label="{{ $trustLabel }}. {{ $trustDescription }}" data-tooltip="{{ $trustDescription }}">
        <i aria-hidden="true">{{ $trustVerified ? '✓' : 'i' }}</i>
        {{ $trustLabel }}
        <b aria-hidden="true">?</b>
    </span>
</div>
