(function () {
    'use strict';

    var leafletPromise;

    function loadLeaflet() {
        if (window.L) return Promise.resolve(window.L);
        if (leafletPromise) return leafletPromise;

        leafletPromise = new Promise(function (resolve, reject) {
            var stylesheet = document.getElementById('leaflet-stylesheet');
            if (!stylesheet) {
                stylesheet = document.createElement('link');
                stylesheet.id = 'leaflet-stylesheet';
                stylesheet.rel = 'stylesheet';
                stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                stylesheet.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
                stylesheet.crossOrigin = '';
                document.head.appendChild(stylesheet);
            }

            var script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
            script.crossOrigin = '';
            script.onload = function () { resolve(window.L); };
            script.onerror = function () { leafletPromise = null; reject(new Error('Map library unavailable')); };
            document.head.appendChild(script);
        });

        return leafletPromise;
    }

    function validCoordinate(value, minimum, maximum) {
        var parsed = Number(value);
        return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum;
    }

    function initialiseVenuePicker(root) {
        var input = root.querySelector('[name="venue"]');
        var searchButton = root.querySelector('[data-venue-search]');
        var searchLabel = root.querySelector('[data-venue-search-label]');
        var status = root.querySelector('[data-venue-status]');
        var resultsPanel = root.querySelector('[data-venue-results-panel]');
        var resultsList = root.querySelector('[data-venue-results]');
        var keepButton = root.querySelector('[data-venue-keep]');
        var latitude = root.querySelector('[data-venue-latitude]');
        var longitude = root.querySelector('[data-venue-longitude]');
        var source = root.querySelector('[data-venue-source]');
        var selection = root.querySelector('[data-venue-selection]');
        var selectionCopy = root.querySelector('[data-venue-selection-copy]');
        var clearButton = root.querySelector('[data-venue-clear]');
        var mapToggle = root.querySelector('[data-venue-map-toggle]');
        var mapClose = root.querySelector('[data-venue-map-close]');
        var mapPanel = root.querySelector('[data-venue-map-panel]');
        var mapCanvas = root.querySelector('[data-venue-map]');
        var coordinateCopy = root.querySelector('[data-venue-coordinate-copy]');
        var selectedLabel = root.dataset.initialLabel || '';
        var requestController = null;
        var map = null;
        var marker = null;

        if (!input || !searchButton) return;

        function hasCoordinates() {
            return validCoordinate(latitude.value, -90, 90) && validCoordinate(longitude.value, -180, 180);
        }

        function setStatus(message, isError) {
            status.textContent = message || '';
            status.classList.toggle('is-error', Boolean(isError));
        }

        function closeResults() {
            resultsPanel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }

        function updateCoordinateCopy() {
            coordinateCopy.textContent = hasCoordinates()
                ? 'Pin: ' + Number(latitude.value).toFixed(5) + ', ' + Number(longitude.value).toFixed(5)
                : 'Choose a point on the map.';
        }

        function updateMarker(shouldReframe) {
            if (!map || !hasCoordinates()) return;
            var point = [Number(latitude.value), Number(longitude.value)];
            if (!marker) marker = window.L.marker(point).addTo(map);
            else marker.setLatLng(point);
            if (shouldReframe) map.setView(point, 16);
            updateCoordinateCopy();
        }

        function setCoordinates(lat, lon, provider, label, shouldReframe) {
            latitude.value = String(lat);
            longitude.value = String(lon);
            source.value = provider || 'nominatim';
            if (label) {
                input.value = label;
                selectedLabel = label;
            } else {
                selectedLabel = input.value;
            }
            selectionCopy.textContent = input.value || ('Pin at ' + Number(lat).toFixed(5) + ', ' + Number(lon).toFixed(5));
            selection.hidden = false;
            updateCoordinateCopy();
            updateMarker(shouldReframe !== false);
        }

        function clearCoordinates(announce) {
            latitude.value = '';
            longitude.value = '';
            source.value = '';
            selectedLabel = '';
            selection.hidden = true;
            if (marker && map) {
                map.removeLayer(marker);
                marker = null;
            }
            updateCoordinateCopy();
            if (announce) setStatus('Map pin removed. Your typed venue will still be saved.', false);
        }

        function normaliseSuggestions(payload) {
            var records = Array.isArray(payload) ? payload : (payload.results || payload.suggestions || payload.data || []);
            if (!Array.isArray(records)) return [];
            return records.map(function (record) {
                var lat = record.lat !== undefined ? record.lat : record.latitude;
                var lon = record.lon !== undefined ? record.lon : record.longitude;
                return {
                    label: String(record.label || record.display_name || record.name || '').trim(),
                    lat: Number(lat),
                    lon: Number(lon),
                    source: String(record.source || 'nominatim'),
                    context: String(record.context || record.type || '').trim()
                };
            }).filter(function (record) {
                return record.label && validCoordinate(record.lat, -90, 90) && validCoordinate(record.lon, -180, 180);
            }).slice(0, 8);
        }

        function renderSuggestions(suggestions) {
            resultsList.replaceChildren();
            suggestions.forEach(function (suggestion) {
                var item = document.createElement('li');
                var button = document.createElement('button');
                var icon = document.createElement('span');
                var copy = document.createElement('span');
                var title = document.createElement('strong');
                var context = document.createElement('small');
                button.type = 'button';
                button.className = 'venue-picker-result';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '⌖';
                title.textContent = suggestion.label;
                context.textContent = suggestion.context || 'Select this map result';
                copy.appendChild(title);
                copy.appendChild(context);
                button.appendChild(icon);
                button.appendChild(copy);
                button.addEventListener('click', function () {
                    setCoordinates(suggestion.lat, suggestion.lon, suggestion.source, suggestion.label, true);
                    closeResults();
                    setStatus('Map location selected. You can adjust the pin or continue with the form.', false);
                    input.focus();
                });
                item.appendChild(button);
                resultsList.appendChild(item);
            });
            resultsPanel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        async function search() {
            var query = input.value.trim();
            if (query.length < 3) {
                closeResults();
                setStatus('Enter at least three characters to search. Your typed venue can still be saved.', true);
                input.focus();
                return;
            }

            if (requestController) requestController.abort();
            requestController = new AbortController();
            searchButton.disabled = true;
            root.setAttribute('aria-busy', 'true');
            searchLabel.textContent = 'Searching…';
            setStatus('Searching the map for “' + query + '”…', false);

            try {
                var url = new URL(root.dataset.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                var response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: requestController.signal,
                    credentials: 'same-origin'
                });
                if (!response.ok) throw new Error('Venue search failed');
                var suggestions = normaliseSuggestions(await response.json());
                if (!suggestions.length) {
                    closeResults();
                    setStatus('No map match found. Your typed venue will still be saved exactly as written.', false);
                    return;
                }
                renderSuggestions(suggestions);
                setStatus(suggestions.length + (suggestions.length === 1 ? ' suggestion found.' : ' suggestions found.'), false);
            } catch (error) {
                if (error.name === 'AbortError') return;
                closeResults();
                setStatus('Map search is unavailable right now. Your typed venue will still be saved exactly as written.', true);
            } finally {
                searchButton.disabled = false;
                root.removeAttribute('aria-busy');
                searchLabel.textContent = 'Search map';
            }
        }

        async function openMap() {
            mapPanel.hidden = false;
            mapToggle.setAttribute('aria-expanded', 'true');
            mapToggle.textContent = 'Map is open';
            setStatus('Loading the interactive map…', false);
            try {
                await loadLeaflet();
                if (!map) {
                    var startingPoint = hasCoordinates() ? [Number(latitude.value), Number(longitude.value)] : [-6.3690, 34.8888];
                    map = window.L.map(mapCanvas, { scrollWheelZoom: false }).setView(startingPoint, hasCoordinates() ? 16 : 6);
                    window.L.tileLayer(root.dataset.mapTileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
                    }).addTo(map);
                    map.on('click', function (event) {
                        setCoordinates(event.latlng.lat.toFixed(7), event.latlng.lng.toFixed(7), source.value || 'nominatim', null, false);
                        updateMarker(false);
                        setStatus('Pin updated. Your typed venue name remains unchanged.', false);
                    });
                    mapCanvas.addEventListener('keydown', function (event) {
                        if (event.key !== 'Enter' && event.key !== ' ') return;
                        event.preventDefault();
                        var centre = map.getCenter();
                        setCoordinates(centre.lat.toFixed(7), centre.lng.toFixed(7), source.value || 'nominatim', null, false);
                        updateMarker(false);
                        setStatus('Pin placed at the map centre. Your typed venue name remains unchanged.', false);
                    });
                }
                window.setTimeout(function () {
                    map.invalidateSize();
                    updateMarker(false);
                }, 0);
                updateCoordinateCopy();
                setStatus(hasCoordinates() ? 'Map ready. Tap, click, or press Enter at the map centre to adjust the pin.' : 'Map ready. Tap, click, or press Enter at the map centre to place a pin.', false);
            } catch (error) {
                mapPanel.hidden = true;
                mapToggle.setAttribute('aria-expanded', 'false');
                mapToggle.textContent = 'View or adjust pin';
                setStatus('The interactive map could not load. Your venue text is safe and can still be saved.', true);
            }
        }

        function closeMap() {
            mapPanel.hidden = true;
            mapToggle.setAttribute('aria-expanded', 'false');
            mapToggle.textContent = 'View or adjust pin';
            mapToggle.focus();
        }

        searchButton.addEventListener('click', search);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                search();
            } else if (event.key === 'Escape') {
                closeResults();
            }
        });
        input.addEventListener('input', function () {
            closeResults();
            setStatus('', false);
            if (hasCoordinates() && input.value !== selectedLabel) clearCoordinates(false);
        });
        keepButton.addEventListener('click', function () {
            closeResults();
            setStatus('Keeping your venue exactly as typed. No map result is required.', false);
            input.focus();
        });
        clearButton.addEventListener('click', function () { clearCoordinates(true); input.focus(); });
        mapToggle.addEventListener('click', function () { if (mapPanel.hidden) openMap(); else closeMap(); });
        mapClose.addEventListener('click', closeMap);

        if (hasCoordinates()) {
            selection.hidden = false;
            selectionCopy.textContent = input.value;
            updateCoordinateCopy();
        }
    }

    function bootVenuePickers() {
        document.querySelectorAll('[data-venue-picker]').forEach(initialiseVenuePicker);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootVenuePickers);
    else bootVenuePickers();
}());
