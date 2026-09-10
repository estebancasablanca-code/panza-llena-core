'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const source = fs.readFileSync( path.join( root, 'assets/js/pllc-frontend.js' ), 'utf8' );
const css = fs.readFileSync( path.join( root, 'assets/css/pllc-frontend.css' ), 'utf8' );

function extractFunction( name ) {
	const start = source.indexOf( '\tfunction ' + name + '(' );
	if ( start === -1 ) {
		throw new Error( 'Missing function: ' + name );
	}

	const openingBrace = source.indexOf( '{', start );
	let depth = 0;
	for ( let index = openingBrace; index < source.length; index++ ) {
		if ( source[index] === '{' ) {
			depth++;
		} else if ( source[index] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return source.slice( start, index + 1 );
			}
		}
	}

	throw new Error( 'Could not extract function: ' + name );
}

function check( condition, message ) {
	if ( ! condition ) {
		throw new Error( message );
	}
}

let summaryRemoved = false;
let queryRuns = 0;
let observerCallback = null;

const summary = {
	remove: function () {
		summaryRemoved = true;
	},
};

const group = {
	classList: {
		contains: function ( className ) {
			return className === 'pllc-mini-cart-group';
		},
	},
	querySelectorAll: function ( selector ) {
		return selector === '.pllc-cart-group-summary' ? [ summary ] : [];
	},
};

const item = {
	children: [ group ],
	querySelector: function () { return null; },
	querySelectorAll: function () { return []; },
};

const fakeDocument = {
	body: {},
	querySelectorAll: function () {
		queryRuns++;
		return [ item ];
	},
};

function FakeMutationObserver( callback ) {
	observerCallback = callback;
	this.observe = function ( target, options ) {
		check( target === fakeDocument.body, 'Observe the document body.' );
		check( options.childList === true && options.subtree === true, 'Observe dynamic descendants.' );
	};
}

const fakeWindow = {
	MutationObserver: FakeMutationObserver,
	requestAnimationFrame: function ( callback ) { callback(); },
};

const factory = new Function(
	'document',
	'window',
	'MutationObserver',
	extractFunction( 'arrangeMiniCartContent' )
		+ '\n' + extractFunction( 'observeMiniCartContent' )
		+ '\nreturn { arrange: arrangeMiniCartContent, observe: observeMiniCartContent };'
);
const functions = factory( fakeDocument, fakeWindow, FakeMutationObserver );

functions.arrange();
check( summaryRemoved, 'Remove student form data and observations from the side cart.' );

functions.observe();
check( typeof observerCallback === 'function', 'Register the dynamic Elementor observer.' );
const runsBeforeMutation = queryRuns;
observerCallback( [ {
	target: {
		nodeType: 1,
		closest: function () { return {}; },
	},
} ] );
check( queryRuns === runsBeforeMutation + 1, 'Reprocess the side cart after Elementor changes it.' );

check(
	/\.pllc-mini-cart-group\s*\{[^}]*order:\s*-100;/s.test( css ),
	'Keep the order heading before the product image.'
);

console.log( 'Mini cart DOM checks passed.' );
