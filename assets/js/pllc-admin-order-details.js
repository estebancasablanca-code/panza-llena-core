( function () {
	'use strict';

	function initializeMixedOrderLayout() {
		var panel = document.getElementById( 'pllc-admin-mixed-order-panel' );
		var orderData = document.getElementById( 'order_data' );
		if ( ! panel || ! orderData || panel.dataset.pllcReady === '1' ) {
			return;
		}

		var originalContainer = orderData.querySelector( '.order_data_column_container' );
		if ( ! originalContainer ) {
			return;
		}

		var columns = originalContainer.querySelectorAll( ':scope > .order_data_column' );
		if ( columns.length < 3 ) {
			return;
		}

		var generalColumn = columns[0];
		var billingColumn = columns[1];
		var shippingColumn = columns[2];
		var nativeEditor = document.createElement( 'div' );
		var toggle = panel.querySelector( '.pllc-toggle-native-order-data' );

		originalContainer.classList.add( 'pllc-admin-mixed-order-shell' );
		generalColumn.classList.add( 'pllc-admin-mixed-order-general' );
		nativeEditor.className = 'pllc-admin-native-order-data';
		nativeEditor.hidden = true;

		originalContainer.appendChild( panel );
		panel.appendChild( nativeEditor );
		nativeEditor.appendChild( billingColumn );
		nativeEditor.appendChild( shippingColumn );
		panel.dataset.pllcReady = '1';

		if ( toggle ) {
			var openLabel = toggle.dataset.openLabel || 'Editar facturación y envío';
			var closeLabel = toggle.dataset.closeLabel || 'Ocultar edición de facturación y envío';
			toggle.addEventListener( 'click', function () {
				var willOpen = nativeEditor.hidden;
				nativeEditor.hidden = ! willOpen;
				toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
				toggle.textContent = willOpen ? closeLabel : openLabel;
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initializeMixedOrderLayout );
	} else {
		initializeMixedOrderLayout();
	}
}() );
