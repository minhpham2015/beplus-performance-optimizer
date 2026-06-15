/**
 * Beplus Performance Booster — Interaction-based JS delay loader.
 *
 * Defers non-critical scripts until the first user interaction (mousemove,
 * click, scroll, keydown, touch), with a 5-second automatic fallback.
 * Exclude list is injected by PHP via wp_localize_script as bepluspbDelayConfig.
 */
(function () {
	'use strict';

	var _bepluspbExclude = ( typeof bepluspbDelayConfig !== 'undefined' && Array.isArray( bepluspbDelayConfig.skip ) )
		? bepluspbDelayConfig.skip
		: [];
	var _bepluspbLoaded = false;

	function _bepluspbIsExcluded( src ) {
		for ( var i = 0; i < _bepluspbExclude.length; i++ ) {
			if ( _bepluspbExclude[ i ] && src.indexOf( _bepluspbExclude[ i ] ) !== -1 ) return true;
		}
		return false;
	}

	function _bepluspbLoadAll() {
		if ( _bepluspbLoaded ) return;
		_bepluspbLoaded = true;

		var delayed = document.querySelectorAll( 'script[data-bepluspb-delay="1"]' );
		delayed.forEach( function ( placeholder ) {
			var src = placeholder.getAttribute( 'data-bepluspb-src' ) || '';
			if ( src && ! _bepluspbIsExcluded( src ) ) {
				var s   = document.createElement( 'script' );
				s.src   = src;
				s.async = false;
				document.body.appendChild( s );
			}
			if ( placeholder.parentNode ) {
				placeholder.parentNode.removeChild( placeholder );
			}
		} );
	}

	var _bepluspbEvents = [
		'mousemove', 'mousedown',
		'keydown',
		'scroll', 'wheel',
		'touchstart', 'touchmove',
		'click'
	];

	function _bepluspbOnInteraction() {
		_bepluspbLoadAll();
		_bepluspbEvents.forEach( function ( e ) {
			document.removeEventListener( e, _bepluspbOnInteraction, { passive: true } );
		} );
	}

	_bepluspbEvents.forEach( function ( e ) {
		document.addEventListener( e, _bepluspbOnInteraction, { once: true, passive: true } );
	} );

	setTimeout( _bepluspbLoadAll, 5000 );
} )();
