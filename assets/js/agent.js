/* global jQuery */

/*
 * The agent: the permission matrix, the connection settings, the token reveal.
 *
 * Depends on `aaos-admin` for the REST wrapper. Everything to do with
 * opening and closing modals belongs to `zui.js` and is not re-implemented
 * here — with exactly one exception, the token dialog, documented where it
 * happens.
 */

( function ( $ ) {
	'use strict';

	const api = window.aiAgentOsAdmin;
	const settings = window.aiAgentOs || {};

	if ( ! api ) {
		return;
	}

	/**
	 * Show a message. Inline where there is a slot, alert as the last resort.
	 *
	 * @param {string} message The message.
	 */
	function fail( message ) {
		api.say( message, 'error' );
	}

	/* ------------------------------------------------------------------
	   The token, on the Connection tab.

	   There is no token dialog. The agent starts without a token (most
	   connect by signing in), and one is issued on its Connection tab, where
	   the plaintext is shown in the Token card. PHP never renders it and
	   nothing persists it; it lives in that field until the page is left or
	   reloaded, which is the "shown once" the notice beside it describes.
	   ------------------------------------------------------------------ */

	/**
	 * Show a freshly issued token in the Connection tab's Token card.
	 *
	 * @param {string} token The plaintext token.
	 * @return {boolean} Whether there was a Token card to show it in.
	 */
	function fillToken( token ) {
		const $box = $( '[data-aaos-token-inline]' ).first();

		if ( ! $box.length || ! token ) {
			return false;
		}

		$box.find( '[data-aaos-token-value]' ).val( token );
		$box.prop( 'hidden', false );

		// The prefix is what the server keeps readable: TokenService::display_prefix(), twelve characters.
		$( '[data-aaos-token-prefix]' ).text( token.slice( 0, 12 ) + '…' );

		const field = $box.find( '[data-aaos-token-value]' ).get( 0 );

		if ( field ) {
			field.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			field.focus();
			field.select();
		}

		return true;
	}

	/*
	 * No token yet: issue one without asking, since there is nothing for it
	 * to replace. Replacing an existing token goes through the
	 * "Issue a new token?" confirmation instead (data-aaos-confirm below).
	 */
	$( document ).on( 'click', '[data-aaos-issue-token]', function () {
		if ( ! hasAgent() ) {
			return;
		}

		const $button = $( this );

		$( '[data-aaos-issue-token]' ).prop( 'disabled', true );

		api.post( '/agent/rotate-token', {} )
			.then( function ( data ) {
				$( '[data-aaos-issue-token]' ).prop( 'hidden', true );
				fillToken( data.token );
			} )
			.fail( function ( error ) {
				$( '[data-aaos-issue-token]' ).prop( 'disabled', false );
				$button.trigger( 'blur' );
				fail( error.message );
			} );
	} );

	/* ------------------------------------------------------------------
	   Copy, anywhere on the page.

	   Takes its source from the button:

	       data-aaos-copy-target="[data-aaos-copy-source='config']"

	   Reads the DOM on every click rather than closing over a value, so a
	   token filled into the Connection tab is copied from the field it
	   sits in and nowhere else holds it.
	   ------------------------------------------------------------------ */

	$( document ).on( 'click', '[data-aaos-copy-target]', function () {
		const $button = $( this );
		const $source = $( $button.data( 'aaos-copy-target' ) );

		if ( ! $source.length ) {
			return;
		}

		// <textarea> carries its value; <code> carries text.
		const value = $source.is( 'textarea, input' )
			? $source.val()
			: $source.text();

		if ( ! value ) {
			return;
		}

		const $label = $button.find( 'span' );
		const previous = $label.text();

		const done = function () {
			$label.text( settings.i18n.copied || 'Copied' );
			window.setTimeout( function () {
				$label.text( previous );
			}, 2000 );
		};

		/*
		 * The textarea path selects and copies in place. A <code> element
		 * cannot be selected that way, so it borrows a detached textarea —
		 * removed immediately, so nothing is left selected on the page.
		 */
		const viaSelection = function () {
			const node = $source.is( 'textarea, input' )
				? $source[ 0 ]
				: $( '<textarea>' )
						.val( value )
						.css( { position: 'fixed', opacity: 0 } )
						.appendTo( 'body' )[ 0 ];

			node.select();
			document.execCommand( 'copy' );
			node.ownerDocument.defaultView.getSelection().removeAllRanges();

			if ( ! $source.is( 'textarea, input' ) ) {
				$( node ).remove();
			}

			done();
		};

		/*
		 * `navigator.clipboard` needs a secure context, which a site served
		 * over plain HTTP is not — and it returns a promise that can reject.
		 * Both fall back rather than leaving a button that did nothing.
		 */
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard
				.writeText( value )
				.then( done )
				.catch( viaSelection );
			return;
		}

		viaSelection();
	} );

	/* ------------------------------------------------------------------
	   Confirmation modals.

	   One dialog serves each action; the trigger's data attribute carries the
	   agent's name, and the dialog shows it before confirming.
	   ------------------------------------------------------------------ */

	let pending = {};

	$( document ).on( 'click', '[data-zui-modal-toggle]', function () {
		const $trigger = $( this );

		pending = {
			name: $trigger.data( 'aaos-agent-name' ) || '',
		};

		const $modal = $( '#' + $trigger.attr( 'data-zui-modal-toggle' ) );
		const $subject = $modal.find( '[data-aaos-confirm-subject]' );

		if ( pending.name ) {
			$subject.prop( 'hidden', false ).text( pending.name );
		} else {
			$subject.prop( 'hidden', true ).text( '' );
		}
	} );

	/**
	 * Whether the agent screen is on the page.
	 *
	 * @return {boolean} Whether the agent is shown.
	 */
	function hasAgent() {
		return null !== document.querySelector( '[data-aaos-agent]' );
	}

	$( document ).on( 'click', '[data-aaos-confirm]', function () {
		if ( ! hasAgent() ) {
			return;
		}

		const action = $( this ).attr( 'data-aaos-confirm' );
		const $button = $( this );

		$button.prop( 'disabled', true );

		let request;

		if ( action === 'revoke-agent' ) {
			request = api.post( '/agent/revoke', {} );
		} else if ( action === 'revoke-all-permissions' ) {
			request = api.post( '/agent/revoke-all-permissions', {} );
		} else if ( action === 'rotate-token' ) {
			request = api.post( '/agent/rotate-token', {} );
		} else {
			$button.prop( 'disabled', false );
			return;
		}

		request
			.then( function ( data ) {
				$button.prop( 'disabled', false );

				if ( window.ZUI && window.ZUI.modal ) {
					const modal = $button.closest( '.zui-modal' ).attr( 'id' );
					window.ZUI.modal.close( modal );
				}

				if ( action === 'rotate-token' ) {
					// Filled into the Connection tab; anywhere else, open that tab, where the next token is issued.
					if ( ! fillToken( data.token ) ) {
						window.location.href =
							( settings.agentUrl ||
								'?page=ai-agent-os&tab=agent' ) +
							'&section=connection';
					}

					return;
				}

				/*
				 * "Every permission revoked" is not visible in the resulting
				 * screen — a matrix of Nones looks the same as a matrix that
				 * was always None. The React version said it; this says it too.
				 */
				if ( action === 'revoke-all-permissions' ) {
					api.say( settings.i18n.allRevoked, 'success' );

					window.setTimeout( function () {
						window.location.reload();
					}, 1400 );

					return;
				}

				window.location.reload();
			} )
			.fail( function ( error ) {
				$button.prop( 'disabled', false );
				fail( error.message );
			} );
	} );

	$( document ).on( 'click', '[data-aaos-reactivate]', function () {
		const $button = $( this );

		$button.prop( 'disabled', true );

		api.put( '/agent', { status: 'active' } )
			.then( function () {
				window.location.reload();
			} )
			.fail( function ( error ) {
				$button.prop( 'disabled', false );
				fail( error.message );
			} );
	} );

	/* ------------------------------------------------------------------
	   The permission matrix.
	   ------------------------------------------------------------------ */

	const $matrix = () => $( '[data-aaos-matrix]' );

	/**
	 * Every ability's current level, read from the buttons.
	 *
	 * @return {Object} Ability name → level.
	 */
	function readMatrix() {
		const grants = {};

		$matrix()
			.find( '[data-aaos-ability]' )
			.each( function () {
				const active = this.querySelector(
					'.aaos-seg__item.is-active'
				);

				grants[ this.getAttribute( 'data-aaos-ability' ) ] = active
					? active.getAttribute( 'data-aaos-level' )
					: 'none';
			} );

		return grants;
	}

	let matrixBaseline = null;

	/**
	 * Enable Save only when something differs from what was loaded.
	 */
	/**
	 * Highlight the bulk button whose choice is what the rows now hold.
	 *
	 * "Grant all" lights when every ability in its scope is at the most it
	 * can take, "All read" when every one is at read, "None" / "Revoke" when
	 * none is granted. A mix lights nothing, so after a single ability is
	 * changed by hand no button claims to describe the section. A destructive
	 * level that cannot be chosen (destructive mode off) is not counted as
	 * the most a row can take, since "Grant all" skips it.
	 */
	function paintBulkState() {
		const $all = $matrix().find( '[data-aaos-ability]' );

		const levelOf = function ( row ) {
			const active = row.querySelector( '.aaos-seg__item.is-active' );

			return active ? active.getAttribute( 'data-aaos-level' ) : 'none';
		};

		/*
		 * The level a bulk button gives this row, as its click handler picks
		 * it. A row it cannot set (a locked destructive ability) can only be
		 * None, so "All read" still lights with destructive switched off.
		 */
		const targetOf = function ( row, level ) {
			if ( 'write' === level ) {
				const write = row.querySelector( '[data-aaos-level="write"]' );

				if ( write && ! write.disabled ) {
					return 'write';
				}

				level = 'read';
			}

			const target = row.querySelector(
				'[data-aaos-level="' + level + '"]'
			);

			return target && ! target.disabled ? level : 'none';
		};

		$matrix()
			.find( '[data-aaos-bulk]' )
			.each( function () {
				const group = this.getAttribute( 'data-aaos-bulk-group' );
				const level = this.getAttribute( 'data-aaos-bulk' );
				const rows = ( group
					? $all.filter( '[data-aaos-group="' + group + '"]' )
					: $all
				).get();

				const matches =
					rows.length > 0 &&
					rows.every( function ( row ) {
						return levelOf( row ) === targetOf( row, level );
					} );

				this.classList.toggle( 'is-active', matches );
				this.setAttribute( 'aria-pressed', matches ? 'true' : 'false' );
			} );

		/*
		 * Off / View only / Full is one choice: in a section that can only be
		 * looked at, "Full" and "View only" mean the same, so only "View only"
		 * lights.
		 */
		$matrix()
			.find( '.aaos-lvl' )
			.each( function () {
				const read = this.querySelector( '[data-aaos-bulk="read"]' );
				const write = this.querySelector( '[data-aaos-bulk="write"]' );

				if (
					read &&
					write &&
					read.classList.contains( 'is-active' ) &&
					write.classList.contains( 'is-active' )
				) {
					write.classList.remove( 'is-active' );
					write.setAttribute( 'aria-pressed', 'false' );
				}
			} );
	}

	/**
	 * Hide the sections with nothing on, while "Show only what's on" is ticked.
	 */
	function applyOnlyOn() {
		const only = $( '[data-aaos-only-on]' ).is( ':checked' );

		$matrix()
			.find( '[data-aaos-matrix-group]' )
			.each( function () {
				const group = this.getAttribute( 'data-aaos-group' );
				const $rows = $matrix().find(
					'[data-aaos-ability][data-aaos-group="' + group + '"]'
				);
				const anyOn = $rows.get().some( function ( row ) {
					const active = row.querySelector( '.aaos-seg__item.is-active' );

					return (
						active && 'none' !== active.getAttribute( 'data-aaos-level' )
					);
				} );
				const hide = only && ! anyOn;

				this.classList.toggle( 'is-filtered', hide );
				$rows.toggleClass( 'is-filtered', hide );
			} );
	}

	$( document ).on( 'change', '[data-aaos-only-on]', applyOnlyOn );

	function refreshMatrix() {
		paintBulkState();
		applyOnlyOn();

		if ( matrixBaseline === null ) {
			return;
		}

		$( '[data-aaos-save-permissions]' ).prop(
			'disabled',
			JSON.stringify( readMatrix() ) === matrixBaseline
		);

	}

	$( document ).on(
		'click',
		'[data-aaos-matrix] [data-aaos-level]',
		function () {
			if ( this.disabled ) {
				return;
			}

			$( this )
				.closest( '.aaos-seg' )
				.find( '.aaos-seg__item' )
				.removeClass( 'is-active' );

			$( this ).addClass( 'is-active' );

			refreshMatrix();
		}
	);

	/**
	 * Set every ability in one section, or in all of them, to a level.
	 *
	 * A level a row cannot take is not forced on it: a destructive ability on a
	 * site with destructive switched off has its control disabled, and "Grant
	 * all" must not appear to have granted something the save would drop.
	 */
	$( document ).on( 'click', '[data-aaos-bulk]', function () {
		const group = this.getAttribute( 'data-aaos-bulk-group' );

		// Clicking the choice that is already selected undoes it: back to None.
		const level = this.classList.contains( 'is-active' )
			? 'none'
			: this.getAttribute( 'data-aaos-bulk' );

		// No group: the toolbar's "All abilities" buttons, for every section.
		const rows = group
			? '[data-aaos-ability][data-aaos-group="' + group + '"]'
			: '[data-aaos-ability]';

		$matrix()
			.find( rows )
			.each( function () {
				/*
				 * "Grant all" means the most this ability offers, not Write
				 * specifically. A READ ability has no Write control — quite
				 * deliberately, since granting write on it would store a level
				 * that means nothing — so asking for Write by name did nothing
				 * at all in a section of read-only abilities, and "Grant all"
				 * on SITE looked broken rather than principled.
				 */
				let target = this.querySelector(
					'[data-aaos-level="' + level + '"]'
				);

				if ( ! target && 'write' === level ) {
					target = this.querySelector( '[data-aaos-level="read"]' );
				}

				if ( ! target || target.disabled ) {
					return;
				}

				this.querySelectorAll( '.aaos-seg__item' ).forEach( ( b ) =>
					b.classList.remove( 'is-active' )
				);

				target.classList.add( 'is-active' );
			} );

		refreshMatrix();
	} );

	/**
	 * Repaint every count that describes the grants, after a save.
	 *
	 * Three places on this screen state the same fact: the header's "N tools
	 * granted", each section heading's "N of M granted", and the matrix rows
	 * themselves. Only the rows updated, so a successful save left the other
	 * two contradicting it until a reload — which reads as a save that failed.
	 *
	 * Driven from the server's response rather than from the DOM, so the
	 * numbers agree with what was stored rather than with what was clicked.
	 *
	 * @param {Object} grants Ability name => level, as stored.
	 */
	function updateGrantCounts( grants ) {
		const $rows = $matrix().find( '[data-aaos-ability]' );
		const totals = {};
		let granted = 0;

		$rows.each( function () {
			const group = this.getAttribute( 'data-aaos-group' );
			const name = this.getAttribute( 'data-aaos-ability' );
			const level = grants[ name ] || 'none';

			totals[ group ] = totals[ group ] || { total: 0, granted: 0 };
			totals[ group ].total++;

			if ( 'none' !== level ) {
				totals[ group ].granted++;
				granted++;
			}
		} );

		Object.keys( totals ).forEach( function ( group ) {
			/*
			 * From the band, not from the toggle: the tally moved out of the
			 * toggle button to sit beside the bulk controls that change it.
			 */
			const $tally = $(
				'[data-aaos-matrix-group][data-aaos-group="' +
					group +
					'"] .aaos-mtable__tally'
			);

			$tally
				.toggleClass( 'is-granted', totals[ group ].granted > 0 )
				.text(
					( $matrix().attr( 'data-aaos-tally' ) || settings.i18n.grantedOf || '%1$d of %2$d granted' )
						.replace( '%1$d', String( totals[ group ].granted ) )
						.replace( '%2$d', String( totals[ group ].total ) )
				);
		} );

		const template =
			1 === granted
				? settings.i18n.toolGranted || '%s tool granted'
				: settings.i18n.toolsGranted || '%s tools granted';

		$( '[data-aaos-grant-count]' ).text(
			template.replace( '%s', String( granted ) )
		);
	}

	/* ------------------------------------------------------------------
	   Connect page: the app picker (tabs), the address's "⋯" menu, and
	   Disconnect for one signed-in app.
	   ------------------------------------------------------------------ */

	/**
	 * Show one app's guide.
	 *
	 * @param {jQuery} $tab The tab to select.
	 */
	function selectAppTab( $tab ) {
		const $box = $tab.closest( '[data-aaos-cx-apps]' );
		const app = $tab.attr( 'data-aaos-cx-tab' );

		$box.find( '[data-aaos-cx-tab]' ).attr( { 'aria-selected': 'false', tabindex: '-1' } );
		$tab.attr( { 'aria-selected': 'true', tabindex: '0' } );
		$box.find( '[data-aaos-cx-panel]' ).prop( 'hidden', true );
		$box.find( '[data-aaos-cx-panel="' + app + '"]' ).prop( 'hidden', false );

		// The token block belongs only to the apps that cannot sign in (Codex, Other).
		$box.find( '[data-aaos-cx-token-for]' ).each( function () {
			const forApps = ( this.getAttribute( 'data-aaos-cx-token-for' ) || '' ).split( ' ' );

			this.hidden = forApps.indexOf( app ) === -1;
		} );
	}

	$( document ).on( 'click', '[data-aaos-cx-tab]', function () {
		selectAppTab( $( this ) );
	} );

	// Arrow keys move between tabs, as in any tab list.
	$( document ).on( 'keydown', '[data-aaos-cx-tab]', function ( event ) {
		const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };

		if ( ! keys[ event.key ] && 'Home' !== event.key && 'End' !== event.key ) {
			return;
		}

		event.preventDefault();

		const $tabs = $( this ).closest( '[role="tablist"]' ).find( '[data-aaos-cx-tab]' );
		let next = $tabs.index( this ) + ( keys[ event.key ] || 0 );

		if ( 'Home' === event.key ) {
			next = 0;
		} else if ( 'End' === event.key ) {
			next = $tabs.length - 1;
		}

		const $next = $tabs.eq( ( next + $tabs.length ) % $tabs.length );

		selectAppTab( $next );
		$next.trigger( 'focus' );
	} );

	$( document ).on( 'click', '[data-aaos-cx-more]', function ( event ) {
		event.stopPropagation();

		const $menu = $( '#' + $( this ).attr( 'aria-controls' ) );
		const open = $menu.prop( 'hidden' );

		$menu.prop( 'hidden', ! open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
	} );

	// A click elsewhere, or Escape, closes the menu.
	$( document ).on( 'click keydown', function ( event ) {
		if ( 'keydown' === event.type && 'Escape' !== event.key ) {
			return;
		}

		if ( 'click' === event.type && $( event.target ).closest( '.aaos-cx-more__menu' ).length ) {
			return;
		}

		$( '.aaos-cx-more__menu' ).prop( 'hidden', true );
		$( '[data-aaos-cx-more]' ).attr( 'aria-expanded', 'false' );
	} );

	$( document ).on( 'click', '[data-aaos-cx-disconnect]', function () {
		const $button = $( this );
		const api = window.aiAgentOsAdmin;

		// eslint-disable-next-line no-alert -- Ends that app's connection; needs an answer first.
		if ( ! window.confirm( $button.attr( 'data-confirm' ) ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		api.del( '/agent/sign-ins/' + $button.attr( 'data-aaos-cx-disconnect' ) )
			.then( function () {
				window.location.reload();
			} )
			.fail( function ( error ) {
				$button.prop( 'disabled', false );
				fail( error.message );
			} );
	} );

	/* ------------------------------------------------------------------
	   A new MCP address for the agent. The old one stops working at once.
	   ------------------------------------------------------------------ */

	$( document ).on( 'click', '[data-aaos-mcp-address-new]', function () {
		const $button = $( this );
		const api = window.aiAgentOsAdmin;

		// eslint-disable-next-line no-alert -- Breaks existing connections; needs an answer first.
		if ( ! window.confirm( $button.attr( 'data-confirm' ) ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		api.post( '/agent/mcp-address', {} )
			.then( function () {
				window.location.reload();
			} )
			.fail( function ( error ) {
				$button.prop( 'disabled', false );
				fail( error.message );
			} );
	} );

	$( document ).on( 'click', '[data-aaos-save-permissions]', function () {
		const $button = $( this );

		if ( ! hasAgent() ) {
			return;
		}

		/*
		 * The button carries its own text now — Z8 replaced `.zui-savebtn` with
		 * the design's plain button, so there is no `__label` span to write to.
		 */
		const previous = $button.text().trim();

		$button.prop( 'disabled', true ).text( settings.i18n.saving );

		api.put( '/agent/permissions', {
			grants: readMatrix(),
		} )
			.then( function ( data ) {
				matrixBaseline = JSON.stringify( readMatrix() );

				/*
				 * Count grants, not rows written.
				 *
				 * `written` is every row the route stored — including each
				 * ability left at None — so granting one ability announced
				 * "Saved 24 permissions" next to a counter reading "0 of 2
				 * granted". Two numbers describing the same save, disagreeing.
				 *
				 * Taken from the response rather than from the form, because
				 * the route drops abilities it does not recognise and the
				 * merchant should be told what actually landed.
				 */
				const grants = ( data && data.grants ) || {};
				const granted = Object.keys( grants ).filter(
					( name ) => 'none' !== grants[ name ]
				).length;

				let message;

				if ( 0 === granted ) {
					message =
						settings.i18n.permissionsNone ||
						'Saved. The agent may not use any abilities.';
				} else if ( 1 === granted ) {
					message =
						settings.i18n.permissionSaved ||
						'Saved. The agent may now use 1 ability.';
				} else {
					message = (
						settings.i18n.permissionsSaved || '%d'
					).replace( '%d', String( granted ) );
				}

				api.say( message, 'success' );

				updateGrantCounts( grants );

				$button.text( previous );
				refreshMatrix();
			} )
			.fail( function ( error ) {
				$button.text( previous ).prop( 'disabled', false );
				fail( error.message );
			} );
	} );

	/* ------------------------------------------------------------------
	   Collapsing sections.

	   A section is open when its toggle says so, except while a search is
	   running — a match inside a closed section has to be reachable, and
	   asking somebody to guess which of nine closed sections holds the
	   thing they just searched for is not a search.

	   So visibility is computed from both inputs in one place rather than
	   each handler setting `hidden` on its own. Two handlers writing the
	   same property from half the state is how a row ends up hidden by the
	   search and then shown by a toggle that knew nothing about it.
	   ------------------------------------------------------------------ */

	function sectionIsOpen( slug ) {
		const toggle = document.querySelector(
			'[data-aaos-matrix-toggle="' + slug + '"]'
		);

		return ! toggle || toggle.getAttribute( 'aria-expanded' ) === 'true';
	}

	/**
	 * Show the rows that both the search and the open sections allow.
	 *
	 * @param {string} term Lower-cased search term, or an empty string.
	 * @return {number} How many ability rows are visible.
	 */
	function applyMatrixVisibility( term ) {
		let shown = 0;

		$matrix()
			.find( '[data-aaos-search]' )
			.each( function () {
				const hit =
					! term ||
					this.getAttribute( 'data-aaos-search' ).indexOf( term ) !==
						-1;

				if ( hit ) {
					shown++;
				}

				// A search reaches into closed sections; browsing does not.
				const open =
					term ||
					sectionIsOpen( this.getAttribute( 'data-aaos-group' ) );

				this.hidden = ! hit || ! open;
			} );

		/*
		 * A group whose every row was filtered out is a heading with nothing
		 * under it, which reads as "this category has no abilities" rather
		 * than "none of them matched".
		 *
		 * The rows are the heading's *siblings*, not its children — this
		 * searched inside the heading row itself, found nothing every time,
		 * and hid every heading the moment anything was typed. The results
		 * still appeared, so it looked like a styling quirk rather than a
		 * selector that could never match.
		 */
		$matrix()
			.find( '[data-aaos-matrix-group]' )
			.each( function () {
				const slug = this.getAttribute( 'data-aaos-group' );

				this.hidden = ! $matrix()
					.find(
						'[data-aaos-search][data-aaos-group="' + slug + '"]'
					)
					.filter( function () {
						return (
							! term ||
							this.getAttribute( 'data-aaos-search' ).indexOf(
								term
							) !== -1
						);
					} ).length;
			} );

		return shown;
	}

	$( document ).on( 'click', '[data-aaos-matrix-toggle]', function () {
		const open = this.getAttribute( 'aria-expanded' ) === 'true';

		this.setAttribute( 'aria-expanded', open ? 'false' : 'true' );

		const $search = $matrix().find( '[data-aaos-matrix-search]' );
		const term = $search.length ? $search.val().trim().toLowerCase() : '';

		applyMatrixVisibility( term );
	} );

	// Client-side search over rows that are already here.
	$( document ).on( 'input', '[data-aaos-matrix-search]', function () {
		const term = this.value.trim().toLowerCase();
		const shown = applyMatrixVisibility( term );

		$matrix().find( '[data-aaos-matrix-empty]' )[ 0 ].hidden = shown > 0;

	} );

	/* ------------------------------------------------------------------ */

	$( function () {
		if ( $matrix().length ) {
			matrixBaseline = JSON.stringify( readMatrix() );
			refreshMatrix();
		}
	} );

} )( jQuery );
