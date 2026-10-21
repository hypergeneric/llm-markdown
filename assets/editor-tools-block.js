( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar ) ||
		( wp.editPost && wp.editPost.PluginSidebar );
	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var __ = wp.i18n.__;

	if ( ! PluginSidebar ) {
		return;
	}

	var sidebarName = 'llm-markdown-editor-tools/markdown';
	function getMarkdown() {
		return fetch( config.markdownUrl, {
			credentials: 'omit',
			headers: { Accept: 'text/markdown' }
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				if ( ! response.ok ) {
					var responseError = new Error( __( 'Markdown returned HTTP', 'llm-markdown' ) + ' ' + response.status + '.' );
					responseError.httpStatus = response.status;
					throw responseError;
				}

				if ( ! text.trim() ) {
					throw new Error( __( 'The Markdown response was empty.', 'llm-markdown' ) );
				}

				return { status: response.status, text: text };
			} );
		} );
	}

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
					throw new Error( __( 'The browser blocked clipboard access.', 'llm-markdown' ) );
				}
				resolve();
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( field );
			}
		} );
	}

	function outputMetrics( text, status ) {
		var body = text.replace( /^---\s*\n[\s\S]*?\n---\s*\n?/, '' ).trim();
		var words = body ? body.split( /\s+/ ).filter( Boolean ).length : 0;
		var bytes = window.TextEncoder ? new TextEncoder().encode( text ).length : text.length;

		return {
			status: status,
			words: words,
			tokens: Math.ceil( text.length / 4 ),
			size: bytes < 1024 ? bytes + ' B' : ( bytes / 1024 ).toFixed( 1 ) + ' KB'
		};
	}

	function SummaryRow( props ) {
		return el(
			'tr',
			null,
			el( 'th', { scope: 'row' }, props.label ),
			el( 'td', null, props.value )
		);
	}

	function MarkdownSidebar() {
		var editorState = useSelect( function ( select ) {
			var editPost = select( 'core/edit-post' );
			var editor = select( 'core/editor' );
			var activeSidebar = editPost && editPost.getActiveGeneralSidebarName ?
				editPost.getActiveGeneralSidebarName() : '';

			return {
				isOpen: activeSidebar === sidebarName,
				isDirty: editor && editor.isEditedPostDirty ? editor.isEditedPostDirty() : false,
				isSaving: editor && editor.isSavingPost ? editor.isSavingPost() : false,
				isAutosaving: editor && editor.isAutosavingPost ? editor.isAutosavingPost() : false,
				didSave: editor && editor.didPostSaveRequestSucceed ? editor.didPostSaveRequestSucceed() : false,
				meta: editor && editor.getEditedPostAttribute ? ( editor.getEditedPostAttribute( 'meta' ) || {} ) : {}
			};
		}, [] );
		var statusState = useState( {
			loading: false,
			checked: false,
			error: '',
			metrics: null,
			responseStatus: null,
			text: ''
		} );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var copyState = useState( '' );
		var copyMessage = copyState[ 0 ];
		var setCopyMessage = copyState[ 1 ];
		var previewState = useState( false );
		var showPreview = previewState[ 0 ];
		var setShowPreview = previewState[ 1 ];
		var wasDirty = useRef( editorState.isDirty );
		var wasSaving = useRef( editorState.isSaving );
		var savedExclusionState = useState( Boolean( config.excluded ) );
		var savedExcluded = savedExclusionState[ 0 ];
		var setSavedExcluded = savedExclusionState[ 1 ];
		var hasEditedExclusion = Object.prototype.hasOwnProperty.call( editorState.meta, config.metaKey );
		var isExcluded = hasEditedExclusion ? Boolean( editorState.meta[ config.metaKey ] ) : Boolean( config.excluded );
		var available = Boolean( config.eligible && config.markdownUrl && ! savedExcluded );

		function checkMarkdown() {
			if ( ! available || status.loading ) {
				return;
			}

			setStatus( { loading: true, checked: false, error: '', metrics: null, responseStatus: null, text: '' } );
			getMarkdown().then( function ( result ) {
				setStatus( {
					loading: false,
					checked: true,
					error: '',
					metrics: outputMetrics( result.text, result.status ),
					responseStatus: result.status,
					text: result.text
				} );
			} ).catch( function ( error ) {
				setStatus( {
					loading: false,
					checked: true,
					error: error.message || __( 'Markdown could not be retrieved.', 'llm-markdown' ),
					metrics: null,
					responseStatus: error.httpStatus || null,
					text: ''
				} );
			} );
		}

		function copyMarkdown() {
			setCopyMessage( __( 'Copying…', 'llm-markdown' ) );
			( status.text ? Promise.resolve( status.text ) : getMarkdown().then( function ( result ) {
				return result.text;
			} ) ).then( function ( text ) {
				return copyText( text );
			} ).then( function () {
				setCopyMessage( __( 'Markdown copied.', 'llm-markdown' ) );
			} ).catch( function ( error ) {
				setCopyMessage( error.message || __( 'Markdown could not be copied.', 'llm-markdown' ) );
			} );
		}

		function setExcluded( value ) {
			var nextMeta = Object.assign( {}, editorState.meta );
			nextMeta[ config.metaKey ] = Boolean( value );
			wp.data.dispatch( 'core/editor' ).editPost( { meta: nextMeta } );
			setCopyMessage( '' );
			setShowPreview( false );
		}

		useEffect( function () {
			var completedSave = wasSaving.current
				&& ! editorState.isSaving
				&& ! editorState.isAutosaving
				&& editorState.didSave;

			if ( completedSave ) {
				setSavedExcluded( isExcluded );
				setStatus( {
					loading: false,
					checked: false,
					error: '',
					metrics: null,
					responseStatus: null,
					text: ''
				} );
				setShowPreview( false );
			}

			wasSaving.current = editorState.isSaving;
		}, [ editorState.isSaving, editorState.isAutosaving, editorState.didSave, isExcluded ] );

		useEffect( function () {
			var savedAfterEditing = wasDirty.current && ! editorState.isDirty;

			if (
				editorState.isOpen
				&& config.eligible
				&& ! isExcluded
				&& ! savedExcluded
				&& ! status.loading
				&& ( ! status.checked || savedAfterEditing )
			) {
				checkMarkdown();
			}

			wasDirty.current = editorState.isDirty;
		}, [ editorState.isOpen, editorState.isDirty, isExcluded, savedExcluded ] );

		var statusLabel = available ? __( 'Available', 'llm-markdown' ) : __( 'Unavailable', 'llm-markdown' );
		var statusClass = available ? 'available' : 'unavailable';
		var exclusionPending = isExcluded !== savedExcluded;
		if ( exclusionPending ) {
			statusLabel = __( 'Pending save', 'llm-markdown' );
			statusClass = 'checking';
		} else if ( isExcluded ) {
			statusLabel = __( 'Disabled', 'llm-markdown' );
			statusClass = 'unavailable';
		} else if ( status.loading ) {
			statusLabel = __( 'Checking…', 'llm-markdown' );
			statusClass = 'checking';
		} else if ( status.error ) {
			statusLabel = __( 'Failed', 'llm-markdown' );
			statusClass = 'error';
		} else if ( status.checked ) {
			statusLabel = __( 'Extracted', 'llm-markdown' );
			statusClass = 'extracted';
		}

		var statusPill = el(
			'span',
			{ className: 'llmmd-editor-panel__pill llmmd-editor-panel__pill--' + statusClass },
			statusLabel
		);
		var metrics = status.metrics;
		var emptyValue = '—';

		return el(
			PluginSidebar,
			{
				name: 'markdown',
				title: __( 'Markdown', 'llm-markdown' ),
				icon: 'editor-code'
			},
			el(
				'div',
				{ className: 'llmmd-editor-panel__content' },
				el(
					'table',
					{ className: 'llmmd-editor-panel__summary' },
					el(
						'tbody',
						null,
						el( SummaryRow, { label: __( 'Status', 'llm-markdown' ), value: statusPill } ),
						el( SummaryRow, { label: __( 'Last response', 'llm-markdown' ), value: status.responseStatus || emptyValue } ),
						el( SummaryRow, { label: __( 'Word count', 'llm-markdown' ), value: metrics ? metrics.words.toLocaleString() : emptyValue } ),
						el( SummaryRow, { label: __( 'Est. tokens', 'llm-markdown' ), value: metrics ? metrics.tokens.toLocaleString() : emptyValue } ),
						el( SummaryRow, { label: __( 'Document size', 'llm-markdown' ), value: metrics ? metrics.size : emptyValue } ),
						el( SummaryRow, { label: __( 'Canonical URL', 'llm-markdown' ), value: config.canonicalUrl ?
							el( 'a', { href: config.canonicalUrl, target: '_blank', rel: 'noopener noreferrer' }, config.canonicalUrl ) : emptyValue } ),
						el( SummaryRow, { label: __( 'Markdown URL', 'llm-markdown' ), value: config.markdownUrl ?
							el( 'a', { href: config.markdownUrl, target: '_blank', rel: 'noopener noreferrer' }, config.markdownUrl ) : emptyValue } )
					)
				),
				! config.eligible && el( 'p', { className: 'llmmd-editor-panel__message' }, config.message ),
				status.error && el( 'p', { className: 'llmmd-editor-panel__message llmmd-editor-panel__message--error' }, status.error ),
				editorState.isDirty && el(
					'p',
					{ className: 'llmmd-editor-panel__message' },
					__( 'Stats reflect the last save. Save changes to update them.', 'llm-markdown' )
				),
				el(
					'div',
					{ className: 'llmmd-actions' },
					el( Button, {
						variant: 'primary',
						onClick: copyMarkdown,
						disabled: ! available
					}, __( 'Copy Markdown', 'llm-markdown' ) ),
					el( Button, {
						variant: 'secondary',
						onClick: function () { setShowPreview( ! showPreview ); },
						disabled: ! available
					}, showPreview ? __( 'Hide Markdown', 'llm-markdown' ) : __( 'View Markdown', 'llm-markdown' ) ),
					available ? el( Button, {
						variant: 'secondary',
						href: config.markdownUrl,
						target: '_blank',
						rel: 'noopener noreferrer'
					}, __( 'Open Markdown URL', 'llm-markdown' ) ) :
						el( Button, { variant: 'secondary', disabled: true }, __( 'Open Markdown URL', 'llm-markdown' ) )
				),
				showPreview && el( 'div', { className: 'llmmd-editor-panel__preview' },
					el( 'p', null, __( 'Last saved public Markdown', 'llm-markdown' ) ),
					status.loading ? el( 'p', null, __( 'Loading Markdown…', 'llm-markdown' ) ) :
						status.text ? el( 'pre', { tabIndex: 0 }, status.text ) :
							el( 'p', null, status.error || __( 'Markdown is not available yet.', 'llm-markdown' ) )
				),
				copyMessage && el( 'p', { className: 'llmmd-editor-panel__feedback', 'aria-live': 'polite' }, copyMessage ),
				el( 'p', { className: 'llmmd-editor-panel__footnote' }, __( 'Token count is approximate and varies by model.', 'llm-markdown' ) ),
				el(
					'div',
					{ className: 'llmmd-editor-panel__exclusion' },
					el( CheckboxControl, {
						label: __( 'Disable Markdown for this content', 'llm-markdown' ),
						help: __( 'The normal HTML page remains available.', 'llm-markdown' ),
						checked: isExcluded,
						onChange: setExcluded
					} )
				)
			)
		);
	}

	registerPlugin( 'llm-markdown-editor-tools', {
		render: MarkdownSidebar,
		icon: 'editor-code'
	} );
}( window.wp, window.llmMarkdownEditorTools ) );
