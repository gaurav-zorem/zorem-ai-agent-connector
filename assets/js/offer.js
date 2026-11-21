/**
 * Dismisses the Black Friday admin notice for the current user.
 *
 * Hides it at once, then records the dismissal (user meta) so it stays hidden
 * on every screen. Settings come from `aiAgentOsOffer` (SeasonalOffer::enqueue()).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '[data-aaos-offer-dismiss]' );
		const settings = window.aiAgentOsOffer;

		if ( ! button || ! settings ) {
			return;
		}

		const notice = button.closest( '[data-aaos-offer]' );

		if ( notice ) {
			notice.hidden = true;
		}

		const body = new window.URLSearchParams();
		body.append( 'action', settings.action );
		body.append( 'nonce', settings.nonce );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} );
	} );
} )();
