( function ( config ) {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );
			field.value = text;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();

			try {
				if ( ! document.execCommand( 'copy' ) ) {
					throw new Error( 'Copy command failed.' );
				}
				resolve();
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( field );
			}
		} );
	}

	function getMarkdown( tools ) {
		var url = tools.getAttribute( 'data-markdown-url' );
		return fetch( url, {
			credentials: 'omit',
			headers: { Accept: 'text/markdown' }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( config.httpError + ' ' + response.status + '.' );
			}
			return response.text();
		} ).then( function ( text ) {
			if ( ! text.trim() ) {
				throw new Error( 'The Markdown response was empty.' );
			}
			return text;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var viewButton = event.target.closest( '.llmmd-view-markdown' );
		if ( viewButton && ! viewButton.disabled ) {
			var viewTools = viewButton.closest( '.llmmd-classic-tools' );
			var preview = viewTools.querySelector( '.llmmd-classic-preview' );
			var previewText = preview.querySelector( 'pre' );
			if ( ! preview.hidden ) {
				preview.hidden = true;
				viewButton.setAttribute( 'aria-expanded', 'false' );
				viewButton.textContent = config.view;
				return;
			}
			preview.hidden = false;
			viewButton.setAttribute( 'aria-expanded', 'true' );
			viewButton.textContent = config.hide;
			previewText.textContent = config.loading;
			getMarkdown( viewTools ).then( function ( text ) {
				previewText.textContent = text;
			} ).catch( function ( error ) {
				previewText.textContent = error.message || config.copyFailed;
			} );
			return;
		}

		var button = event.target.closest( '.llmmd-copy-markdown' );
		if ( ! button || button.disabled ) {
			return;
		}

		var tools = button.closest( '.llmmd-classic-tools' );
		var feedback = tools.querySelector( '.llmmd-copy-feedback' );
		var originalLabel = button.textContent;

		button.disabled = true;
		button.textContent = config.copying;
		feedback.textContent = '';

		getMarkdown( tools )
			.then( copyText )
			.then( function () {
				feedback.textContent = config.copied;
			} )
			.catch( function ( error ) {
				feedback.textContent = error.message || config.copyFailed;
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = originalLabel;
			} );
	} );
}( window.llmMarkdownClassicEditorTools ) );
