@php
    $venueInputId = $inputId ?? 'event-venue';
    $venueValue = $value ?? '';
    $venueLatitude = $latitude ?? '';
    $venueLongitude = $longitude ?? '';
    $venueSource = $source ?? '';
    $venueHasPin = is_numeric($venueLatitude) && is_numeric($venueLongitude);
    $venueSearchUrl = route('venues.search');
    $venueMapTileUrl = config('services.map_tiles.url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
@endphp

<div class="{{ trim(($wrapperClass ?? '').' venue-picker') }}"
     data-venue-picker
     data-search-url="{{ $venueSearchUrl }}"
     data-map-tile-url="{{ $venueMapTileUrl }}"
     data-initial-label="{{ $venueValue }}"
     data-initial-latitude="{{ $venueLatitude }}"
     data-initial-longitude="{{ $venueLongitude }}">
    <label for="{{ $venueInputId }}">Venue @if($required ?? false)<b>*</b>@endif</label>
    <div class="venue-picker-search-row">
        <input id="{{ $venueInputId }}"
               name="venue"
               value="{{ $venueValue }}"
               placeholder="Venue name, area, city"
               maxlength="255"
               autocomplete="off"
               aria-describedby="{{ $venueInputId }}-help {{ $venueInputId }}-status {{ $venueInputId }}-selection"
               aria-controls="{{ $venueInputId }}-results"
               aria-expanded="false"
               @required($required ?? false)>
        <button class="venue-picker-search-button" type="button" data-venue-search aria-controls="{{ $venueInputId }}-results">
            <span aria-hidden="true">⌖</span> <span data-venue-search-label>Search map</span>
        </button>
    </div>
    <p class="venue-picker-help" id="{{ $venueInputId }}-help">Search runs only when you choose <strong>Search map</strong> or press Enter, and sends that phrase to OpenStreetMap. Manual typing is not sent to OpenStreetMap; if no suggestion matches, keep your venue exactly as typed.</p>

    <input type="hidden" name="venue_latitude" value="{{ $venueLatitude }}" data-venue-latitude>
    <input type="hidden" name="venue_longitude" value="{{ $venueLongitude }}" data-venue-longitude>
    <input type="hidden" name="venue_source" value="{{ $venueSource }}" data-venue-source>

    <p class="venue-picker-status" id="{{ $venueInputId }}-status" data-venue-status role="status" aria-live="polite"></p>

    <section class="venue-picker-results" data-venue-results-panel hidden aria-label="Map search suggestions">
        <header>
            <div><strong>Suggested places</strong><small>Choose one to attach its map location.</small></div>
            <button type="button" data-venue-keep>Keep my typed venue</button>
        </header>
        <ol id="{{ $venueInputId }}-results" data-venue-results aria-label="Venue suggestions"></ol>
        <p class="venue-picker-attribution">Search results <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap contributors</a></p>
    </section>

    <div class="venue-picker-selection" id="{{ $venueInputId }}-selection" data-venue-selection @if(!$venueHasPin) hidden @endif>
        <span aria-hidden="true">✓</span>
        <div><strong>Map location attached</strong><small data-venue-selection-copy>{{ $venueHasPin ? $venueValue : '' }}</small></div>
        <button type="button" data-venue-map-toggle aria-expanded="false" aria-controls="{{ $venueInputId }}-map">View or adjust pin</button>
        <button type="button" data-venue-clear>Remove pin</button>
    </div>

    <section class="venue-picker-map" id="{{ $venueInputId }}-map" data-venue-map-panel hidden aria-label="Venue map picker">
        <header><div><strong>Choose the exact point</strong><small>Tap or click to move the pin. With a keyboard, pan using the arrow keys and press Enter to place it. Your venue name stays unchanged.</small></div><button type="button" data-venue-map-close aria-label="Close venue map">×</button></header>
        <div class="venue-picker-map-canvas" data-venue-map tabindex="0" aria-label="Interactive OpenStreetMap venue picker. Pan with arrow keys and press Enter to place the pin at the map centre."></div>
        <p><span data-venue-coordinate-copy>Choose a point on the map.</span> <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">Map data © OpenStreetMap contributors</a></p>
    </section>
</div>
