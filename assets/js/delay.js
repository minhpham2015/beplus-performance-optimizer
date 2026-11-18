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

	// Scripts loaded after the page finished loading miss the events that already fired. Contact Form 7,
	// for example, starts with document.addEventListener( 'DOMContentLoaded', init ): when it runs late
	// that handler never fires, the form is not initialised and a submit reloads the page. While the
	// delayed scripts load, listeners for the events that have already happened are held back and called
	// once, in order, after every script has run (the same idea as WP Meteor's event queue).
	var _bepluspbLate = false;
	var _bepluspbLateQueue = [];
	var _bepluspbLateRank = { DOMContentLoaded: 0, readystatechange: 1, load: 2, pageshow: 3 };

	function _bepluspbAlreadyFired( type ) {
		if ( type === 'DOMContentLoaded' ) return document.readyState !== 'loading';
		return document.readyState === 'complete'; // readystatechange (complete), load, pageshow.
	}

	function _bepluspbHoldLate( target, types ) {
		var original = target.addEventListener;
		target.addEventListener = function ( type, listener ) {
			if ( _bepluspbLate && listener && types.indexOf( type ) !== -1 && _bepluspbAlreadyFired( type ) ) {
				_bepluspbLateQueue.push( { target: target, type: type, listener: listener, order: _bepluspbLateQueue.length } );
				return;
			}
			return original.apply( this, arguments );
		};
	}

	function _bepluspbLateEvent( item ) {
		var event;
		try {
			event = item.type === 'pageshow' && typeof PageTransitionEvent === 'function'
				? new PageTransitionEvent( 'pageshow', { persisted: false } )
				: new Event( item.type, { bubbles: item.type === 'DOMContentLoaded' } );
			Object.defineProperty( event, 'target', { value: item.target } );
			Object.defineProperty( event, 'currentTarget', { value: item.target } );
		} catch ( err ) {
			event = { type: item.type, target: item.target, currentTarget: item.target };
		}
		return event;
	}

	function _bepluspbReplayLate() {
		if ( ! _bepluspbLate ) return;
		_bepluspbLate = false;
		delete document.addEventListener;
		delete window.addEventListener;
		var queue = _bepluspbLateQueue;
		_bepluspbLateQueue = [];
		queue.sort( function ( a, b ) {
			return ( _bepluspbLateRank[ a.type ] - _bepluspbLateRank[ b.type ] ) || ( a.order - b.order );
		} );
		queue.forEach( function ( item ) {
			try {
				var event = _bepluspbLateEvent( item );
				if ( typeof item.listener === 'function' ) {
					item.listener.call( item.target, event );
				} else if ( item.listener && typeof item.listener.handleEvent === 'function' ) {
					item.listener.handleEvent( event );
				}
			} catch ( err ) {
				if ( window.console && console.error ) console.error( err );
			}
		} );
	}

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
	// form.submit / form.requestSubmit can be shadowed by a field named "submit"
	// (the WordPress comment form has one), so always call the prototype methods.
	function _bepluspbNativeSubmit( form ) {
		HTMLFormElement.prototype.submit.call( form );
	}

	function _bepluspbFlushForms() {
		if ( _bepluspbReady ) return;
		_bepluspbReady = true;
		_bepluspbReplayLate(); // Before any held form is submitted again, so the form's own JS is ready.
		var forms = _bepluspbPendingForms;
		_bepluspbPendingForms = [];
		forms.forEach( function ( item ) {
			setTimeout( function () {
				var proto = HTMLFormElement.prototype;
				try {
					if ( typeof proto.requestSubmit === 'function' ) {
						if ( item.submitter ) {
							proto.requestSubmit.call( item.form, item.submitter );
						} else {
							proto.requestSubmit.call( item.form );
						}
					} else {
						_bepluspbNativeSubmit( item.form );
					}
				} catch ( err ) {
					_bepluspbNativeSubmit( item.form );
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
		// A second click while the form is held must not send it twice.
		var held = false;
		for ( var i = 0; i < _bepluspbPendingForms.length; i++ ) {
			if ( _bepluspbPendingForms[ i ].form === event.target ) {
				_bepluspbPendingForms[ i ].submitter = event.submitter;
				held = true;
			}
		}
		if ( ! held ) {
			_bepluspbPendingForms.push( { form: event.target, submitter: event.submitter } );
		}
		_bepluspbLoadAll();
	}, true );

	function _bepluspbLoadAll() {
		if ( _bepluspbLoaded ) return;
		_bepluspbLoaded = true;

		_bepluspbLate = true;
		_bepluspbHoldLate( document, [ 'DOMContentLoaded', 'readystatechange' ] );
		_bepluspbHoldLate( window, [ 'DOMContentLoaded', 'load', 'pageshow' ] );

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
