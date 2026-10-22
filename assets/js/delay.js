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
	var _bepluspbReady = false;
	var _bepluspbPendingForms = [];
	var _bepluspbRemaining = 0;

	function _bepluspbIsExcluded( src ) {
		for ( var i = 0; i < _bepluspbExclude.length; i++ ) {
			if ( _bepluspbExclude[ i ] && src.indexOf( _bepluspbExclude[ i ] ) !== -1 ) return true;
		}
		return false;
	}

	var _bepluspbCopyAttrs = [ 'integrity', 'crossorigin', 'nonce', 'referrerpolicy' ];

	// Forms submitted while the delayed scripts are still loading would be sent as
	// a plain page-reload POST (their AJAX handlers are not attached yet). Hold the
	// submit and replay it through requestSubmit() once every script has run, so the
	// form's own JS handles it exactly as if the scripts had been there all along.
	function _bepluspbFlushForms() {
		if ( _bepluspbReady ) return;
		_bepluspbReady = true;
		var forms = _bepluspbPendingForms;
		_bepluspbPendingForms = [];
		forms.forEach( function ( item ) {
			setTimeout( function () {
				try {
					if ( typeof item.form.requestSubmit === 'function' ) {
						item.form.requestSubmit( item.submitter || undefined );
					} else {
						item.form.submit();
					}
				} catch ( err ) {
					item.form.submit();
				}
			}, 0 );
		} );
	}

	function _bepluspbScriptSettled() {
		_bepluspbRemaining--;
		if ( _bepluspbRemaining <= 0 ) _bepluspbFlushForms();
	}

	document.addEventListener( 'submit', function ( event ) {
		if ( _bepluspbReady ) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		_bepluspbPendingForms.push( { form: event.target, submitter: event.submitter } );
		_bepluspbLoadAll();
	}, true );

	function _bepluspbLoadAll() {
		if ( _bepluspbLoaded ) return;
		_bepluspbLoaded = true;

		var delayed = document.querySelectorAll( 'script[data-bepluspb-delay="1"]' );
		delayed.forEach( function ( placeholder ) {
			var src  = placeholder.getAttribute( 'data-bepluspb-src' ) || '';
			var type = placeholder.getAttribute( 'data-bepluspb-type' );
			if ( src && ! _bepluspbIsExcluded( src ) ) {
				var s = document.createElement( 'script' );
				if ( type ) {
					s.type = type;
				}
				_bepluspbCopyAttrs.forEach( function ( attr ) {
					var value = placeholder.getAttribute( attr );
					if ( value !== null ) {
						s.setAttribute( attr, value );
					}
				} );
				s.src   = src;
				s.async = false;
				_bepluspbRemaining++;
				s.addEventListener( 'load', _bepluspbScriptSettled );
				s.addEventListener( 'error', _bepluspbScriptSettled );
				document.body.appendChild( s );
			}
			if ( placeholder.parentNode ) {
				placeholder.parentNode.removeChild( placeholder );
			}
		} );

		if ( _bepluspbRemaining <= 0 ) {
			_bepluspbFlushForms();
		} else {
			// Never keep a held form waiting on a stalled script.
			setTimeout( _bepluspbFlushForms, 8000 );
		}
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
