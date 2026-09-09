( function () {
	'use strict';

	var data = window.PLLC_Tour_Data || {};
	var steps = [];
	var current = 0;
	var overlay;
	var dialog;
	var activeTarget;
	var targetStyle = null;

	function isVisible( element ) {
		if ( ! element ) {
			return false;
		}
		var style = window.getComputedStyle( element );
		var rect = element.getBoundingClientRect();
		return style.display !== 'none' && style.visibility !== 'hidden' && ! element.hidden && rect.width > 0 && rect.height > 0;
	}

	function firstVisible( selector ) {
		var found = [];
		try {
			found = document.querySelectorAll( selector );
		} catch ( error ) {
			return null;
		}
		for ( var i = 0; i < found.length; i++ ) {
			if ( isVisible( found[ i ] ) ) {
				return found[ i ];
			}
		}
		return null;
	}

	function availableSteps() {
		return ( data.steps || [] ).map( function ( step ) {
			var target = firstVisible( step.selector );
			if ( ! target ) {
				return null;
			}
			return {
				target: target,
				title: step.title || '',
				text: step.text || ''
			};
		} ).filter( Boolean );
	}

	function createUi() {
		if ( overlay ) {
			return;
		}

		overlay = document.createElement( 'div' );
		overlay.className = 'pllc-tour-overlay';
		overlay.setAttribute( 'aria-hidden', 'true' );

		dialog = document.createElement( 'section' );
		dialog.className = 'pllc-tour-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'pllc-tour-title' );
		dialog.innerHTML =
			'<button type="button" class="pllc-tour-close" aria-label="' + ( data.labels.close || 'Cerrar' ) + '">×</button>' +
			'<div class="pllc-tour-progress"></div>' +
			'<h3 id="pllc-tour-title"></h3>' +
			'<p class="pllc-tour-text"></p>' +
			'<div class="pllc-tour-actions">' +
				'<button type="button" class="pllc-tour-skip"></button>' +
				'<div class="pllc-tour-nav">' +
					'<button type="button" class="pllc-tour-previous"></button>' +
					'<button type="button" class="pllc-tour-next"></button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( overlay );
		document.body.appendChild( dialog );

		dialog.querySelector( '.pllc-tour-close' ).addEventListener( 'click', finish );
		dialog.querySelector( '.pllc-tour-skip' ).addEventListener( 'click', finish );
		dialog.querySelector( '.pllc-tour-previous' ).addEventListener( 'click', previous );
		dialog.querySelector( '.pllc-tour-next' ).addEventListener( 'click', next );
	}

	function highlight( target ) {
		clearHighlight();
		activeTarget = target;
		targetStyle = {
			position: target.style.position,
			zIndex: target.style.zIndex
		};
		if ( window.getComputedStyle( target ).position === 'static' ) {
			target.style.position = 'relative';
		}
		target.style.zIndex = '100002';
		target.classList.add( 'pllc-tour-highlight' );
	}

	function clearHighlight() {
		if ( ! activeTarget ) {
			return;
		}
		activeTarget.classList.remove( 'pllc-tour-highlight' );
		activeTarget.style.position = targetStyle.position;
		activeTarget.style.zIndex = targetStyle.zIndex;
		activeTarget = null;
		targetStyle = null;
	}

	function placeDialog() {
		if ( ! activeTarget || ! dialog ) {
			return;
		}

		var rect = activeTarget.getBoundingClientRect();
		var dialogRect = dialog.getBoundingClientRect();
		var gap = 16;
		var left = Math.max( 12, Math.min( rect.left, window.innerWidth - dialogRect.width - 12 ) );
		var top = rect.bottom + gap;

		if ( top + dialogRect.height > window.innerHeight - 12 ) {
			top = rect.top - dialogRect.height - gap;
		}
		if ( top < 12 || window.innerWidth <= 700 ) {
			top = Math.max( 12, window.innerHeight - dialogRect.height - 12 );
			left = 12;
		}

		dialog.style.left = left + 'px';
		dialog.style.top = top + 'px';
	}

	function show( index ) {
		if ( ! steps.length ) {
			return;
		}
		current = Math.max( 0, Math.min( index, steps.length - 1 ) );
		var step = steps[ current ];

		highlight( step.target );
		step.target.scrollIntoView( { behavior: 'smooth', block: 'center', inline: 'nearest' } );

		dialog.querySelector( '.pllc-tour-progress' ).textContent = 'Paso ' + ( current + 1 ) + ' de ' + steps.length;
		dialog.querySelector( '#pllc-tour-title' ).textContent = step.title;
		dialog.querySelector( '.pllc-tour-text' ).textContent = step.text;
		dialog.querySelector( '.pllc-tour-skip' ).textContent = data.labels.skip || 'Omitir tour';

		var previousButton = dialog.querySelector( '.pllc-tour-previous' );
		previousButton.textContent = data.labels.previous || 'Anterior';
		previousButton.hidden = current === 0;

		var nextButton = dialog.querySelector( '.pllc-tour-next' );
		nextButton.textContent = current === steps.length - 1 ? ( data.labels.finish || 'Finalizar' ) : ( data.labels.next || 'Siguiente' );

		window.setTimeout( placeDialog, 360 );
	}

	function start() {
		steps = availableSteps();
		if ( ! steps.length ) {
			return;
		}
		createUi();
		document.body.classList.add( 'pllc-tour-open' );
		overlay.hidden = false;
		dialog.hidden = false;
		show( 0 );
	}

	function markSeen() {
		try {
			window.localStorage.setItem( data.storage_key, '1' );
		} catch ( error ) {}
	}

	function finish() {
		markSeen();
		clearHighlight();
		document.body.classList.remove( 'pllc-tour-open' );
		if ( overlay ) {
			overlay.hidden = true;
		}
		if ( dialog ) {
			dialog.hidden = true;
		}
	}

	function next() {
		if ( current >= steps.length - 1 ) {
			finish();
			return;
		}
		show( current + 1 );
	}

	function previous() {
		show( current - 1 );
	}

	function wasSeen() {
		try {
			return window.localStorage.getItem( data.storage_key ) === '1';
		} catch ( error ) {
			return false;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-pllc-tour-start]' ).forEach( function ( button ) {
			button.addEventListener( 'click', start );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! document.body.classList.contains( 'pllc-tour-open' ) ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				finish();
			} else if ( event.key === 'ArrowRight' ) {
				next();
			} else if ( event.key === 'ArrowLeft' ) {
				previous();
			}
		} );

		window.addEventListener( 'resize', placeDialog );
		window.addEventListener( 'scroll', placeDialog, { passive: true } );

		if ( data.auto_start && ! wasSeen() ) {
			window.setTimeout( start, 900 );
		}
	} );
} )();
