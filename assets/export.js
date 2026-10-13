/**
 * Export page: keep the number of matching messages on the download button
 * up to date while the filters change.
 *
 * The server counts the messages (meff_ajax_count() in the main plugin
 * file) and also returns the button label and note, so all texts stay
 * translated in PHP.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'meff-export-form' );

	if ( ! form ) {
		return;
	}

	var button = document.getElementById( 'meff-download' );
	var note = document.getElementById( 'meff-note' );
	var spinner = form.querySelector( '.meff-spinner' );
	var custom = form.querySelector( 'input[name="meff_period"][value="custom"]' );
	var timer = null;
	var latest = 0;

	function update() {
		var request = ++latest;
		var data = new FormData( form );

		data.set( 'action', 'meff_count' );
		spinner.classList.add( 'is-active' );

		fetch( form.dataset.countUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( 'Count failed' );
				}

				// An older answer that arrives late must not overwrite a newer one.
				if ( request === latest ) {
					button.textContent = result.data.label;
					button.disabled = 0 === result.data.count;
					note.textContent = result.data.note;
				}
			} )
			.catch( function () {
				if ( request === latest ) {
					button.disabled = false;
					note.textContent = form.dataset.error;
				}
			} )
			.then( function () {
				if ( request === latest ) {
					spinner.classList.remove( 'is-active' );
				}
			} );
	}

	// Typing waits for a short pause, a click counts at once.
	function schedule( delay ) {
		clearTimeout( timer );
		timer = setTimeout( update, delay );
	}

	function isDate( element ) {
		return 'meff_from' === element.name || 'meff_to' === element.name;
	}

	function isText( element ) {
		return 'text' === element.type;
	}

	// Choosing a day only makes sense with a custom range, so select it.
	function selectCustom() {
		if ( ! custom.checked ) {
			custom.checked = true;
			schedule( 0 );
		}
	}

	form.addEventListener( 'focusin', function ( event ) {
		if ( isDate( event.target ) ) {
			selectCustom();
		}
	} );

	form.addEventListener( 'input', function ( event ) {
		if ( isText( event.target ) || isDate( event.target ) ) {
			schedule( 400 );
		}
	} );

	form.addEventListener( 'change', function ( event ) {
		if ( isDate( event.target ) ) {
			selectCustom();
		}

		schedule( isText( event.target ) ? 400 : 0 );
	} );

	// Enter in a text field applies the filter, it does not start a download.
	form.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && isText( event.target ) ) {
			event.preventDefault();
			schedule( 0 );
		}
	} );
}() );
