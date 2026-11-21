/* global jQuery */

/*
 * Plugin admin behaviour, on top of the ZUI library.
 *
 * Deliberately small. The library's own `zui.js` already handles tab switching,
 * sidebar sections, modals, menus, the sortable table and the snackbar — all of
 * it wired from data attributes, none of it needing jQuery. Re-implementing any
 * of that here would mean two switchers disagreeing about which panel is
 * visible, which is the sort of bug nobody can reproduce.
 *
 * What this file is for: talking to the plugin's REST API. Everything else
 * belongs to the library.
 */

( function ( $ ) {
	'use strict';

	const settings = window.aiAgentOs || {};

	/**
	 * Call the plugin's admin REST API.
	 *
	 * The admin routes are nonce-gated with `wp_rest` and capability-gated with
	 * `manage_agent_os`. `AdminRestTest` asserts that every one of them refuses
	 * a caller without either.
	 *
	 * @param {string} path    Route path below the plugin namespace.
	 * @param {Object} options jQuery.ajax overrides.
	 * @return {Promise} Resolves with the parsed body.
	 */
	function request( path, options ) {
		return $.ajax(
			$.extend(
				{
					/*
					 * One slash between the root and the path, whichever of
					 * them carries it. The root ends in "/" and callers write
					 * "/agent/…", and WordPress matches no route for
					 * "v1//agent/…": every save answered 404 rest_no_route.
					 */
					url: String( settings.root ).replace( /\/+$/, '' ) + '/' + String( path ).replace( /^\/+/, '' ),
					method: 'GET',
					dataType: 'json',
					contentType: 'application/json',
					beforeSend( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', settings.nonce );
					},
				},
				options || {}
			)
		).then( null, function ( xhr ) {
			/*
			 * Surface the server's own message where there is one. A REST error
			 * carries a sentence written for a human; replacing it with "request
			 * failed" throws away the only useful part.
			 */
			const message =
				xhr.responseJSON && xhr.responseJSON.message
					? xhr.responseJSON.message
					: settings.i18n.genericError;

			return $.Deferred().reject( new Error( message ) ).promise();
		} );
	}

	const api = {
		request,
		get( path ) {
			return request( path );
		},
		post( path, body ) {
			return request( path, {
				method: 'POST',
				data: JSON.stringify( body || {} ),
			} );
		},
		put( path, body ) {
			return request( path, {
				method: 'PUT',
				data: JSON.stringify( body || {} ),
			} );
		},
		del( path ) {
			return request( path, { method: 'DELETE' } );
		},
	};

	/**
	 * Say something, using the library's snackbar.
	 *
	 * Added at Z7, and it closes two things at once.
	 *
	 * Six files carried `window.alert` with a comment promising "replaced by
	 * the ZUI snackbar in Z6" — and Z6 came and went without it. A stale promise
	 * in the source is worse than no promise, and `alert()` on an admin screen
	 * is a modal interruption for something that is usually not urgent.
	 *
	 * It also restores feedback the port had quietly lost. The React screens
	 * said "Settings saved.", "Demo products created.", "%d fixture products
	 * removed." The ZUI screens reload instead, which shows the new state but
	 * cannot say *how many* of anything changed — and "6 products removed" is
	 * information no amount of looking at the resulting screen recovers.
	 *
	 * @param {string} message What happened.
	 * @param {string} type    success, error, warning or info.
	 */
	function say( message, type ) {
		if ( window.ZUI && window.ZUI.snackbar ) {
			window.ZUI.snackbar( message, { type: type || 'info' } );
			return;
		}

		// Last resort only — the library is enqueued on every screen that calls this.
		window.alert( message ); // eslint-disable-line no-alert -- ZUI absent.
	}

	api.say = say;

	window.aiAgentOsAdmin = api;

	/**
	 * Read a settings form into the shape the REST route expects.
	 *
	 * Checkboxes are read from `.checked` rather than from the form's serialised
	 * value, because each one is paired with a hidden `0` input and serialising
	 * would send both.
	 *
	 * @param {Element} form The form element.
	 * @return {Object} Key/value pairs.
	 */
	function readForm( form ) {
		const values = {};

		$( form )
			.find( 'input, select, textarea' )
			.each( function () {
				if ( ! this.name || this.type === 'hidden' ) {
					return;
				}

				if ( this.type === 'checkbox' ) {
					values[ this.name ] = this.checked;
				} else if ( this.type === 'radio' ) {
					if ( this.checked ) {
						values[ this.name ] = this.value;
					}
				} else if ( this.type === 'number' ) {
					values[ this.name ] = parseInt( this.value, 10 );
				} else {
					values[ this.name ] = this.value;
				}
			} );

		return values;
	}

	/**
	 * The one-save model: wire a settings form to its save button.
	 *
	 * This replaces the React screen's auto-save, where every toggle wrote to the
	 * database the instant it moved. Auto-save is fine for a preference and wrong
	 * for a permission: "Allow destructive abilities" should not take
	 * effect because somebody's finger slipped, and there was no way to undo it
	 * short of clicking back. Now nothing is written until Save is pressed, and
	 * the button is disabled until something has actually changed.
	 *
	 * The cost, stated plainly: a merchant who changes a toggle and navigates away
	 * loses the change. That is the trade — a lost edit is recoverable, a
	 * silently-granted permission is not. A card holding a single switch is the
	 * exception and opts into `bindAutosaveForm()` instead.
	 *
	 * Every form carrying `.aaos-settings-form` is bound, not only the one on the
	 * Settings tab. This used to select `[data-aaos-settings-form]`, which
	 * exactly one form has — the other four named themselves
	 * `data-aaos-knowledge-form`, `data-aaos-agent-form` and
	 * `data-aaos-shop-form`, and none of those three strings appeared anywhere in
	 * this file. Nothing selected them, so nothing bound them, so their toggles
	 * could be flipped and never saved. The Save button on two of them rendered
	 * `disabled` and stayed that way, which reads as "nothing to save" rather than
	 * "this does nothing" — the reason six screenshot reviews went past it.
	 *
	 * The endpoint is per-form: most of these write site settings, but the agent's
	 * Connection panel writes the agent. `data-aaos-endpoint` and
	 * `data-aaos-method` carry that; the default is the old behaviour.
	 *
	 * @param {HTMLFormElement} form The settings form.
	 */
	function bindSettingsForm( form ) {
		const $form = $( form );
		const $save = $form.find( '[data-aaos-save]' );

		/*
		 * The early return comes before the rest of the setup, so nothing is
		 * computed for a form that has no save button to wire it to.
		 */
		if ( ! $save.length ) {
			return;
		}

		const endpoint =
			form.getAttribute( 'data-aaos-endpoint' ) || '/settings';
		const method = (
			form.getAttribute( 'data-aaos-method' ) || 'post'
		).toLowerCase();
		let baseline = JSON.stringify( readForm( form ) );

		function refresh() {
			const dirty = JSON.stringify( readForm( form ) ) !== baseline;
			$save.prop( 'disabled', ! dirty );
			markDirty( form, dirty );
		}

		$form.on( 'change input', 'input, select, textarea', refresh );

		$save.on( 'click', function () {
			const $button = $( this );
			const $label = $button.find( '.zui-savebtn__label' );
			const previous = $label.text();

			$button.prop( 'disabled', true ).addClass( 'is-saving' );
			$label.text( settings.i18n.saving );

			api[ method ]( endpoint, readForm( form ) )
				.then( function ( data ) {
					/*
					 * Repaint from the response, not from what was typed. The
					 * server clamps the retention window, so a merchant who
					 * typed 5000 must see 365 sitting in the box rather than
					 * their own number and a success message.
					 */
					const saved = ( data && data.settings ) || {};
					const sent = readForm( form );

					$form.find( 'input[type="number"]' ).each( function () {
						if (
							! Object.prototype.hasOwnProperty.call(
								saved,
								this.name
							)
						) {
							return;
						}

						/*
						 * Say so when the server changed the number.
						 *
						 * Repainting silently was the whole bug: somebody types
						 * 90000, the box shows 365, and the only reading
						 * available is "the save failed and it reverted". The
						 * clamp is correct and deliberate — what was missing was
						 * anybody being told it happened.
						 */
						const before = Number( sent[ this.name ] );
						const after = Number( saved[ this.name ] );

						if ( before !== after && ! Number.isNaN( before ) ) {
							api.say(
								(
									settings.i18n.adjusted ||
									'%1$s was adjusted to %2$s.'
								)
									.replace( '%1$s', String( before ) )
									.replace( '%2$s', String( after ) ),
								'info'
							);
						}

						this.value = saved[ this.name ];
					} );

					$label.text( settings.i18n.saved );
					window.setTimeout( function () {
						$label.text( previous );
					}, 2000 );

					/*
					 * The button's "Saved" lasts two seconds on a button that
					 * greys out at the same moment, so on its own it reads as
					 * nothing happening. Say it the way every other action does.
					 */
					api.say(
						settings.i18n.changesSaved ||
							'Your changes have been saved.',
						'success'
					);

					/*
					 * The new values become the clean baseline, so Save goes
					 * back to disabled and a second click cannot re-send.
					 * Reset the baseline rather than re-running this function —
					 * calling it again would bind a second set of handlers and
					 * every later save would fire twice.
					 */
					baseline = JSON.stringify( readForm( form ) );
					refresh();
				} )
				.fail( function ( error ) {
					$label.text( previous );
					$button.prop( 'disabled', false );
					api.say( error.message, 'error' );
				} )
				.always( function () {
					$button.removeClass( 'is-saving' );
				} );
		} );

		refresh();
	}


	/* ------------------------------------------------------------------
	   Keep the URL describing what is on screen.

	   Every tab panel is rendered into the document on every request and
	   the library shows and hides them, so switching a tab changes nothing
	   the browser knows about. The address bar keeps saying `tab=agent`
	   while you are reading the Status tab — and a refresh, a bookmark, or
	   a link sent to somebody else all land back on the tab you happened
	   to open first.

	   `zui.js` dispatches `zui:tab` and `zui:section` on every switch, so
	   this listens rather than re-implementing the switching. Two
	   switchers disagreeing about which panel is visible is the bug this
	   file's header warns against.

	   Tabs push, sections replace. A tab reads as a page and Back should
	   return to the previous one; a sidebar section is sub-navigation
	   within a tab, and pushing it would make Back a slow walk through
	   every section a user glanced at.
	   ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------
	   Unsaved changes.

	   Sections and tabs switch client-side, so leaving one is a click that
	   the browser never hears about — no navigation, no `beforeunload`,
	   nothing. A merchant who flips a permission toggle, glances at another
	   section and comes back finds it off again, with no sign anything was
	   lost. On a screen whose whole job is permissions, that is the worst
	   possible thing to lose silently.

	   `bindSettingsForm()` already knows whether a form is dirty; it
	   publishes that here rather than each screen inventing its own idea of
	   unsaved.
	   ------------------------------------------------------------------ */

	const dirtyForms = new Set();

	function markDirty( form, dirty ) {
		if ( dirty ) {
			dirtyForms.add( form );
		} else {
			dirtyForms.delete( form );
		}
	}

	/**
	 * Whether anything on screen has unsaved changes.
	 *
	 * Only visible forms count. A hidden panel's form is still in the DOM —
	 * every tab renders on every request — and treating a change nobody can
	 * see as a reason to block navigation would make the warning fire for
	 * something the user cannot find.
	 *
	 * @return {boolean} True when a visible form is dirty.
	 */
	function hasUnsavedChanges() {
		let unsaved = false;

		dirtyForms.forEach( function ( form ) {
			if ( form.isConnected && form.offsetParent !== null ) {
				unsaved = true;
			}
		} );

		return unsaved;
	}

	function confirmDiscard() {
		if ( ! hasUnsavedChanges() ) {
			return true;
		}

		// eslint-disable-next-line no-alert -- A confirm is the point: this is destructive and needs an answer before the click proceeds.
		return window.confirm(
			settings.i18n.unsavedChanges ||
				'You have unsaved changes. Leave without saving?'
		);
	}

	/*
	 * Closing the tab is the browser's own dialog, and the string is ignored
	 * by every modern browser — returning a value is what asks for it.
	 */
	$( window ).on( 'beforeunload', function ( event ) {
		if ( ! hasUnsavedChanges() ) {
			return undefined;
		}

		event.preventDefault();
		event.originalEvent.returnValue = '';

		return '';
	} );

	function bindNavigationState() {
		const supported =
			window.history &&
			window.history.replaceState &&
			window.URL &&
			$( '#aaos-app' ).length;

		if ( ! supported ) {
			return;
		}

		/*
		 * Restoring drives the library by clicking its own controls, which
		 * re-fires `zui:tab` — so without this guard, handling a Back would
		 * push a fresh history entry and the button would fight itself: one
		 * press moves, the next lands on the entry the press just created.
		 *
		 * Cleared on a timer rather than synchronously because the library
		 * dispatches its event from the click handler, after this frame.
		 */
		let restoring = false;

		const writeUrl = function ( changes, push ) {
			if ( restoring ) {
				return;
			}

			const url = new window.URL( window.location.href );

			Object.keys( changes ).forEach( function ( key ) {
				if ( null === changes[ key ] ) {
					url.searchParams.delete( key );
					return;
				}

				url.searchParams.set( key, changes[ key ] );
			} );

			if ( url.href === window.location.href ) {
				return;
			}

			window.history[ push ? 'pushState' : 'replaceState' ](
				{ zorem: true },
				'',
				url.href
			);
		};

		/*
		 * Intercepted in the capture phase, because `zui.js` listens for the
		 * same click in the bubble phase and would already have swapped the
		 * panel by the time a bubbling handler ran. Stopping it here is the
		 * only point at which "stay where you are" is still possible.
		 */
		document.addEventListener(
			'click',
			function ( event ) {
				const nav = event.target.closest(
					'.zui-sidebar__item[data-zui-section], .zui-tabs__item[data-zui-tab]'
				);

				if ( ! nav || nav.classList.contains( 'is-active' ) ) {
					return;
				}

				if ( confirmDiscard() ) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				event.stopImmediatePropagation();
			},
			true
		);

		/*
		 * A section is a page, so it starts at the top. Without this, leaving a
		 * tall section for a short one leaves the viewport parked below the new
		 * content, looking like an empty screen.
		 */
		$( document ).on( 'zui:section zui:tab', function () {
			const panel = document.querySelector(
				'.zui-tab-panel:not([hidden])'
			);

			if ( panel && panel.getBoundingClientRect().top < 0 ) {
				panel.scrollIntoView( { block: 'start' } );
			}
		} );

		$( document ).on( 'zui:tab', function ( event ) {
			const tab =
				event.originalEvent && event.originalEvent.detail
					? event.originalEvent.detail.tab
					: null;

			if ( ! tab ) {
				return;
			}

			/*
			 * `section` is dropped on a tab change because section keys are
			 * only unique within a tab: carrying `section=connection` from
			 * the Agent tab onto the Settings tab would ask for a section
			 * that does not exist there, and the restore below would silently
			 * do nothing while the URL claimed otherwise.
			 */
			writeUrl(
				{
					tab,
					section: null,
				},
				true
			);
		} );

		$( document ).on( 'zui:section', function ( event ) {
			const section =
				event.originalEvent && event.originalEvent.detail
					? event.originalEvent.detail.section
					: null;

			if ( section ) {
				writeUrl( { section }, false );
			}
		} );

		/*
		 * Restore on load and on Back/Forward.
		 *
		 * The tab itself is restored server-side — `layout-app.php` reads
		 * `tab` and renders that panel visible — so only the section needs
		 * applying here, and only within the visible panel, since every
		 * hidden panel has a sidebar of its own.
		 *
		 * Restoring by clicking the real control rather than by toggling
		 * classes: the library owns what "active" means, and duplicating
		 * that here is how the two switchers start disagreeing.
		 */
		const restoreSection = function () {
			let wanted = new window.URL(
				window.location.href
			).searchParams.get( 'section' );

			if ( ! wanted ) {
				return;
			}

			// The agent's Connection section became Connect (old links and bookmarks).
			if ( 'connection' === wanted ) {
				wanted = 'connect';
			}

			const $item = $( '.zui-tab-panel:not([hidden])' )
				.find( '.zui-sidebar__item[data-zui-section="' + wanted + '"]' )
				.not( '.is-active' )
				.first();

			if ( $item.length ) {
				$item.trigger( 'click' );
			}
		};

		/*
		 * Back and Forward move between tabs without a page load, so the
		 * panel has to be switched here too — the server only gets a say on
		 * the first request. Without this, Back would rewrite the address
		 * bar and leave the screen showing the previous tab, which is worse
		 * than not pushing at all.
		 */
		const restoreTab = function () {
			const wanted = new window.URL(
				window.location.href
			).searchParams.get( 'tab' );

			if ( ! wanted ) {
				return;
			}

			const $item = $( '.zui-tabs__item[data-zui-tab="' + wanted + '"]' )
				.not( '.is-active' )
				.first();

			if ( $item.length ) {
				$item.trigger( 'click' );
			}
		};

		restoreSection();

		$( window ).on( 'popstate', function () {
			restoring = true;
			window.setTimeout( function () {
				restoring = false;
			}, 0 );

			restoreTab();

			/*
			 * After the tab switch, not before: the section lives inside the
			 * panel that just became visible, and the selector above looks
			 * only at the visible one.
			 */
			restoreSection();
		} );
	}

	$( function () {
		bindNavigationState();

		/*
		 * A row's three-dot menu closes once an item is picked. The library
		 * closes it only on a click outside, so a Delete that opens a dialog
		 * left the menu open behind it. → upstream.
		 */
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

		$( 'form.aaos-settings-form' ).each( function () {
			bindSettingsForm( this );
		} );

		$( document ).on(
			'click',
			'[data-aaos-copy-diagnostics]',
			function () {
				/*
				 * Printed by the Status panel with wp_add_inline_script, not
				 * as a tag in the page: a plugin should put its data on the
				 * page through the enqueue API like everything else.
				 */
				const payload = window.aiAgentOsDiagnostics || '{}';
				const $button = $( this );
				const $label = $button.find( 'span' );
				const previous = $label.text();

				/*
				 * `navigator.clipboard` needs a secure context, which a local dev
				 * site over plain http is not. Falling back to a throwaway textarea
				 * keeps the button honest everywhere rather than failing silently on
				 * exactly the installs most likely to need a support ticket.
				 */
				const done = function () {
					$label.text( settings.i18n.copied || 'Copied' );
					window.setTimeout( function () {
						$label.text( previous );
					}, 2000 );

					/*
					 * A snackbar as well as the label.
					 *
					 * The label flip works and lasts two seconds, which is long
					 * enough to miss entirely — you click Copy and immediately
					 * look at wherever you are pasting. Reported as "no
					 * feedback", and the honest reading is that feedback nobody
					 * sees is not feedback.
					 */
					say( settings.i18n.copied || 'Copied', 'success' );
				};

				const viaTextarea = function () {
					const scratch = document.createElement( 'textarea' );
					scratch.value = payload;
					scratch.setAttribute( 'readonly', 'readonly' );
					scratch.style.position = 'fixed';
					scratch.style.left = '-9999px';
					document.body.appendChild( scratch );
					scratch.select();
					document.execCommand( 'copy' );
					document.body.removeChild( scratch );
					done();
				};

				/*
				 * The fallback runs on rejection too, not only on a missing API.
				 *
				 * `navigator.clipboard.writeText()` returns a promise that rejects
				 * whenever the browser declines — permission denied, the document
				 * not focused, a hardened profile. This had no `catch`, so a
				 * rejected write did nothing at all: no copy, no message, a button
				 * that looked broken on exactly the installs whose owner is trying
				 * to file a support ticket.
				 *
				 * Found by clicking it in a headless browser, where the write is
				 * refused by default. The condition was right and the failure path
				 * was missing.
				 */
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard
						.writeText( payload )
						.then( done )
						.catch( viaTextarea );
					return;
				}

				viaTextarea();
			}
		);
	} );

} )( jQuery );
