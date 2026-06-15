/**
 * Beplus Performance Booster — Native lazy-load polyfill.
 *
 * Activates only when the browser does not support the native `loading` attribute.
 * Uses IntersectionObserver when available; falls back to eager loading otherwise.
 */
(function () {
	'use strict';

	if ( 'loading' in HTMLImageElement.prototype ) {
		return;
	}

	var lazyImgs = [].slice.call( document.querySelectorAll( 'img[loading="lazy"]' ) );
	if ( ! lazyImgs.length ) {
		return;
	}

	function loadImage( img ) {
		if ( img.dataset && img.dataset.src ) {
			img.src = img.dataset.src;
		}
		if ( img.dataset && img.dataset.srcset ) {
			img.srcset = img.dataset.srcset;
		}
		img.removeAttribute( 'loading' );
	}

	if ( 'IntersectionObserver' in window ) {
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}
					loadImage( entry.target );
					observer.unobserve( entry.target );
				} );
			},
			{ rootMargin: '200px 0px', threshold: 0.01 }
		);
		lazyImgs.forEach( function ( img ) {
			observer.observe( img );
		} );
	} else {
		lazyImgs.forEach( loadImage );
	}
} )();
