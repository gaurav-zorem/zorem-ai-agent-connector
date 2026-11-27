/**
 * Website Brain admin screen.
 *
 * Every change goes through the zorem-agent-brain/v1 REST API with the
 * wp_rest nonce. Server strings are rendered with textContent only.
 */
/* global jQuery, zoremAgentBrain, ZUI */
( function ( $ ) {
	'use strict';

	const config = window.zoremAgentBrain || { root: '', nonce: '', i18n: {} };
	const i18n = config.i18n || {};

	/* ---- Requests. ---- */

	/**
	 * Call the Brain's REST API.
	 *
	 * @param {string}          route  Route relative to the namespace.
	 * @param {string}          method HTTP method.
	 * @param {Object|FormData} body   Payload, or null.
	 * @return {Promise<Object>} Parsed response; rejects with a message.
	 */
	function request( route, method, body ) {
		const options = {
			method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		};

		if ( body instanceof FormData ) {
			options.body = body;
		} else if ( body && 'GET' !== method ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		return window.fetch( config.root + route, options ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || i18n.failed || 'That did not work.' );
					}

					return data;
				} );
		} );
	}

	/**
	 * Show a message in a ZUI snackbar.
	 *
	 * @param {string} message Message.
	 * @param {string} type    success, error or warning.
	 */
	function say( message, type ) {
		if ( window.ZUI && typeof ZUI.snackbar === 'function' ) {
			ZUI.snackbar( message, { type } );
		}
	}

	/**
	 * Report rejected imports, if any.
	 *
	 * @param {Object} data Response.
	 * @return {boolean} Whether anything was rejected.
	 */
	function reportRejected( data ) {
		const rejected = data && data.rejected ? data.rejected : null;

		if ( ! rejected || 'object' !== typeof rejected || 0 === Object.keys( rejected ).length ) {
			return false;
		}

		const lines = Object.keys( rejected ).map( function ( name ) {
			return name + ': ' + rejected[ name ];
		} );

		say( ( i18n.rejected || 'Not imported:' ) + ' ' + lines.join( '; ' ), 'warning' );

		return true;
	}

	/**
	 * Run a request from a control, with busy state and the follow-up.
	 *
	 * @param {jQuery}          $control Button or form.
	 * @param {string}          route    Route.
	 * @param {string}          method   Method.
	 * @param {Object|FormData} body     Payload.
	 * @param {Object}          after    { reload: bool, output: jQuery|null }.
	 */
	function run( $control, route, method, body, after ) {
		const $buttons = $control.is( 'form' ) ? $control.find( 'button' ) : $control;

		$buttons.prop( 'disabled', true );

		request( route, method, body )
			.then( function ( data ) {
				const warned = reportRejected( data );

				if ( after.output && after.output.length ) {
					after.output.prop( 'hidden', false ).get( 0 ).textContent = JSON.stringify( data, null, 2 );
				}

				if ( ! warned ) {
					say( i18n.saved || 'Saved.', 'success' );
				}

				if ( after.reload ) {
					window.setTimeout( function () {
						window.location.reload();
					}, warned ? 2500 : 600 );
				}
			} )
			.catch( function ( error ) {
				say( error.message, 'error' );
			} )
			.then( function () {
				$buttons.prop( 'disabled', false );
			} );
	}

	/* ---- Forms. ---- */

	/**
	 * Read a form into a JSON payload, or FormData when it holds a file.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {Object|FormData} Payload.
	 */
	function payload( form ) {
		if ( form.querySelector( 'input[type="file"]' ) ) {
			return new FormData( form );
		}

		const data = {};

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.name || field.disabled ) {
				return;
			}

			if ( field.name.slice( -2 ) === '[]' ) {
				const name = field.name.slice( 0, -2 );

				data[ name ] = data[ name ] || [];

				if ( 'checkbox' !== field.type || field.checked ) {
					data[ name ].push( parseInt( field.value, 10 ) || field.value );
				}

				return;
			}

			data[ field.name ] = 'checkbox' === field.type ? field.checked : field.value.trim();
		} );

		return data;
	}

	$( document ).on( 'submit', '[data-zorem-brain-form]', function ( event ) {
		event.preventDefault();

		const $form = $( this );
		const submitter = event.originalEvent && event.originalEvent.submitter ? $( event.originalEvent.submitter ) : $();
		const route = submitter.data( 'zoremBrainRoute' ) || $form.data( 'route' );
		const confirmText = submitter.data( 'confirm' ) || $form.data( 'confirm' );

		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}

		/*
		 * The key form while no acknowledgement is recorded: the acknowledgement
		 * still comes first, but in the same click. Ticked, it is recorded and
		 * the key saved right after; unticked, nothing is sent and the box is
		 * pointed to, instead of a button that silently does nothing.
		 */
		if ( $form.is( '[data-needs-ack]' ) ) {
			const ackForm = document.querySelector( '.zorem-brain__ackform' );
			const ack = ackForm ? ackForm.querySelector( 'input[name="acknowledge"]' ) : null;

			if ( ack && ! ack.checked ) {
				say( i18n.ackFirst || 'Tick the acknowledgement above first.', 'error' );
				ack.focus();

				return;
			}

			if ( ackForm ) {
				const form = this;

				$form.find( 'button' ).prop( 'disabled', true );

				request( $( ackForm ).data( 'route' ), 'POST', payload( ackForm ) )
					.then( function () {
						run( $form, route, $form.data( 'method' ) || 'POST', payload( form ), { reload: true, output: null } );
					} )
					.catch( function ( error ) {
						$form.find( 'button' ).prop( 'disabled', false );
						say( error.message, 'error' );
					} );

				return;
			}
		}

		run( $form, route, $form.data( 'method' ) || 'POST', payload( this ), {
			reload: !! $form.data( 'reload' ),
			output: $form.data( 'output' ) ? $form.siblings( '[data-zorem-brain-output]' ) : null,
		} );
	} );

	/* ---- Buttons and toggles. ---- */

	// A row's three-dot menu closes once an item is picked; ZUI closes it only on a click outside.
	$( document ).on( 'click', '.zui-actions-item', function () {
		const menu = this.closest( '.zui-actions-menu' );
		const toggle = menu ? menu.parentNode.querySelector( '.zui-actions-toggle' ) : null;

		if ( menu ) {
			menu.hidden = true;
		}

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}
	} );

	$( document ).on( 'click', '[data-zorem-brain-action]', function () {
		const $button = $( this );
		const confirmText = $button.data( 'confirm' );

		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}

		run( $button, $button.data( 'route' ), $button.data( 'method' ) || 'POST', $button.data( 'payload' ) || {}, {
			reload: !! $button.data( 'reload' ),
			output: null,
		} );
	} );

	$( document ).on( 'change', '[data-zorem-brain-setting]', function () {
		const $toggle = $( this );
		const on = this.checked;
		const confirmText = $toggle.data( 'confirmOn' );

		if ( on && confirmText && ! window.confirm( confirmText ) ) {
			this.checked = false;
			return;
		}

		const body = {};
		body[ $toggle.data( 'zoremBrainSetting' ) ] = on;

		run( $toggle, 'settings', 'POST', body, { reload: true, output: null } );
	} );

	/* ---- Entries. ---- */

	$( document ).on( 'click', '[data-zorem-brain-entry]', function () {
		const id = String( $( this ).data( 'zoremBrainEntry' ) );
		const $row = $( '[data-zorem-brain-entry-row="' + id + '"]' );
		const body = $( '[data-zorem-brain-entry-body="' + id + '"]' ).get( 0 );

		if ( ! $row.prop( 'hidden' ) ) {
			$row.prop( 'hidden', true );
			return;
		}

		body.textContent = i18n.working || 'Working…';
		$row.prop( 'hidden', false );

		request( 'entries/' + id, 'GET', null )
			.then( function ( entry ) {
				body.textContent = entry.content || '';
			} )
			.catch( function ( error ) {
				body.textContent = error.message;
			} );
	} );

	/* ---- Store policies. ----
	   Saved on change: one value from a list, nothing to get half-finished,
	   and the row's "In the Brain" badge, refreshed by the reload, is the
	   confirmation. */

	$( document ).on( 'focus', '[data-zorem-brain-policy]', function () {
		$( this ).data( 'previous', this.value );
	} );

	$( document ).on( 'change', '[data-zorem-brain-policy]', function () {
		const select = this;
		const $select = $( select );

		$select.prop( 'disabled', true );

		request( 'sources/woocommerce/policy', 'PUT', {
			purpose: $select.data( 'zoremBrainPolicy' ),
			page_id: parseInt( select.value, 10 ) || 0,
		} )
			.then( function () {
				say( i18n.saved || 'Saved.', 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 600 );
			} )
			.catch( function ( error ) {
				select.value = String( $select.data( 'previous' ) || '0' );
				$select.prop( 'disabled', false );
				say( error.message, 'error' );
			} );
	} );

	/* ---- Add another page: the checklist. ----
	   Search and "show selected only" filter rows the browser already holds.
	   The save reads every box in the list, hidden or not, plus chosen pages
	   beyond the list (Rule 19: a filter changes what you see, never what is). */

	const $checklist = function () {
		return $( '[data-zorem-brain-checklist]' );
	};

	let selectedOnly = false;

	/**
	 * Update the "n of m selected" line and the empty-search note.
	 */
	function refreshChecklist() {
		const $card = $checklist();
		const $rows = $card.find( '[data-zorem-brain-check-row]' );
		const term = String( $card.find( '[data-zorem-brain-check-search]' ).val() || '' ).toLowerCase().trim();
		let shown = 0;

		$rows.each( function () {
			const checked = $( this ).find( '[data-zorem-brain-pick]' ).is( ':checked' );
			const matches = '' === term || String( this.getAttribute( 'data-search' ) || '' ).indexOf( term ) !== -1;
			const visible = matches && ( ! selectedOnly || checked );

			this.hidden = ! visible;
			shown += visible ? 1 : 0;
		} );

		const $count = $card.find( '[data-zorem-brain-check-count]' );
		const template = String( $count.data( 'template' ) || '%1$d of %2$d selected' );

		$count.get( 0 ).textContent = template
			.replace( '%1$d', String( $rows.find( '[data-zorem-brain-pick]:checked' ).length ) )
			.replace( '%2$d', String( $rows.length ) );

		$card.find( '[data-zorem-brain-check-empty]' ).prop( 'hidden', shown > 0 || 0 === $rows.length );
	}

	$( document ).on( 'change', '[data-zorem-brain-pick], [data-zorem-brain-all-posts]', function () {
		$checklist().find( '[data-zorem-brain-save-pages]' ).prop( 'disabled', false );
		refreshChecklist();
	} );

	$( document ).on( 'input', '[data-zorem-brain-check-search]', refreshChecklist );

	$( document ).on( 'click', '[data-zorem-brain-check-selected]', function () {
		const $button = $( this );

		selectedOnly = ! selectedOnly;
		$button.get( 0 ).textContent = selectedOnly ? $button.data( 'labelAll' ) : $button.data( 'labelSelected' );

		if ( selectedOnly ) {
			$checklist().find( '[data-zorem-brain-check-search]' ).val( '' );
		}

		refreshChecklist();
	} );

	$( document ).on( 'click', '[data-zorem-brain-save-pages]', function () {
		const $card = $checklist();
		const $button = $( this );
		const $allPosts = $card.find( '[data-zorem-brain-all-posts]' );
		const ids = [];

		$card.find( '[data-zorem-brain-pick]:checked, [data-zorem-brain-pick-extra]' ).each( function () {
			const id = parseInt( this.value, 10 );

			if ( id > 0 && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		} );

		$button.prop( 'disabled', true );

		// Two settings, two requests: the chosen pages, then the standing rule for posts.
		request( 'sources/pages', 'POST', { post_ids: ids } )
			.then( function ( data ) {
				reportRejected( data );

				// Only when it changed: turning it on reads up to 200 posts.
				if ( ! $allPosts.length || $allPosts.prop( 'checked' ) === $allPosts.prop( 'defaultChecked' ) ) {
					return null;
				}

				return request( 'sources/pages', 'POST', { all_posts: $allPosts.prop( 'checked' ) } );
			} )
			.then( function () {
				say( i18n.saved || 'Saved.', 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 600 );
			} )
			.catch( function ( error ) {
				$button.prop( 'disabled', false );
				say( error.message, 'error' );
			} );
	} );

	/* ---- Tabs, filters and pages without a reload. ---- */

	/*
	 * Every tab, the Entries filters and its page links are ordinary links and
	 * a GET form, so each still works without JavaScript and every view has its
	 * own address. Here they are followed in place: the screen's own address is
	 * fetched, its tab bar and body replace the current ones, and the address
	 * bar follows (Back and Forward too). Anything else — a link to another
	 * screen, a new tab, a failed fetch — is left to the browser.
	 */
	const screenPage = 'zorem-agent-brain';
	let navigating = null;

	/**
	 * Whether a URL is this screen, on this site.
	 *
	 * @param {URL} url Address.
	 * @return {boolean} True for the Website Brain screen.
	 */
	function isScreenUrl( url ) {
		return url.origin === window.location.origin && url.pathname === window.location.pathname && url.searchParams.get( 'page' ) === screenPage;
	}

	/**
	 * Show a view of this screen without reloading the page.
	 *
	 * @param {string}  href Address of the view.
	 * @param {boolean} push Whether to add it to the browser history.
	 */
	function navigate( href, push ) {
		const $root = $( '.zorem-brain' ).first();
		const $body = $root.find( '.zorem-brain__body' ).first();

		if ( ! $root.length || ! $body.length || ! window.fetch || ! window.DOMParser ) {
			window.location.assign( href );

			return;
		}

		if ( navigating ) {
			navigating.abort();
		}

		const controller = window.AbortController ? new window.AbortController() : null;

		navigating = controller;
		$root.addClass( 'is-loading' ).attr( 'aria-busy', 'true' );

		window
			.fetch( href, { credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}

				return response.text();
			} )
			.then( function ( html ) {
				const doc = new window.DOMParser().parseFromString( html, 'text/html' );
				const nextBody = doc.querySelector( '.zorem-brain .zorem-brain__body' );
				const nextTabs = doc.querySelector( '.zorem-brain .zui-tabs' );

				// Not the screen (signed out, an error page): let the browser show it.
				if ( ! nextBody ) {
					throw new Error( 'not-the-screen' );
				}

				if ( nextTabs ) {
					$root.find( '.zui-tabs' ).first().replaceWith( document.importNode( nextTabs, true ) );
				}

				const body = $body.get( 0 );

				body.innerHTML = '';
				Array.prototype.forEach.call( nextBody.childNodes, function ( node ) {
					body.appendChild( document.importNode( node, true ) );
				} );

				if ( push ) {
					window.history.pushState( { zoremBrain: true }, '', href );
				}

				if ( window.ZUI && 'function' === typeof window.ZUI.scan ) {
					window.ZUI.scan( body );
				}

				// Back to the tabs when the view starts above the fold no longer.
				const top = $root.find( '.zui-tabs' ).first().offset();

				if ( top && window.scrollY > top.top ) {
					window.scrollTo( 0, Math.max( 0, top.top - 40 ) );
				}

				$( document ).trigger( 'zorem-brain:view', [ href ] );
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				window.location.assign( href );
			} )
			.then( function () {
				if ( navigating === controller ) {
					navigating = null;
					$root.removeClass( 'is-loading' ).removeAttr( 'aria-busy' );
				}
			} );
	}

	$( document ).on( 'click', '.zorem-brain a[href]', function ( event ) {
		if ( event.isDefaultPrevented() || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		if ( this.target && '_self' !== this.target ) {
			return;
		}

		let url;

		try {
			url = new window.URL( this.href, window.location.href );
		} catch ( e ) {
			return;
		}

		if ( ! isScreenUrl( url ) ) {
			return;
		}

		event.preventDefault();

		if ( url.href !== window.location.href ) {
			navigate( url.href, true );
		}
	} );

	// The Entries filters: a GET form to this screen.
	$( document ).on( 'submit', '.zorem-brain form', function ( event ) {
		const form = this;

		if ( event.isDefaultPrevented() || 'get' !== String( form.getAttribute( 'method' ) || '' ).toLowerCase() ) {
			return;
		}

		let url;

		try {
			url = new window.URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
		} catch ( e ) {
			return;
		}

		// A GET form replaces the query with its fields, as the browser would.
		const params = new window.URLSearchParams();

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.name || field.disabled || ( ( 'checkbox' === field.type || 'radio' === field.type ) && ! field.checked ) ) {
				return;
			}

			params.append( field.name, field.value );
		} );

		url.search = params.toString();

		if ( ! isScreenUrl( url ) ) {
			return;
		}

		event.preventDefault();
		navigate( url.href, true );
	} );

	window.addEventListener( 'popstate', function () {
		if ( isScreenUrl( new window.URL( window.location.href ) ) && $( '.zorem-brain__body' ).length ) {
			navigate( window.location.href, false );
		}
	} );
} )( jQuery );
