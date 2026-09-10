/**
 * Panza Llena - Frontend
 *
 * 1) Oculta el contenedor de un día (título + Loop Grid) cuando ese Loop
 *    Grid no tiene ningún producto.
 * 2) Lee la selección dentro de cada card de producto (Loop Item):
 *    variante -radio real- (Colegios), checkbox real (ITEO Personal),
 *    cantidad -input number real- (ITEO Pacientes).
 * 3) Maneja el toggle del botón "Agregar al pedido" / "Eliminar" (y
 *    resetea la selección de esa card al eliminar), y la exclusión mutua
 *    en Colegios (agregar un producto bloquea todos los radios y el resto
 *    de los botones "Agregar al pedido" del mismo día).
 * 4) Cascada Colegio → Nivel → Curso en el formulario de Colegios.
 * 5) Recolecta todo lo marcado como "agregado" + los datos del formulario,
 *    y lo manda por AJAX a WooCommerce (PLLC_Cart, en el PHP) al hacer
 *    clic en "Agregar al carrito".
 *
 * Los radios/checkboxes/inputs los generan los shortcodes del plugin
 * (class-pllc-shortcodes.php) — acá solo leemos su estado nativo del
 * navegador.
 *
 * El estado de cada card queda en sus propios data-attributes
 * (data-pllc-added, data-pllc-variant, data-pllc-variation-id), así el
 * envío final simplemente recorre el DOM y lee qué quedó marcado.
 */
( function () {
	var STUDENT_RESTORE_KEY = 'pllc_restore_student_after_reload';
	var FORM_RESET_KEY      = 'pllc_reset_form_after_submit';
	var CART_EVENT_KEY      = 'pllc_emit_added_to_cart_after_reload';

	function rememberCartEventAfterReload( data, eventName ) {
		try {
			sessionStorage.setItem( CART_EVENT_KEY, JSON.stringify( {
				fragments: data && data.fragments ? data.fragments : {},
				cart_hash: data && data.cart_hash ? data.cart_hash : '',
				event_name: eventName || 'added_to_cart'
			} ) );
		} catch ( error ) {}
	}

	function emitRememberedCartEvent() {
		var eventData = null;
		try {
			eventData = JSON.parse( sessionStorage.getItem( CART_EVENT_KEY ) || 'null' );
		} catch ( error ) {}
		if ( ! eventData ) {
			return;
		}

		var startedAt = Date.now();
		var scheduled = false;
		var waitForElementor = window.setInterval( function () {
			if ( scheduled ) {
				return;
			}
			if ( window.jQuery && window.elementorFrontend && window.elementorProFrontend ) {
				scheduled = true;
				window.clearInterval( waitForElementor );

				// El handler del widget se carga en un chunk diferido después del
				// frontend general de Elementor. Este margen permite que bindEvents()
				// alcance a suscribirse a `added_to_cart` antes de emitirlo.
				window.setTimeout( function () {
					try {
						sessionStorage.removeItem( CART_EVENT_KEY );
					} catch ( error ) {}
					window.jQuery( document.body ).trigger( eventData.event_name || 'added_to_cart', [
						eventData.fragments || {},
						eventData.cart_hash || '',
						window.jQuery()
					] );
				}, 750 );
				return;
			}

			// No borra la señal: si el optimizador demora aún más los scripts,
			// queda disponible para el próximo ingreso o recarga de la página.
			if ( Date.now() - startedAt > 15000 ) {
				window.clearInterval( waitForElementor );
			}
		}, 100 );
	}

	var ESTRUCTURA_POR_COLEGIO = {
		'Plaza Mayor': {
			'Jardín': [
			'Sala de 1', 'Sala de 2', 'Sala de 3', 'Sala de 4', 'Sala de 5'
			],
			'Primaria': [
			'1er grado A', '1er grado B', '1er grado C',
			'2do grado A', '2do grado B', '2do grado C',
			'3er grado A', '3er grado B', '3er grado C',
			'4to grado A', '4to grado B', '4to grado C',
			'5to grado A', '5to grado B',
			'6to grado A', '6to grado B'
			],
			'Secundaria': [
			'1er año A', '1er año B',
			'2do año A', '2do Año B',
			'3er año A', '3er año B',
			'4to año', '5to año', '6to año'
			]
		},
		'Michelangelo': {
			'Secundaria': [ '1er año', '2do año', '3er año' ]
		},
		'CAE': {
			'Primaria': [
				'1er grado', '2do grado', '3er grado', '4to grado',
				'5to grado', '6to grado', '7mo grado'
			],
			'Secundaria': [
				'1er año', '2do año', '3er año', '4to año', '5to año'
			]
		}
	};

	function closestCard( el ) {
		return el.closest( '.e-loop-item' );
	}

	function closestGrid( el ) {
		return el.closest( '.elementor-widget-loop-grid' );
	}

	function getLoopItemProductId( card ) {
		if ( ! card ) {
			return null;
		}
		var match = card.className.match( /(?:^|\s)e-loop-item-(\d+)(?:\s|$)/ );
		if ( ! match ) {
			match = card.className.match( /(?:^|\s)post-(\d+)(?:\s|$)/ );
		}
		return match ? match[1] : null;
	}

	function normalizeDaySlug( value ) {
		value = ( value || '' ).trim().toLowerCase();
		var aliases = {
			lunes: 'lunes', martes: 'martes', miercoles: 'miercoles',
			'miércoles': 'miercoles', jueves: 'jueves', viernes: 'viernes',
			sabado: 'sabado', 'sábado': 'sabado'
		};
		return aliases[ value.split( /\s+/ )[0] ] || '';
	}

	function getDeliveryDate( day ) {
		return window.PLLC_Data && PLLC_Data.day_dates ? ( PLLC_Data.day_dates[ day ] || '' ) : '';
	}

	function getCurrentFormType() {
		var form = document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type]' );
		if ( form ) {
			return form.dataset.pllcFormType || '';
		}
		if ( document.body.classList.contains( 'pllc-page-particulares' ) || document.body.classList.contains( 'pllc-page-home' ) ) {
			return 'particular';
		}
		return '';
	}

	/** Adopta únicamente el estado que PHP reconstruyó desde el carrito real. */
	function acceptFrontendState( data ) {
		if ( ! data || ! data.frontend_state || ! window.PLLC_Data ) {
			return null;
		}
		var state = data.frontend_state;
		PLLC_Data.cart_state = state.cart_state || {};
		PLLC_Data.current_form = state.current_form || {};
		PLLC_Data.students = Array.isArray( state.students ) ? state.students : [];
		PLLC_Data.has_active_order = !! state.has_active_order;
		return state;
	}

	function getWrapperDay( wrapper ) {
		if ( ! wrapper ) {
			return '';
		}

		// El encabezado es la referencia visible y evita depender de que todas
		// las páginas de Elementor tengan clases manuales idénticas.
		var heading = wrapper.querySelector( '.elementor-heading-title' );
		var headingDay = heading ? normalizeDaySlug( heading.textContent ) : '';
		if ( headingDay ) {
			return headingDay;
		}

		var classMatch = wrapper.className.match( /(?:^|\s)pllc-day-(lunes|martes|miercoles|jueves|viernes|sabado)(?:\s|$)/ );
		return classMatch ? classMatch[1] : '';
	}

	function ensureOperationId( holder ) {
		if ( holder && holder.dataset.pllcOperationId ) {
			return holder.dataset.pllcOperationId;
		}
		var id = window.crypto && typeof window.crypto.randomUUID === 'function'
			? window.crypto.randomUUID()
			: 'pllc-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
		if ( holder ) {
			holder.dataset.pllcOperationId = id;
		}
		return id;
	}

	function getCardDay( card ) {
		if ( ! card ) {
			return '';
		}
		var wrapper = card.closest( '.pllc-day-wrapper' );
		if ( wrapper ) {
			var wrapperDay = getWrapperDay( wrapper );
			if ( wrapperDay ) {
				return wrapperDay;
			}
		}

		var panel = card.closest( '[role="tabpanel"][aria-labelledby]' );
		var title = panel ? document.getElementById( panel.getAttribute( 'aria-labelledby' ) ) : null;
		return title ? normalizeDaySlug( title.textContent ) : '';
	}

	function getProductId( card ) {
		// El ID de la clase del Loop Item es el contexto real de Elementor.
		// El widget "Añadir al carrito" puede repetir en todas las cards el ID
		// del producto utilizado para editar la plantilla.
		var loopProductId = getLoopItemProductId( card );
		if ( loopProductId ) {
			return loopProductId;
		}
		var button = card.querySelector( '.add_to_cart_button, .single_add_to_cart_button' );
		if ( button ) {
			var buttonId = button.getAttribute( 'data-product_id' ) || button.value;
			if ( buttonId ) {
				return String( buttonId );
			}
		}
		var hiddenId = card.querySelector( 'input[name="add-to-cart"]' );
		if ( hiddenId && hiddenId.value ) {
			return String( hiddenId.value );
		}
		var holder = card.querySelector( '[data-product-id]' );
		return holder && holder.dataset.productId ? holder.dataset.productId : null;
	}

	/** Corrige el contexto estático que Elementor imprime en el widget nativo. */
	function normalizeLoopAddToCartWidgets() {
		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var productId = getLoopItemProductId( card );
			if ( ! productId ) {
				return;
			}
			card.querySelectorAll( 'form.cart' ).forEach( function ( form ) {
				form.setAttribute( 'action', window.location.href );
				var day = getCardDay( card );
					var dayInput = form.querySelector( 'input[name="pllc_day"]' );
					var dateInput = form.querySelector( 'input[name="pllc_delivery_date"]' );
				if ( day && ! dayInput ) {
					dayInput = document.createElement( 'input' );
					dayInput.type = 'hidden';
					dayInput.name = 'pllc_day';
					form.appendChild( dayInput );
				}
					if ( dayInput ) {
						dayInput.value = day;
					}
					if ( day && ! dateInput ) {
						dateInput = document.createElement( 'input' );
						dateInput.type = 'hidden';
						dateInput.name = 'pllc_delivery_date';
						form.appendChild( dateInput );
					}
					if ( dateInput ) {
						dateInput.value = getDeliveryDate( day );
					}
				form.querySelectorAll( 'button[name="add-to-cart"], .add_to_cart_button, .single_add_to_cart_button' ).forEach( function ( button ) {
					button.value = productId;
					button.setAttribute( 'value', productId );
					button.setAttribute( 'data-product_id', productId );
				} );
			} );
		} );
	}

	/**
	 * Determina el recorrido por el control real que contiene cada tarjeta.
	 * Esto evita que un atributo copiado en Elementor (por ejemplo "variant"
	 * dentro de ITEO Personal) envíe el clic a la lógica de otro formulario.
	 */
	function getProductCardMode( card, button ) {
		if ( card ) {
			if ( card.querySelector( '[data-pllc-role="check-group"], [data-pllc-role="check"]' ) ) {
				return 'checkbox';
			}
			if ( card.querySelector( '[data-pllc-role="variant-group"], [data-pllc-role="variant"]' ) ) {
				return 'variant';
			}
			if ( card.querySelector( '[data-pllc-role="qty-group"], [data-pllc-role="qty-value"]' ) ) {
				return 'quantity';
			}
		}
		return button && button.dataset.pllcMode ? button.dataset.pllcMode : '';
	}

	/** Sincroniza el atributo que puede venir configurado en el widget. */
	function normalizeProductCardModes() {
		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var button = card.querySelector( '[data-pllc-role="add-btn"]' );
			if ( ! button ) {
				return;
			}
			var mode = getProductCardMode( card, button );
			if ( mode ) {
				button.dataset.pllcMode = mode;
			}
		} );
	}

	/**
	 * En ITEO Pacientes el widget nativo de Elementor funciona como selector
	 * del pedido: no envía su form a WooCommerce hasta pulsar el botón general.
	 */
	function initIteoPatientNativeWidgets() {
		if ( ! document.body.classList.contains( 'pllc-page-iteo-pacientes' ) ) {
			return;
		}
		document.querySelectorAll( '.e-loop-item form.cart' ).forEach( function ( form ) {
			var card = closestCard( form );
			var input = form.querySelector( '.quantity input.qty, input[name="quantity"]' );
			var button = form.querySelector( '.single_add_to_cart_button, .add_to_cart_button' );
			if ( ! card || ! input || ! button ) {
				return;
			}
			input.setAttribute( 'data-pllc-role', 'qty-value' );
			input.setAttribute( 'min', '0' );
			input.value = '0';
			button.type = 'button';
			button.removeAttribute( 'name' );
			button.setAttribute( 'data-pllc-role', 'add-btn' );
			button.setAttribute( 'data-pllc-mode', 'quantity' );
			setProductButtonState( button, 'Agregar', 'disabled' );
		} );
	}

	function getParticularOrderForm() {
		return document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type="particular"]' );
	}

	/**
	 * En la tienda Particular, los controles nativos de Elementor pasan a ser
	 * selectores locales. WooCommerce se actualiza recién desde el botón general
	 * del formulario, junto con las observaciones para la cocina.
	 */
	function initParticularBatchWidgets() {
		if ( ! getParticularOrderForm() || document.body.classList.contains( 'pllc-page-particular-product' ) ) {
			return;
		}

		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var elements = getParticularQuantityElements( card );
			if ( ! elements.input || ! elements.button ) {
				return;
			}

			elements.input.setAttribute( 'data-pllc-role', 'qty-value' );
			elements.input.setAttribute( 'min', '0' );
			elements.input.value = '0';
			elements.button.type = 'button';
			elements.button.removeAttribute( 'name' );
			elements.button.setAttribute( 'data-pllc-role', 'add-btn' );
			elements.button.setAttribute( 'data-pllc-mode', 'quantity' );
			elements.button.classList.add( 'pllc-particular-cart-button' );
			setProductButtonState( elements.button, 'Agregar', 'disabled' );

			var nativeForm = elements.button.closest( 'form.cart' );
			if ( nativeForm ) {
				nativeForm.addEventListener( 'submit', function ( event ) {
					event.preventDefault();
					event.stopImmediatePropagation();
				}, true );
			}
		} );
	}

	/**
	 * Elementor coloca data-pllc-role en el contenedor del widget. Cambiar el
	 * textContent de ese contenedor elimina el enlace y el span internos, por lo
	 * que se pierden tanto el aspecto como el comportamiento del botón. Actualiza
	 * solamente la etiqueta cuando existe y conserva intacto el markup.
	 */
	function setButtonLabel( button, label ) {
		if ( ! button ) {
			return;
		}
		var labelNode = button.querySelector( '.elementor-button-text' );
		if ( labelNode ) {
			labelNode.textContent = label;
			return;
		}
		button.textContent = label;
	}

	/**
	 * Aplica un estado visual exclusivo. Nunca deja clases de un estado
	 * anterior, aunque el usuario cambie varias veces la selección.
	 */
	function setProductButtonState( button, label, state ) {
		if ( ! button ) {
			return;
		}
		setButtonLabel( button, label );
		button.classList.remove( 'pllc-added', 'pllc-disabled', 'pllc-update-pending' );
		button.removeAttribute( 'aria-disabled' );

		if ( 'added' === state ) {
			button.classList.add( 'pllc-added' );
		} else if ( 'pending' === state ) {
			button.classList.add( 'pllc-update-pending' );
		} else if ( 'disabled' === state ) {
			button.classList.add( 'pllc-disabled' );
			button.setAttribute( 'aria-disabled', 'true' );
		}
	}

	function getParticularQuantityElements( card ) {
		return {
			input: card.querySelector( '.quantity input.qty, input[name="quantity"]' ),
			button: card.querySelector( '.add_to_cart_button, .single_add_to_cart_button' )
		};
	}

	function getBaseAddLabel( button ) {
		return ( document.body.classList.contains( 'pllc-page-iteo-pacientes' ) || !! getParticularOrderForm() )
			&& button && button.dataset.pllcMode === 'quantity'
			? 'Agregar'
			: 'Agregar al pedido';
	}

	function refreshParticularQuantityButton( card ) {
		if ( ! card || ! card.dataset.pllcParticularCartKey ) {
			return;
		}
		var elements = getParticularQuantityElements( card );
		if ( ! elements.input || ! elements.button ) {
			return;
		}
		var existing = parseInt( card.dataset.pllcParticularExistingQty || '1', 10 );
		var current  = parseInt( elements.input.value || '1', 10 );
		current = isNaN( current ) ? 1 : Math.max( 1, current );
		elements.input.value = current;
		elements.button.classList.add( 'pllc-particular-cart-button' );

		if ( current !== existing ) {
			card.dataset.pllcParticularUpdate = 'pending';
			setProductButtonState( elements.button, 'Actualizar', 'pending' );
		} else {
			delete card.dataset.pllcParticularUpdate;
			setProductButtonState( elements.button, 'Eliminar', 'added' );
		}
	}

	function getParticularObservationsField() {
		return document.querySelector( '[data-pllc-role="particular-observations-field"]' );
	}

	function appendParticularObservations( payload ) {
		var field = getParticularObservationsField();
		if ( field ) {
			payload.append( 'observaciones', field.value || '' );
		}
	}

	function initParticularCartState() {
		if ( getParticularOrderForm() ) {
			return;
		}
		if ( ! document.body.classList.contains( 'pllc-page-particulares' ) && ! document.body.classList.contains( 'pllc-page-home' ) ) {
			return;
		}
		var state = window.PLLC_Data && PLLC_Data.cart_state ? PLLC_Data.cart_state : {};
		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var elements = getParticularQuantityElements( card );
			if ( ! elements.input || ! elements.button ) {
				return;
			}
			elements.button.classList.add( 'pllc-particular-cart-button' );
			elements.input.value = 1;
			var productId = getProductId( card );
			var productState = productId ? state[ productId ] : null;
			var day = getCardDay( card );
			var slots = productState && day ? ( productState._days ? productState._days[ day ] : null ) : productState;
			if ( ! slots || ! slots._default ) {
				return;
			}
			var quantity = parseInt( slots._default_qty, 10 ) || 1;
			card.dataset.pllcParticularCartKey = slots._default;
			card.dataset.pllcParticularExistingQty = quantity;
			elements.input.value = quantity;
			refreshParticularQuantityButton( card );
		} );
	}

	function handleParticularQuantityUpdateClick( event ) {
		var button = event.target.closest( '.add_to_cart_button, .single_add_to_cart_button' );
		var card = button ? closestCard( button ) : null;
		var isParticularListing = document.body.classList.contains( 'pllc-page-particulares' )
			|| document.body.classList.contains( 'pllc-page-home' )
			|| !! getParticularOrderForm();
		if ( ! card || ! isParticularListing ) {
			return;
		}
		if ( getParticularOrderForm() ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			handleAddClick( button );
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		if ( card.dataset.pllcParticularCartKey && card.dataset.pllcParticularUpdate !== 'pending' ) {
			removeFromCart( [ card.dataset.pllcParticularCartKey ], button );
			return;
		}

		var elements = getParticularQuantityElements( card );
		var quantity = elements.input ? Math.max( 1, parseInt( elements.input.value || '1', 10 ) || 1 ) : 1;
		var payload = new FormData();
		var updating = !! card.dataset.pllcParticularCartKey;
			payload.append( 'action', updating ? 'pllc_update_particular_quantity' : 'pllc_add_particular_quantity' );
			payload.append( 'nonce', PLLC_Data.nonce );
			payload.append( 'quantity', quantity );
			payload.append( 'operation_id', ensureOperationId( card ) );
			appendParticularObservations( payload );
		if ( updating ) {
			payload.append( 'cart_item_key', card.dataset.pllcParticularCartKey );
			} else {
				payload.append( 'product_id', getProductId( card ) );
				payload.append( 'pllc_day', getCardDay( card ) );
				payload.append( 'pllc_delivery_date', getDeliveryDate( getCardDay( card ) ) );
			}

		// También cubre tarjetas insertadas por pestañas/carruseles después de iniciar.
		button.classList.add( 'pllc-particular-cart-button' );
		button.disabled = true;
		setButtonLabel( button, updating ? 'Actualizando…' : 'Agregando…' );

		fetch( PLLC_Data.ajax_url, { method: 'POST', credentials: 'same-origin', body: payload } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) {
				if ( response && response.success ) {
					rememberCartEventAfterReload( response.data );
					window.location.reload();
					return;
				}
				button.disabled = false;
				if ( card.dataset.pllcParticularCartKey ) {
					refreshParticularQuantityButton( card );
				} else {
					setProductButtonState( button, 'Agregar', 'base' );
				}
				alert( response && response.data && response.data.message ? response.data.message : 'No se pudo actualizar la cantidad.' );
			} )
			.catch( function () {
				button.disabled = false;
				if ( card.dataset.pllcParticularCartKey ) {
					refreshParticularQuantityButton( card );
				} else {
					setProductButtonState( button, 'Agregar', 'base' );
				}
				alert( 'Error de conexión, probá de nuevo.' );
			} );
	}

	function initParticularProductPage() {
		var selector = document.querySelector( '[data-pllc-role="particular-day-selector"]' );
		var form = document.querySelector( 'form.cart' );
		if ( ! selector || ! form || ! document.body.classList.contains( 'pllc-page-particular-product' ) || getParticularOrderForm() ) {
			return;
		}

		var productId = selector.dataset.productId || '';
		var input = form.querySelector( '.quantity input.qty, input[name="quantity"]' );
		var button = form.querySelector( '.single_add_to_cart_button, .add_to_cart_button' );
		if ( ! productId || ! input || ! button ) {
			return;
		}

		button.classList.add( 'pllc-particular-cart-button' );
		var dayInput = form.querySelector( 'input[name="pllc_day"]' );
		var dateInput = form.querySelector( 'input[name="pllc_delivery_date"]' );
		if ( ! dayInput ) {
			dayInput = document.createElement( 'input' );
			dayInput.type = 'hidden';
			dayInput.name = 'pllc_day';
			form.appendChild( dayInput );
		}
		if ( ! dateInput ) {
			dateInput = document.createElement( 'input' );
			dateInput.type = 'hidden';
			dateInput.name = 'pllc_delivery_date';
			form.appendChild( dateInput );
		}

		selector.querySelectorAll( '[data-pllc-role="particular-day"]' ).forEach( function ( radio ) {
			var available = window.PLLC_Data && PLLC_Data.day_availability && PLLC_Data.day_availability[ radio.value ] === true;
			radio.disabled = ! available;
			if ( ! available ) {
				radio.checked = false;
			}
			if ( radio.closest( 'label' ) ) {
				radio.closest( 'label' ).hidden = ! available;
			}
		} );
		var firstAvailable = selector.querySelector( '[data-pllc-role="particular-day"]:not(:disabled)' );
		if ( firstAvailable && ! selector.querySelector( '[data-pllc-role="particular-day"]:checked:not(:disabled)' ) ) {
			firstAvailable.checked = true;
		}
		if ( ! firstAvailable ) {
			button.disabled = true;
			setProductButtonState( button, 'Pedidos cerrados', 'disabled' );
		}

		selector.querySelectorAll( '[data-pllc-day-label]' ).forEach( function ( label ) {
			var title = PLLC_Data.day_titles && PLLC_Data.day_titles[ label.dataset.pllcDayLabel ];
			if ( title ) {
				label.textContent = title;
			}
		} );

		function selectedDay() {
			var radio = selector.querySelector( '[data-pllc-role="particular-day"]:checked' );
			return radio ? radio.value : '';
		}

		function selectedSlots() {
			var productState = PLLC_Data.cart_state && PLLC_Data.cart_state[ productId ];
			var day = selectedDay();
			return productState && productState._days && day ? productState._days[ day ] : null;
		}

		function refreshButton() {
			var existing = parseInt( form.dataset.pllcExistingQty || '0', 10 );
			var current = Math.max( 1, parseInt( input.value || '1', 10 ) || 1 );
			input.value = current;
			if ( form.dataset.pllcCartKey ) {
				setProductButtonState( button, current === existing ? 'Eliminar' : 'Actualizar', current === existing ? 'added' : 'pending' );
			} else {
				setProductButtonState( button, 'Agregar', 'base' );
			}
		}

		function loadSelectedDay() {
			if ( ! selectedDay() ) {
				dayInput.value = '';
				dateInput.value = '';
				button.disabled = true;
				setProductButtonState( button, 'Pedidos cerrados', 'disabled' );
				return;
			}
			var slots = selectedSlots();
			dayInput.value = selectedDay();
			dateInput.value = getDeliveryDate( selectedDay() );
			delete form.dataset.pllcCartKey;
			delete form.dataset.pllcExistingQty;
			if ( slots && slots._default ) {
				form.dataset.pllcCartKey = slots._default;
				form.dataset.pllcExistingQty = parseInt( slots._default_qty, 10 ) || 1;
				input.value = form.dataset.pllcExistingQty;
			} else {
				input.value = 1;
			}
			refreshButton();
		}

		selector.addEventListener( 'change', loadSelectedDay );
		input.addEventListener( 'input', refreshButton );
		input.addEventListener( 'change', refreshButton );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}, true );
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			var existing = parseInt( form.dataset.pllcExistingQty || '0', 10 );
			var quantity = Math.max( 1, parseInt( input.value || '1', 10 ) || 1 );
			if ( form.dataset.pllcCartKey && quantity === existing ) {
				removeFromCart( [ form.dataset.pllcCartKey ], button );
				return;
			}

			var payload = new FormData();
				payload.append( 'action', form.dataset.pllcCartKey ? 'pllc_update_particular_quantity' : 'pllc_add_particular_quantity' );
				payload.append( 'nonce', PLLC_Data.nonce );
				payload.append( 'quantity', quantity );
				payload.append( 'operation_id', ensureOperationId( form ) );
			appendParticularObservations( payload );
			if ( form.dataset.pllcCartKey ) {
				payload.append( 'cart_item_key', form.dataset.pllcCartKey );
				} else {
					payload.append( 'product_id', productId );
					payload.append( 'pllc_day', selectedDay() );
					payload.append( 'pllc_delivery_date', getDeliveryDate( selectedDay() ) );
				}

			button.disabled = true;
			setButtonLabel( button, form.dataset.pllcCartKey ? 'Actualizando…' : 'Agregando…' );
			fetch( PLLC_Data.ajax_url, { method: 'POST', credentials: 'same-origin', body: payload } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( response ) {
					if ( response && response.success ) {
						rememberCartEventAfterReload( response.data );
						window.location.reload();
						return;
					}
					button.disabled = false;
					refreshButton();
					alert( response && response.data && response.data.message ? response.data.message : 'No se pudo actualizar el producto.' );
				} )
				.catch( function () {
					button.disabled = false;
					refreshButton();
					alert( 'Error de conexión, probá de nuevo.' );
				} );
		}, true );

		loadSelectedDay();
	}

	function handleVariantChange( input ) {
		var card = closestCard( input );
		if ( ! card ) {
			return;
		}
		card.dataset.pllcVariant = input.dataset.pllcValue || '';
		card.dataset.pllcVariationId = input.dataset.pllcVariationId || '';

		// Si el producto ya está en el carrito, cambiar Clásico/XL solo
		// deja una actualización pendiente. El envío real se hace desde el
		// botón general "Actualizar carrito".
		if ( card.dataset.pllcCartKeys && card.dataset.pllcOriginalVariationId ) {
			var btn = card.querySelector( '[data-pllc-role="add-btn"]' );
			if ( ! btn ) {
				return;
			}
			if ( String( card.dataset.pllcVariationId ) !== String( card.dataset.pllcOriginalVariationId ) ) {
				card.dataset.pllcVariationUpdate = 'pending';
				setProductButtonState( btn, 'Actualizar', 'pending' );
			} else {
				delete card.dataset.pllcVariationUpdate;
				setProductButtonState( btn, 'Eliminar', 'added' );
			}
		}
	}

	function handleQtyClick( btn, delta ) {
		var card = closestCard( btn );
		if ( ! card ) {
			return;
		}
		var input = card.querySelector( '[data-pllc-role="qty-value"]' );
		if ( ! input ) {
			return;
		}

		var current = parseInt( input.value, 10 );
		current = isNaN( current ) ? 0 : current;
		input.value = Math.max( 0, current + delta );
		refreshQuantityCardButton( card );
	}

	function refreshQuantityCardButton( card ) {
		if ( ! card ) {
			return;
		}
		var input    = card.querySelector( '[data-pllc-role="qty-value"]' );
		var addBtn   = card.querySelector( '[data-pllc-role="add-btn"]' );
		var existing = parseInt( card.dataset.pllcExistingQty || '0', 10 );
		var particularBatch = !! getParticularOrderForm();
		if ( ! input || ! addBtn ) {
			return;
		}
		var current = parseInt( input.value, 10 );
		current = isNaN( current ) ? 0 : Math.max( 0, current );
		input.value = current;
		if ( existing <= 0 ) {
			delete card.dataset.pllcQuantityUpdate;
			if ( current <= 0 ) {
				delete card.dataset.pllcAdded;
				setProductButtonState( addBtn, getBaseAddLabel( addBtn ), 'disabled' );
			} else if ( card.dataset.pllcAdded === '1' ) {
				setProductButtonState( addBtn, 'Eliminar', 'added' );
			} else {
				setProductButtonState( addBtn, getBaseAddLabel( addBtn ), 'base' );
			}
			return;
		}
		if ( current === 0 && ! particularBatch ) {
			card.dataset.pllcQuantityUpdate = 'prepared';
			delete card.dataset.pllcAdded;
			setProductButtonState( addBtn, getBaseAddLabel( addBtn ), 'disabled' );
			return;
		}
		if ( current !== existing ) {
			if ( particularBatch ) {
				card.dataset.pllcQuantityUpdate = 'pending';
				card.dataset.pllcAdded = '1';
				setProductButtonState( addBtn, 'Actualizar', 'pending' );
				return;
			}
			// En ITEO Pacientes el selector representa la cantidad total.
			// El cambio se guarda con el botón general "Actualizar carrito".
			card.dataset.pllcQuantityUpdate = 'prepared';
			card.dataset.pllcAdded = '1';
			setProductButtonState( addBtn, 'Eliminar', 'added' );
			} else {
				delete card.dataset.pllcQuantityUpdate;
				card.dataset.pllcAdded = '1';
				setProductButtonState( addBtn, 'Eliminar', 'added' );
		}
	}

	function setGridExclusive( card ) {
		var grid = closestGrid( card );
		if ( ! grid ) {
			return;
		}

		// Colegios admite un solo plato por día. Se bloquean los demás platos,
		// pero Clásico/XL del plato elegido siguen editables para poder preparar
		// una actualización sin eliminar primero la línea del carrito.
		grid.querySelectorAll( '[data-pllc-role="variant"]' ).forEach( function ( input ) {
			input.disabled = closestCard( input ) !== card;
		} );

		grid.querySelectorAll( '[data-pllc-role="add-btn"]' ).forEach( function ( btn ) {
			if ( closestCard( btn ) !== card ) {
				btn.classList.add( 'pllc-disabled' );
				btn.setAttribute( 'aria-disabled', 'true' );
			}
		} );
	}

	function clearProductState() {
		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			delete card.dataset.pllcAdded;
			delete card.dataset.pllcCartKeys;
			delete card.dataset.pllcExistingQty;
			delete card.dataset.pllcVariant;
			delete card.dataset.pllcVariationId;
			delete card.dataset.pllcOriginalVariationId;
			delete card.dataset.pllcVariationUpdate;
			delete card.dataset.pllcQuantityUpdate;
			delete card.dataset.pllcMealUpdate;
			delete card.dataset.pllcOriginalMeals;
			delete card.dataset.pllcMealCartKeys;
			delete card.dataset.pllcFreshConfirmed;
			delete card.dataset.pllcConfirmedMeals;
			delete card.dataset.pllcMealConfirmation;
			delete card.dataset.pllcMealAction;

			card.querySelectorAll( '[data-pllc-role="variant"]' ).forEach( function ( input ) {
				input.checked = false;
				input.disabled = false;
			} );

			var btn = card.querySelector( '[data-pllc-role="add-btn"]' );
			if ( btn ) {
				setProductButtonState( btn, 'Agregar al pedido', 'base' );
			}
		} );
	}

	function releaseGridExclusive( card ) {
		var grid = closestGrid( card );
		if ( ! grid ) {
			return;
		}

		grid.querySelectorAll( '[data-pllc-role="variant"]' ).forEach( function ( input ) {
			input.disabled = false;
		} );

		grid.querySelectorAll( '[data-pllc-role="add-btn"]' ).forEach( function ( btn ) {
			btn.classList.remove( 'pllc-disabled' );
			btn.removeAttribute( 'aria-disabled' );
		} );
	}

	function resetCardSelection( card, mode ) {
		if ( 'variant' === mode ) {
			var checkedRadio = card.querySelector( '[data-pllc-role="variant"]:checked' );
			if ( checkedRadio ) {
				checkedRadio.checked = false;
			}
			delete card.dataset.pllcVariant;
			delete card.dataset.pllcVariationId;
		}

		if ( 'checkbox' === mode ) {
			// Solo desmarca lo NUEVO (sin cart-key) — lo que ya está en
			// el carrito real queda tildado y bloqueado tal cual.
			card.querySelectorAll( '[data-pllc-role="check"]:not([data-pllc-cart-key])' ).forEach( function ( c ) {
				c.checked = false;
			} );
		}

		if ( 'quantity' === mode ) {
			var qtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
			if ( qtyInput ) {
				qtyInput.value = 0;
			}
		}
	}

	/**
	 * Refleja en el botón si hay algo YA existente en el carrito real
	 * para esta card — se llama al cargar la página. Tildar un checkbox
	 * nuevo NO pasa por acá (no debe activar el botón solo; hace falta
	 * el click explícito en "Agregar al pedido", ver handleAddClick).
	 */
	function refreshCheckboxCardButton( card ) {
		var btn = card.querySelector( '[data-pllc-role="add-btn"]' );
		if ( ! btn ) {
			return;
		}

		if ( card.dataset.pllcFreshConfirmed === '1' || getExistingCheckKeys( card ).length ) {
			card.dataset.pllcAdded = '1';
			setProductButtonState( btn, 'Eliminar', 'added' );
		} else if ( getCheckedMeals( card ).length ) {
			card.dataset.pllcAdded = '0';
			setProductButtonState( btn, 'Agregar al pedido', 'base' );
		} else {
			card.dataset.pllcAdded = '0';
			setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
		}

		refreshIteoPersonalSubmitState();
	}

	function getCheckedMeals( card ) {
		return Array.prototype.map.call(
			card.querySelectorAll( '[data-pllc-role="check"]:checked' ),
			function ( input ) { return input.dataset.pllcValue || ''; }
		).filter( Boolean ).sort();
	}

	function getOriginalMeals( card ) {
		if ( ! card.dataset.pllcOriginalMeals ) {
			return [];
		}
		try {
			return JSON.parse( card.dataset.pllcOriginalMeals ).sort();
		} catch ( error ) {
			return [];
		}
	}

	function sameMeals( first, second ) {
		return first.length === second.length && first.every( function ( meal, index ) {
			return meal === second[ index ];
		} );
	}

	function getConfirmedFreshMeals( card ) {
		if ( ! card || ! card.dataset.pllcConfirmedMeals ) {
			return [];
		}
		try {
			var meals = JSON.parse( card.dataset.pllcConfirmedMeals );
			return Array.isArray( meals ) ? meals.sort() : [];
		} catch ( error ) {
			return [];
		}
	}

	function refreshFreshMealChangeState( card ) {
		var confirmed = getConfirmedFreshMeals( card );
		var current = getCheckedMeals( card );
		if ( confirmed.length && sameMeals( current, confirmed ) ) {
			card.dataset.pllcFreshConfirmed = '1';
			delete card.dataset.pllcMealConfirmation;
		} else if ( confirmed.length ) {
			delete card.dataset.pllcFreshConfirmed;
			card.dataset.pllcMealConfirmation = 'pending';
		}
		refreshFreshMealCardButton( card );
	}

	function refreshExistingMealCardButton( card ) {
		if ( ! card || ! card.dataset.pllcOriginalMeals ) {
			return;
		}
		var btn = card.querySelector( '[data-pllc-role="add-btn"]' );
		if ( ! btn ) {
			return;
		}

		var current = getCheckedMeals( card );
		var original = getOriginalMeals( card );
		var confirmed = card.dataset.pllcConfirmedMeals !== undefined
			? getConfirmedFreshMeals( card )
			: original;

		if ( sameMeals( current, confirmed ) ) {
			delete card.dataset.pllcMealConfirmation;
			delete card.dataset.pllcMealAction;
			if ( sameMeals( current, original ) ) {
				delete card.dataset.pllcMealUpdate;
			} else {
				card.dataset.pllcMealUpdate = 'prepared';
			}
			if ( current.length ) {
				card.dataset.pllcAdded = '1';
				setProductButtonState( btn, 'Eliminar', 'added' );
			} else {
				delete card.dataset.pllcAdded;
				setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
			}
		} else if ( ! confirmed.length && current.length ) {
			card.dataset.pllcMealConfirmation = 'pending';
			card.dataset.pllcMealAction = 'add';
			setProductButtonState( btn, 'Agregar al pedido', 'base' );
		} else {
			delete card.dataset.pllcMealAction;
			card.dataset.pllcMealUpdate = 'pending';
			setProductButtonState( btn, 'Actualizar', 'pending' );
		}

		refreshIteoPersonalSubmitState();
	}

	function getMealDay( card ) {
		var contextualDay = getCardDay( card );
		if ( contextualDay ) {
			return contextualDay;
		}
		var group = card ? card.querySelector( '[data-pllc-role="check-group"][data-pllc-day]' ) : null;
		return group ? ( group.dataset.pllcDay || '' ) : '';
	}

	function getCardProductLabel( card ) {
		var title = card ? card.querySelector( '.elementor-heading-title, .woocommerce-loop-product__title, h2, h3' ) : null;
		return title ? title.textContent.trim() : 'el plato seleccionado';
	}

	function refreshFreshMealCardButton( card ) {
		var btn = card ? card.querySelector( '[data-pllc-role="add-btn"]' ) : null;
		if ( ! btn ) {
			return;
		}

		if ( card.dataset.pllcMealConfirmation === 'pending' ) {
			setProductButtonState( btn, 'Actualizar', 'pending' );
			refreshIteoPersonalSubmitState();
			return;
		}

		if ( ! getCheckedMeals( card ).length ) {
			delete card.dataset.pllcAdded;
			delete card.dataset.pllcFreshConfirmed;
			setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
			refreshIteoPersonalSubmitState();
			return;
		}

		if ( card.dataset.pllcFreshConfirmed === '1' ) {
			setProductButtonState( btn, 'Eliminar', 'added' );
		} else {
			setProductButtonState( btn, 'Agregar al pedido', 'base' );
		}

		refreshIteoPersonalSubmitState();
	}

	/** El diálogo de reemplazo ya funciona como confirmación del cambio. */
	function confirmMealCardChange( card ) {
		var btn = card ? card.querySelector( '[data-pllc-role="add-btn"]' ) : null;
		if ( ! btn ) {
			return;
		}

		if ( card.dataset.pllcOriginalMeals ) {
			card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
			delete card.dataset.pllcMealConfirmation;
			delete card.dataset.pllcMealAction;
			if ( sameMeals( getCheckedMeals( card ), getOriginalMeals( card ) ) ) {
				delete card.dataset.pllcMealUpdate;
			} else {
				card.dataset.pllcMealUpdate = 'prepared';
			}
			if ( getCheckedMeals( card ).length ) {
				card.dataset.pllcAdded = '1';
				setProductButtonState( btn, 'Eliminar', 'added' );
			} else {
				delete card.dataset.pllcAdded;
				setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
			}
		} else if ( getCheckedMeals( card ).length ) {
			card.dataset.pllcFreshConfirmed = '1';
			card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
			card.dataset.pllcAdded = '1';
			delete card.dataset.pllcMealConfirmation;
			setProductButtonState( btn, 'Eliminar', 'added' );
		} else {
			delete card.dataset.pllcFreshConfirmed;
			delete card.dataset.pllcConfirmedMeals;
			delete card.dataset.pllcMealConfirmation;
			delete card.dataset.pllcAdded;
			setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
		}

		refreshIteoPersonalSubmitState();
	}

	function handleMealSelectionChange( input ) {
		var card = closestCard( input );
		if ( ! card ) {
			return;
		}

		if ( ! input.checked ) {
			if ( card.dataset.pllcOriginalMeals ) {
				refreshExistingMealCardButton( card );
			} else {
				refreshFreshMealChangeState( card );
			}
			return;
		}

		var day = getMealDay( card );
		var meal = input.dataset.pllcValue || '';
		if ( ! day || ! meal ) {
			refreshExistingMealCardButton( card );
			return;
		}

		var conflicts = [];
		document.querySelectorAll( '[data-pllc-role="check"][data-pllc-value="' + meal + '"]:checked' ).forEach( function ( candidate ) {
			var candidateCard = closestCard( candidate );
			if ( candidate !== input && candidateCard && getMealDay( candidateCard ) === day ) {
				conflicts.push( candidate );
			}
		} );

		if ( ! conflicts.length ) {
			if ( card.dataset.pllcOriginalMeals ) {
				refreshExistingMealCardButton( card );
			} else {
				refreshFreshMealChangeState( card );
			}
			return;
		}

		var previousCard = closestCard( conflicts[0] );
		var previousName = getCardProductLabel( previousCard );
		var newName = getCardProductLabel( card );
		var mealLabel = 'almuerzo' === meal ? 'almuerzo' : 'cena';
		var dayLabel = day.charAt(0).toUpperCase() + day.slice(1);
		var message = 'Ya elegiste “' + previousName + '” para ' + mealLabel + ' del ' + dayLabel + '.\n\n¿Querés reemplazarlo por “' + newName + '”?';

		if ( ! window.confirm( message ) ) {
			input.checked = false;
			if ( card.dataset.pllcOriginalMeals ) {
				refreshExistingMealCardButton( card );
			} else {
				refreshFreshMealCardButton( card );
			}
			return;
		}

		conflicts.forEach( function ( conflict ) {
			conflict.checked = false;
			confirmMealCardChange( closestCard( conflict ) );
		} );
		confirmMealCardChange( card );
	}

	function handleAddClick( btn ) {
		if ( btn.classList.contains( 'pllc-disabled' ) ) {
			return; // Deshabilitado por exclusión del día (Colegios).
		}

		var card = closestCard( btn );
		if ( ! card ) {
			return;
		}

		var mode = getProductCardMode( card, btn );
		if ( mode ) {
			btn.dataset.pllcMode = mode;
		}

		// El primer clic confirma el cambio solo en el navegador. La variante
		// se guarda en WooCommerce recién con "Actualizar carrito".
		if ( 'variant' === mode && card.dataset.pllcVariationUpdate === 'pending' ) {
			card.dataset.pllcVariationUpdate = 'prepared';
			setProductButtonState( btn, 'Cambio preparado', 'pending' );
			return;
		}

		if ( 'variant' === mode && card.dataset.pllcVariationUpdate === 'prepared' ) {
			return;
		}

		// En ITEO Pacientes, "Eliminar" prepara cantidad 0. La línea real
		// se elimina recién al pulsar el botón general "Actualizar carrito".
		if ( 'quantity' === mode && card.dataset.pllcCartKeys ) {
			if ( getParticularOrderForm() && card.dataset.pllcQuantityUpdate === 'pending' ) {
				card.dataset.pllcQuantityUpdate = 'prepared';
				setProductButtonState( btn, 'Cambio preparado', 'pending' );
				return;
			}
			if ( getParticularOrderForm() && card.dataset.pllcQuantityUpdate === 'prepared' ) {
				return;
			}
			var existingQtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
			if ( existingQtyInput ) {
				existingQtyInput.value = 0;
			}
			card.dataset.pllcQuantityUpdate = 'prepared';
			delete card.dataset.pllcAdded;
			setProductButtonState( btn, getBaseAddLabel( btn ), 'disabled' );
			return;
		}

		if ( 'checkbox' === mode ) {
			if ( card.dataset.pllcOriginalMeals ) {
				if ( card.dataset.pllcMealConfirmation === 'pending' && card.dataset.pllcMealAction === 'add' ) {
					card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
					delete card.dataset.pllcMealConfirmation;
					delete card.dataset.pllcMealAction;
					if ( sameMeals( getCheckedMeals( card ), getOriginalMeals( card ) ) ) {
						delete card.dataset.pllcMealUpdate;
					} else {
						card.dataset.pllcMealUpdate = 'prepared';
					}
					card.dataset.pllcAdded = '1';
					setProductButtonState( btn, 'Eliminar', 'added' );
					refreshIteoPersonalSubmitState();
					return;
				}

				if ( card.dataset.pllcMealUpdate === 'pending' ) {
					card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
					card.dataset.pllcMealUpdate = 'prepared';
					if ( getCheckedMeals( card ).length ) {
						card.dataset.pllcAdded = '1';
						setProductButtonState( btn, 'Eliminar', 'added' );
					} else {
						delete card.dataset.pllcAdded;
						setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
					}
					refreshIteoPersonalSubmitState();
					return;
				}

				var savedKeys = getExistingCheckKeys( card );
				if ( savedKeys.length ) {
					card.querySelectorAll( '[data-pllc-role="check"]' ).forEach( function ( input ) {
						input.checked = false;
					} );
					card.dataset.pllcConfirmedMeals = '[]';
					card.dataset.pllcMealUpdate = 'prepared';
					delete card.dataset.pllcMealConfirmation;
					delete card.dataset.pllcMealAction;
					delete card.dataset.pllcAdded;
					setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
					refreshIteoPersonalSubmitState();
				}
				return;
			}

			var fresh = getFreshChecked( card );

			// Una selección ya confirmada que fue modificada siempre exige
			// "Actualizar", incluso cuando se desmarcó el último checkbox.
			if ( card.dataset.pllcMealConfirmation === 'pending' ) {
				delete card.dataset.pllcMealConfirmation;
				if ( fresh.length ) {
					card.dataset.pllcAdded = '1';
					card.dataset.pllcFreshConfirmed = '1';
					card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
					setProductButtonState( btn, 'Eliminar', 'added' );
				} else {
					delete card.dataset.pllcAdded;
					delete card.dataset.pllcFreshConfirmed;
					delete card.dataset.pllcConfirmedMeals;
					setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
				}
				refreshIteoPersonalSubmitState();
				return;
			}

			// Una única confirmación individual: después de elegir Almuerzo
			// y/o Cena, este clic deja el plato listo para el botón general.
			if ( fresh.length && card.dataset.pllcFreshConfirmed !== '1' ) {
				card.dataset.pllcAdded = '1';
				card.dataset.pllcFreshConfirmed = '1';
				card.dataset.pllcConfirmedMeals = JSON.stringify( getCheckedMeals( card ) );
				delete card.dataset.pllcMealConfirmation;
				setProductButtonState( btn, 'Eliminar', 'added' );
				refreshIteoPersonalSubmitState();
				return;
			}

			// En una selección nueva ya confirmada, Eliminar revierte toda
			// la selección local antes de enviarla al carrito.
			if ( fresh.length && card.dataset.pllcFreshConfirmed === '1' ) {
				resetCardSelection( card, mode );
				delete card.dataset.pllcFreshConfirmed;
				delete card.dataset.pllcConfirmedMeals;
				delete card.dataset.pllcMealConfirmation;
				refreshCheckboxCardButton( card );
				return;
			}

			if ( ! fresh.length && card.dataset.pllcFreshConfirmed === '1' ) {
				delete card.dataset.pllcFreshConfirmed;
				delete card.dataset.pllcMealConfirmation;
				card.dataset.pllcAdded = '0';
				setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
				refreshIteoPersonalSubmitState();
				return;
			}

			// Un producto pre-existente sigue el mismo circuito de confirmación
			// que cualquier otro cambio de ITEO Personal: "Eliminar" prepara
			// la baja y el botón general "Actualizar carrito" la confirma.
			// De este modo no hay una eliminación AJAX inmediata ni un flujo
			// distinto según el carrito estuviera vacío o tuviera productos.
			var existingKeys = getExistingCheckKeys( card );
			if ( existingKeys.length ) {
				card.querySelectorAll( '[data-pllc-role="check"]' ).forEach( function ( input ) {
					input.checked = false;
				} );
				card.dataset.pllcMealUpdate = 'prepared';
				delete card.dataset.pllcAdded;
				setProductButtonState( btn, 'Agregar al pedido', 'disabled' );
				refreshIteoPersonalSubmitState();
				return;
			}

			alert( 'Elegí Almuerzo y/o Cena antes de agregar.' );
			return;
		}

		var alreadyAdded = card.dataset.pllcAdded === '1';

		if ( alreadyAdded ) {
			// Si ya está en el carrito real (vino marcado desde el
			// servidor al cargar la página), hay que sacarlo del carrito
			// de verdad, no solo resetear el estado local.
			if ( card.dataset.pllcCartKeys ) {
				removeFromCart( card.dataset.pllcCartKeys.split( ',' ), btn );
				return;
			}

			card.dataset.pllcAdded = '0';
			setProductButtonState( btn, getBaseAddLabel( btn ), 'base' );

			resetCardSelection( card, mode );

			if ( 'quantity' === mode ) {
				setProductButtonState( btn, getBaseAddLabel( btn ), 'disabled' );
			} else if ( 'variant' === mode ) {
				releaseGridExclusive( card );
			}
			return;
		}

		if ( 'variant' === mode && ! card.dataset.pllcVariant ) {
			alert( 'Elegí Clásico o XL antes de agregar.' );
			return;
		}

		if ( 'quantity' === mode ) {
			var newQtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
			var newQty = newQtyInput ? parseInt( newQtyInput.value, 10 ) : 0;
			if ( isNaN( newQty ) || newQty <= 0 ) {
				alert( 'Elegí una cantidad mayor a 0 antes de agregar.' );
				return;
			}
		}

		card.dataset.pllcAdded = '1';
		setProductButtonState( btn, 'Eliminar', 'added' );

		if ( 'variant' === mode ) {
			setGridExclusive( card );
		}
	}

	/** Saca del carrito real y recarga desde el estado canónico del servidor. */
	function removeFromCart( keys, btn ) {
		var collegeForm = document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type="colegios"]' );
		var selectedStudentKey = collegeForm ? ( collegeForm.dataset.pllcStudentKey || '' ) : '';
		var studentNameField = collegeForm ? collegeForm.querySelector( '[data-pllc-field="nombre_alumno"]' ) : null;
		var studentName = studentNameField ? studentNameField.value : '';

		if ( selectedStudentKey && ! window.confirm( '¿Eliminar este producto del pedido de ' + studentName + '?' ) ) {
			return;
		}

		btn.disabled = true;

		var payload = new FormData();
		payload.append( 'action', 'pllc_remove_order_item' );
		payload.append( 'nonce', PLLC_Data.nonce );
		payload.append( 'form_type', getCurrentFormType() );
		keys.forEach( function ( k ) {
			payload.append( 'cart_item_keys[]', k );
		} );

		fetch( PLLC_Data.ajax_url, { method: 'POST', body: payload } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res.success ) {
					var state = acceptFrontendState( res.data );
					try {
						sessionStorage.removeItem( STUDENT_RESTORE_KEY );
					} catch ( error ) {}
					if ( selectedStudentKey && state && ( state.students || [] ).some( function ( student ) { return student.key === selectedStudentKey; } ) ) {
						rememberStudentAfterReload( selectedStudentKey );
					}
					rememberCartEventAfterReload( res.data, 'removed_from_cart' );
					window.location.href = window.location.pathname + window.location.search;
				} else {
					btn.disabled = false;
					alert( 'No se pudo quitar del carrito, probá de nuevo.' );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				alert( 'Error de conexión, probá de nuevo.' );
			} );
	}

	/**
	 * Al cargar la página, marca como "ya agregadas" (botón en "Eliminar",
	 * checkbox/radio tildado) las cards que correspondan a productos que
	 * el usuario ya tiene en el carrito real — usando PLLC_Data.cart_state,
	 * que llega calculado desde el servidor (PLLC_Frontend_Assets).
	 */
	function applyCartState( state ) {
		state = state || ( window.PLLC_Data ? PLLC_Data.cart_state : null );
		if ( ! state ) {
			return;
		}

		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var productId = getProductId( card );
			var slots     = productId ? state[ productId ] : null;
			var day       = getCardDay( card );
			if ( slots && day ) {
				slots = slots._days ? ( slots._days[ day ] || null ) : null;
			}

			if ( ! slots ) {
				return;
			}

			var addBtn = card.querySelector( '[data-pllc-role="add-btn"]' );
			if ( ! addBtn ) {
				return;
			}

			// Checkbox (ITEO Personal): recupera la selección existente,
			// pero la mantiene editable para preparar cambios.
			if ( 'checkbox' === addBtn.dataset.pllcMode ) {
				var originalMeals = [];
				var mealKeys = {};
				card.querySelectorAll( '[data-pllc-role="check"]' ).forEach( function ( input ) {
					var meal = input.dataset.pllcValue;
					if ( slots[ meal ] ) {
						input.checked = true;
						input.dataset.pllcCartKey = slots[ meal ];
						originalMeals.push( meal );
						mealKeys[ meal ] = slots[ meal ];
					}
				} );
				if ( originalMeals.length ) {
					card.dataset.pllcOriginalMeals = JSON.stringify( originalMeals.sort() );
					card.dataset.pllcConfirmedMeals = JSON.stringify( originalMeals.sort() );
					card.dataset.pllcMealCartKeys = JSON.stringify( mealKeys );
					card.dataset.pllcAdded = '0';
					refreshExistingMealCardButton( card );
				}
				return;
			}

			// Variante (Colegios): un solo posible ítem, ya confirmado.
			if ( 'variant' === addBtn.dataset.pllcMode ) {
				if ( ! slots._default ) {
					return;
				}
				card.dataset.pllcAdded = '1';
				card.dataset.pllcCartKeys = slots._default;
				var variationId = String( slots._default_variation_id || '' );
				card.dataset.pllcOriginalVariationId = variationId;
				var variationInput = card.querySelector( '[data-pllc-role="variant"][data-pllc-variation-id="' + variationId + '"]' );
				if ( variationInput ) {
					variationInput.checked = true;
					card.dataset.pllcVariant = variationInput.dataset.pllcValue || '';
					card.dataset.pllcVariationId = variationId;
				}
				setProductButtonState( addBtn, 'Eliminar', 'added' );
				setGridExclusive( card );
				return;
			}

			// Cantidad (ITEO Pacientes): recupera y muestra la cantidad total
			// guardada en la línea existente del carrito.
			if ( 'quantity' === addBtn.dataset.pllcMode ) {
				if ( ! slots._default ) {
					return;
				}
				var qtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
				var existingQty = parseInt( slots._default_qty, 10 ) || 1;

				if ( qtyInput ) {
					qtyInput.value = existingQty;
				}

				card.dataset.pllcExistingQty = existingQty;
				card.dataset.pllcCartKeys = slots._default;
				card.dataset.pllcAdded = '1';
				setProductButtonState( addBtn, 'Eliminar', 'added' );
			}
		} );
	}

	function setCollegeFormLocked( form, locked ) {
		form.querySelectorAll( '[data-pllc-field]' ).forEach( function ( field ) {
			if ( 'observaciones' === field.dataset.pllcField ) {
				field.disabled = false;
				return;
			}
			field.disabled = locked;
		} );
		form.classList.toggle( 'pllc-existing-student', locked );
	}

	function showStudentNameField( form, show ) {
		var wrapper = form.querySelector( '[data-pllc-role="student-name-field"]' );
		if ( wrapper ) {
			wrapper.hidden = ! show;
		}
	}

	function setOrderSubmitLabel( form, editing ) {
		var submit = form.querySelector( '[data-pllc-role="submit-order"]' );
		if ( submit ) {
			if ( form.dataset.pllcFormType === 'iteo_personal' ) {
				setButtonLabel( submit, editing ? 'Actualizar carrito' : 'Agregar al carrito' );
			} else {
				setButtonLabel( submit, editing ? 'Actualizar carrito' : 'Agregar al carrito' );
			}
		}
	}

	function refreshIteoPersonalSubmitState() {
		var form = document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type="iteo_personal"]' );
		if ( ! form ) {
			return;
		}

		var submit = form.querySelector( '[data-pllc-role="submit-order"]' );
		var message = form.querySelector( '[data-pllc-role="form-message"]' );
		var hasActiveOrder = form.dataset.pllcActiveOrder === '1';
		var hasConfirmedNew = !! document.querySelector( '.e-loop-item[data-pllc-fresh-confirmed="1"]' );
		var hasPreparedUpdate = !! document.querySelector( '.e-loop-item[data-pllc-meal-update="prepared"]' );
		var hasFormChanges = form.dataset.pllcFormDirty === '1';
		var hasPendingConfirmation = !! document.querySelector(
			'.e-loop-item[data-pllc-meal-update="pending"], .e-loop-item[data-pllc-meal-confirmation="pending"]'
		);

		if ( submit ) {
			submit.disabled = hasPendingConfirmation || ( ! hasConfirmedNew && ! hasPreparedUpdate && ! hasFormChanges );
			setOrderSubmitLabel( form, hasActiveOrder );
		}

		if ( message ) {
			message.classList.add( 'pllc-form-message-info' );
			if ( hasPendingConfirmation ) {
				message.textContent = 'Usá el botón Actualizar del plato antes de continuar.';
			} else if ( hasConfirmedNew || hasPreparedUpdate || hasFormChanges ) {
				message.textContent = 'Tenés cambios sin guardar.';
			} else {
				message.textContent = '';
				message.classList.remove( 'pllc-form-message-info' );
			}
		}
	}

	function rememberStudentAfterReload( studentKey ) {
		if ( ! studentKey ) {
			return;
		}
		try {
			sessionStorage.setItem( STUDENT_RESTORE_KEY, studentKey );
		} catch ( error ) {}
	}

	function fillCollegeForm( form, data ) {
		var colegio = form.querySelector( '[data-pllc-field="colegio"]' );
		var nivel = form.querySelector( '[data-pllc-field="nivel"]' );
		var curso = form.querySelector( '[data-pllc-field="curso"]' );
		if ( colegio ) {
			colegio.value = data.colegio || '';
			handleColegioChange( colegio );
		}
		if ( nivel ) {
			nivel.value = data.nivel || '';
			handleNivelChange( nivel );
		}
		if ( curso ) {
			curso.value = data.curso || '';
		}

		form.querySelectorAll( '[data-pllc-field]' ).forEach( function ( field ) {
			if ( [ 'colegio', 'nivel', 'curso' ].indexOf( field.dataset.pllcField ) !== -1 ) {
				return;
			}
			var value = data[ field.dataset.pllcField ] || '';
			if ( 'radio' === field.type ) {
				field.checked = field.value === value;
			} else {
				field.value = value;
			}
		} );
	}

	function resetCollegeForm( form ) {
		delete form.dataset.pllcStudentKey;
		setCollegeFormLocked( form, false );
		showStudentNameField( form, true );
		setOrderSubmitLabel( form, false );
		form.querySelectorAll( '[data-pllc-field]' ).forEach( function ( field ) {
			if ( 'radio' === field.type || 'checkbox' === field.type ) {
				field.checked = false;
			} else {
				field.value = '';
			}
		} );
		var curso = form.querySelector( '[data-pllc-field="curso"]' );
		var nivel = form.querySelector( '[data-pllc-field="nivel"]' );
		if ( nivel ) {
			nivel.innerHTML = '<option value="">Elegí primero el colegio</option>';
			nivel.disabled = true;
		}
		if ( curso ) {
			curso.innerHTML = '<option value="">Elegí primero el nivel</option>';
			curso.disabled = true;
		}
		clearProductState();
	}

	function initStudentPicker() {
		var form = document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type="colegios"]' );
		var picker = form ? form.querySelector( '[data-pllc-role="student-picker"]' ) : null;
		var options = picker ? picker.querySelector( '[data-pllc-role="student-options"]' ) : null;
		var students = window.PLLC_Data && Array.isArray( PLLC_Data.students ) ? PLLC_Data.students : [];

		if ( ! form || ! picker || ! options || ! students.length ) {
			return;
		}

		var html = '<label class="pllc-student-option"><input type="radio" name="pllc-student" value="new" checked> Nuevo alumno</label>';
		students.forEach( function ( student ) {
			html += '<label class="pllc-student-option"><input type="radio" name="pllc-student" value="' + student.key + '"> ' + escapeHtml( student.name ) + '</label>';
		} );
		options.innerHTML = html;
		picker.hidden = false;

		options.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( 'input[name="pllc-student"]' ) ) {
				return;
			}
			if ( 'new' === event.target.value ) {
				resetCollegeForm( form );
				return;
			}
			var student = students.find( function ( item ) { return item.key === event.target.value; } );
			if ( ! student ) {
				return;
			}
			clearProductState();
			fillCollegeForm( form, student.form || {} );
			form.dataset.pllcStudentKey = student.key;
			showStudentNameField( form, false );
			setOrderSubmitLabel( form, true );
			setCollegeFormLocked( form, true );
			applyCartState( student.cart_state || {} );
		} );

		var restoreKey = '';
		try {
			restoreKey = sessionStorage.getItem( STUDENT_RESTORE_KEY ) || '';
			sessionStorage.removeItem( STUDENT_RESTORE_KEY );
		} catch ( error ) {}

		if ( restoreKey ) {
			var restoreInput = options.querySelector( 'input[name="pllc-student"][value="' + restoreKey + '"]' );
			if ( restoreInput ) {
				restoreInput.checked = true;
				restoreInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		}
	}

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value || '';
		return div.innerHTML;
	}

	function handleColegioChange( select ) {
		var form = select.closest( '[data-pllc-role="order-form"]' );
		if ( ! form ) {
			return;
		}
		var nivelSelect = form.querySelector( '[data-pllc-field="nivel"]' );
		var cursoSelect = form.querySelector( '[data-pllc-field="curso"]' );
		if ( ! nivelSelect || ! cursoSelect ) {
			return;
		}
		var estructura = ESTRUCTURA_POR_COLEGIO[ select.value ] || null;
		var html = '<option value="">Seleccionar</option>';
		if ( estructura ) {
			Object.keys( estructura ).forEach( function ( nivel ) {
				html += '<option value="' + escapeHtml( nivel ) + '">' + escapeHtml( nivel ) + '</option>';
			} );
		} else {
			html = '<option value="">Elegí primero el colegio</option>';
		}
		nivelSelect.innerHTML = html;
		nivelSelect.disabled = ! estructura;
		cursoSelect.innerHTML = '<option value="">Elegí primero el nivel</option>';
		cursoSelect.disabled = true;
	}

	function handleNivelChange( select ) {
		var form = select.closest( '[data-pllc-role="order-form"]' );
		if ( ! form ) {
			return;
		}
		var cursoSelect = form.querySelector( '[data-pllc-field="curso"]' );
		if ( ! cursoSelect ) {
			return;
		}
		var colegioSelect = form.querySelector( '[data-pllc-field="colegio"]' );
		var estructura = colegioSelect ? ESTRUCTURA_POR_COLEGIO[ colegioSelect.value ] : null;
		var opciones = estructura && estructura[ select.value ] ? estructura[ select.value ] : [];
		var html     = '<option value="">Seleccionar</option>';
		opciones.forEach( function ( c ) {
			html += '<option value="' + c + '">' + c + '</option>';
		} );
		cursoSelect.innerHTML = html;
		cursoSelect.disabled = ! opciones.length;
	}

	function getExistingCheckKeys( card ) {
		var keys = [];
		card.querySelectorAll( '[data-pllc-role="check"][data-pllc-cart-key]' ).forEach( function ( c ) {
			keys.push( c.dataset.pllcCartKey );
		} );
		return keys;
	}

	function getFreshChecked( card ) {
		return card.querySelectorAll( '[data-pllc-role="check"]:checked:not([data-pllc-cart-key])' );
	}

	function collectOrderItems() {
		var items = [];

		document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
			var addBtn = card.querySelector( '[data-pllc-role="add-btn"]' );
			if ( ! addBtn ) {
				return;
			}

			var productId = getProductId( card );
			if ( ! productId ) {
				return;
			}

			// Checkbox (ITEO Personal): solo lo tildado NUEVO (sin
			// data-pllc-cart-key) — lo que ya estaba en el carrito real
			// no se vuelve a mandar, para no duplicarlo.
			if ( 'checkbox' === addBtn.dataset.pllcMode ) {
				if ( card.dataset.pllcOriginalMeals || card.dataset.pllcFreshConfirmed !== '1' ) {
					return;
				}
				var fresh = getFreshChecked( card );
				if ( ! fresh.length ) {
					return;
				}
					items.push( {
						product_id: productId,
						day: getCardDay( card ),
						delivery_date: getDeliveryDate( getCardDay( card ) ),
						meals: Array.prototype.map.call( fresh, function ( c ) {
						return c.dataset.pllcValue;
					} )
				} );
				return;
			}

			// Variante (Colegios) / cantidad nueva (ITEO Pacientes).
			if ( card.dataset.pllcAdded === '1' ) {
				if ( card.dataset.pllcCartKeys ) {
					// Todo ítem existente se procesa por su colector de actualización;
					// nunca se vuelve a agregar como una segunda línea.
					return;
				}

				var item = {
					product_id: productId,
					day: getCardDay( card ),
					delivery_date: getDeliveryDate( getCardDay( card ) )
				};

				if ( card.dataset.pllcVariationId ) {
					item.variation_id = card.dataset.pllcVariationId;
				}

				var qtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
				if ( qtyInput ) {
					item.qty = qtyInput.value;
				}

				items.push( item );
			}
		} );

		return items;
	}

	function collectVariationUpdates() {
		var updates = [];

		document.querySelectorAll( '.e-loop-item[data-pllc-variation-update="prepared"]' ).forEach( function ( card ) {
			var keys = ( card.dataset.pllcCartKeys || '' ).split( ',' ).filter( Boolean );
			if ( ! keys.length || ! card.dataset.pllcVariationId ) {
				return;
			}
			updates.push( {
				cart_item_key: keys[0],
				product_id: getProductId( card ),
				variation_id: card.dataset.pllcVariationId
			} );
		} );

		return updates;
	}

	function collectQuantityUpdates() {
		var updates = [];
		document.querySelectorAll( '.e-loop-item[data-pllc-quantity-update="prepared"]' ).forEach( function ( card ) {
			var keys  = ( card.dataset.pllcCartKeys || '' ).split( ',' ).filter( Boolean );
			var input = card.querySelector( '[data-pllc-role="qty-value"]' );
			if ( ! keys.length || ! input ) {
				return;
			}
			var qty = parseInt( input.value, 10 );
			updates.push( {
				cart_item_key: keys[0],
				product_id: getProductId( card ),
				qty: isNaN( qty ) ? 0 : Math.max( 0, qty )
			} );
		} );
		return updates;
	}

	function collectMealUpdates() {
		var updates = [];
		document.querySelectorAll( '.e-loop-item[data-pllc-meal-update="prepared"]' ).forEach( function ( card ) {
			var productId = getProductId( card );
			if ( ! productId ) {
				return;
			}
			updates.push( {
				product_id: productId,
				day: getCardDay( card ),
				delivery_date: getDeliveryDate( getCardDay( card ) ),
				meals: getCheckedMeals( card )
			} );
		} );
		return updates;
	}

	function initSingleOrderForm() {
		var form = document.querySelector(
			'[data-pllc-role="order-form"][data-pllc-form-type="iteo_pacientes"], ' +
			'[data-pllc-role="order-form"][data-pllc-form-type="iteo_personal"], ' +
			'[data-pllc-role="order-form"][data-pllc-form-type="particular"]'
		);
		if ( ! form ) {
			return;
		}
		var state = window.PLLC_Data && PLLC_Data.cart_state ? PLLC_Data.cart_state : {};
		var hasActiveOrder = Object.keys( state ).length > 0;
		var observations = form.querySelector( '[data-pllc-field="observaciones"]' );
		if ( observations ) {
			if ( window.PLLC_Data && PLLC_Data.current_form ) {
				observations.value = PLLC_Data.current_form.observaciones || '';
			}
			observations.dataset.pllcOriginalValue = observations.value;
			observations.addEventListener( 'input', function () {
				form.dataset.pllcFormDirty = observations.value !== ( observations.dataset.pllcOriginalValue || '' ) ? '1' : '0';
				if ( form.dataset.pllcFormType === 'iteo_personal' ) {
					refreshIteoPersonalSubmitState();
				}
			} );
		}
		form.dataset.pllcActiveOrder = hasActiveOrder ? '1' : '0';
		setOrderSubmitLabel( form, hasActiveOrder );

		if ( form.dataset.pllcFormType === 'iteo_personal' ) {
			document.querySelectorAll( '.e-loop-item' ).forEach( function ( card ) {
				var addBtn = card.querySelector( '[data-pllc-role="add-btn"][data-pllc-mode="checkbox"]' );
				if ( addBtn && ! card.dataset.pllcOriginalMeals ) {
					refreshFreshMealCardButton( card );
				}
			} );
			refreshIteoPersonalSubmitState();
		}
	}

	function collectFormData( form ) {
		var data = {};
		form.querySelectorAll( '[data-pllc-field]' ).forEach( function ( field ) {
			if ( ( 'radio' === field.type || 'checkbox' === field.type ) && ! field.checked ) {
				return;
			}
			data[ field.dataset.pllcField ] = field.value;
		} );
		return data;
	}

	function showFormMessage( form, text ) {
		var msg = form.querySelector( '[data-pllc-role="form-message"]' );
		if ( msg ) {
			msg.classList.remove( 'pllc-form-message-info' );
			msg.textContent = text;
		}
	}

	function submitOrder( btn ) {
		var form = btn.closest( '[data-pllc-role="order-form"]' );
		if ( ! form ) {
			return;
		}

		var items = collectOrderItems();
		var updates = collectVariationUpdates();
		var quantityUpdates = collectQuantityUpdates();
		var mealUpdates = collectMealUpdates();
		var hasEditableOrder = !! form.dataset.pllcStudentKey || form.dataset.pllcActiveOrder === '1';
		if ( ! items.length && ! updates.length && ! quantityUpdates.length && ! mealUpdates.length && ! hasEditableOrder ) {
			alert( 'Todavía no agregaste ningún producto al pedido.' );
			return;
		}

		var requiredFields = form.querySelectorAll( '[data-pllc-field][required]' );
		for ( var i = 0; i < requiredFields.length; i++ ) {
			if ( ! requiredFields[ i ].value ) {
				alert( 'Completá todos los campos obligatorios del formulario.' );
				requiredFields[ i ].focus();
				return;
			}
		}

		var payload = new FormData();
		payload.append( 'action', 'pllc_add_order' );
		payload.append( 'nonce', PLLC_Data.nonce );
		payload.append( 'form_type', form.dataset.pllcFormType );
		payload.append( 'student_key', form.dataset.pllcStudentKey || '' );
		payload.append( 'form', JSON.stringify( collectFormData( form ) ) );
		payload.append( 'items', JSON.stringify( items ) );
		payload.append( 'updates', JSON.stringify( updates ) );
		payload.append( 'quantity_updates', JSON.stringify( quantityUpdates ) );
		payload.append( 'meal_updates', JSON.stringify( mealUpdates ) );
		payload.append( 'operation_id', ensureOperationId( form ) );

		btn.disabled = true;
		if ( form.dataset.pllcFormType === 'iteo_personal' ) {
			setButtonLabel( btn, hasEditableOrder ? 'Actualizando...' : 'Agregando...' );
		} else {
			setButtonLabel( btn, hasEditableOrder ? 'Actualizando...' : 'Agregando...' );
		}
		showFormMessage( form, '' );

		fetch( PLLC_Data.ajax_url, { method: 'POST', body: payload } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res.success ) {
					acceptFrontendState( res.data );
					try {
						if ( form.dataset.pllcStudentKey ) {
							rememberStudentAfterReload( form.dataset.pllcStudentKey );
							sessionStorage.removeItem( FORM_RESET_KEY );
						} else {
							sessionStorage.removeItem( STUDENT_RESTORE_KEY );
							sessionStorage.setItem( FORM_RESET_KEY, '1' );
						}
					} catch ( error ) {}
					rememberCartEventAfterReload( res.data );
					window.location.href = window.location.pathname + window.location.search;
				} else {
					showFormMessage( form, ( res.data && res.data.message ) || 'Ocurrió un error, probá de nuevo.' );
					btn.disabled = false;
					setOrderSubmitLabel( form, hasEditableOrder );
				}
			} )
			.catch( function () {
				showFormMessage( form, 'Error de conexión, probá de nuevo.' );
				btn.disabled = false;
				setOrderSubmitLabel( form, hasEditableOrder );
			} );
	}

	function forceQuantityInputStyles() {
		document.querySelectorAll( '.pllc-quantity-stepper input[type="number"]' ).forEach( function ( input ) {
			input.style.setProperty( 'color', '#222', 'important' );
			input.style.setProperty( 'background-color', '#fff', 'important' );
			input.style.setProperty( 'font-size', '1rem', 'important' );
			input.style.setProperty( 'opacity', '1', 'important' );
			input.style.setProperty( 'text-indent', '0', 'important' );
		} );
	}

	/**
	 * Algunos campos "key" que le pasamos a woocommerce_get_item_data
	 * quedan impresos como texto visible en esta instalación (en vez de
	 * usarse solo como clase CSS) — ocultamos cualquier rótulo que
	 * contenga el prefijo "pllc-", sin tener que listar cada clave nueva
	 * a mano.
	 */
	function hideGroupHeaderLabel() {
		document.querySelectorAll( 'dt' ).forEach( function ( dt ) {
			if ( dt.textContent.trim().toLowerCase().indexOf( 'pllc-' ) !== -1 ) {
				dt.style.display = 'none';
			}
		} );
	}

	/**
	 * El botón "Update Cart" solo tiene sentido si hay algo editable en
	 * el carrito — o sea, si hay al menos un producto de Particular (los
	 * únicos con cantidad editable). Si el carrito es 100% de Colegios
	 * (cantidad siempre fija en 1), no hace falta mostrarlo.
	 */
	function toggleUpdateCartButton() {
		var hasParticular = document.querySelector( '.pllc-form-type-particular' ) !== null;
		document.body.classList.toggle( 'pllc-cart-has-editable-quantities', hasParticular );
	}

	/**
	 * Mueve el bloque "Pedido para..." + resumen (que WooCommerce imprime
	 * adentro de la celda "Producto") a su PROPIA fila de la tabla, con
	 * colspan completo — así ocupa todo el ancho en vez de quedar
	 * apretado en una sola columna.
	 */
	function expandGroupHeadersFullWidth() {
		document.querySelectorAll( '.pllc-cart-group-header' ).forEach( function ( headerEl ) {
			var dd  = headerEl.closest( 'dd' );
			var row = headerEl.closest( 'tr' );

			if ( ! dd || ! row || ! row.parentNode ) {
				return;
			}

			var dt = dd.previousElementSibling;
			var colCount = row.children.length;

			var newRow = document.createElement( 'tr' );
			newRow.className = 'pllc-cart-group-header-row';

			var newCell = document.createElement( 'td' );
			newCell.colSpan = colCount;
			newCell.innerHTML = dd.innerHTML;

			newRow.appendChild( newCell );
			row.parentNode.insertBefore( newRow, row );

			dd.remove();
			if ( dt && dt.tagName === 'DT' ) {
				dt.remove();
			}
		} );
	}

	/**
	 * En un carrito 100% ITEO (sin Colegios ni Particulares), no hay
	 * envío ni pago real acá — así que en el bloque de totales dejamos
	 * visible solo el botón de checkout, ocultando Subtotal/Envío/Total.
	 */
	/**
	 * En checkout separa "Pedido para..." del primer producto del grupo.
	 * El encabezado ocupa una fila completa y cada precio queda alineado
	 * verticalmente con el plato al que pertenece.
	 */
	function expandCheckoutGroupHeadersFullWidth() {
		document.querySelectorAll( '.pllc-checkout-group-header' ).forEach( function ( headerEl ) {
			var row = headerEl.closest( 'tr' );
			if ( ! row || ! row.parentNode || row.classList.contains( 'pllc-checkout-group-header-row' ) ) {
				return;
			}
			var newRow = document.createElement( 'tr' );
			newRow.className = 'pllc-checkout-group-header-row';
			var newCell = document.createElement( 'td' );
			newCell.colSpan = Math.max( row.children.length, 1 );
			newCell.appendChild( headerEl );
			newRow.appendChild( newCell );
			row.parentNode.insertBefore( newRow, row );
		} );
	}

	/** Separa también los encabezados en Gracias / Ver pedido. */
	function expandOrderDetailGroupHeadersFullWidth() {
		document.querySelectorAll( '.pllc-order-detail-group-header' ).forEach( function ( headerEl ) {
			var row = headerEl.closest( 'tr' );
			if ( ! row || ! row.parentNode || row.classList.contains( 'pllc-order-detail-group-header-row' ) ) {
				return;
			}
			var newRow = document.createElement( 'tr' );
			newRow.className = 'pllc-order-detail-group-header-row';
			var newCell = document.createElement( 'td' );
			newCell.colSpan = Math.max( row.children.length, 1 );
			newCell.appendChild( headerEl );
			newRow.appendChild( newCell );
			row.parentNode.insertBefore( newRow, row );
		} );
	}

	function simplifyTotalsForIteoOnlyCart() {
		if ( ! document.body.classList.contains( 'pllc-iteo-only-cart' ) ) {
			return;
		}

		var checkoutBtn = document.querySelector( '.checkout-button, .wc-proceed-to-checkout, a[href*="checkout"]' );
		if ( ! checkoutBtn ) {
			return;
		}

		var totalsBox = checkoutBtn.closest( '[class*="totals" i]' );
		if ( ! totalsBox ) {
			return;
		}

		Array.prototype.forEach.call( totalsBox.children, function ( child ) {
			if ( child !== checkoutBtn && ! child.contains( checkoutBtn ) ) {
				child.style.display = 'none';
			}
		} );
	}

	/**
	 * En el mismo caso (carrito 100% ITEO), el ícono de carrito del menú
	 * (widget "Menu Cart" de Elementor Pro) muestra el importe real —
	 * lo reemplazamos por "$---" ya que ese número no debería verse.
	 */
	function maskMiniCartTotalForIteo() {
		if ( ! document.body.classList.contains( 'pllc-iteo-only-cart' ) ) {
			return;
		}

		var cartWidget = document.querySelector( '.elementor-widget-woocommerce-menu-cart' );
		if ( ! cartWidget ) {
			return;
		}

		// WooCommerce separa el símbolo "$" y el número en elementos
		// distintos dentro de .woocommerce-Price-amount — por eso no
		// alcanza con buscar texto que empiece con "$", hay que apuntar
		// directo a esa clase estándar y reemplazar todo su contenido.
		cartWidget.querySelectorAll( '.woocommerce-Price-amount' ).forEach( function ( el ) {
			el.textContent = '---';
		} );
	}

	function hideEmptyDays() {
		document.querySelectorAll( '.pllc-day-wrapper' ).forEach( function ( wrapper ) {
			var grid = wrapper.querySelector( '.elementor-widget-loop-grid' );
			if ( ! grid ) {
				return;
			}
			var hasItems = grid.querySelectorAll( '.e-loop-item' ).length > 0;
			if ( ! hasItems ) {
				wrapper.style.display = 'none';
			}
		} );
	}

	function hideUnavailableDays() {
		var availability = window.PLLC_Data && PLLC_Data.day_availability ? PLLC_Data.day_availability : {};
		document.querySelectorAll( '.pllc-day-wrapper' ).forEach( function ( wrapper ) {
			var day = getWrapperDay( wrapper );
			if ( ! day || ! Object.prototype.hasOwnProperty.call( availability, day ) ) {
				return;
			}
			wrapper.style.display = availability[ day ] === true ? '' : 'none';
		} );
	}

	/**
	 * Completa el encabezado de cada bloque con la fecha calculada por
	 * WordPress. Conserva el widget Encabezado de Elementor existente.
	 */
	function updateDayTitles() {
		var titles = window.PLLC_Data && PLLC_Data.day_titles ? PLLC_Data.day_titles : {};
		var aliases = {
			lunes: 'lunes',
			martes: 'martes',
			miercoles: 'miercoles',
			'miércoles': 'miercoles',
			jueves: 'jueves',
			viernes: 'viernes',
			sabado: 'sabado',
			'sábado': 'sabado'
		};

		document.querySelectorAll( '.pllc-day-wrapper' ).forEach( function ( wrapper ) {
			var heading = wrapper.querySelector( '.elementor-heading-title' );
			if ( ! heading ) {
				return;
			}

			var firstWord = heading.textContent.trim().split( /\s+/ )[ 0 ].toLowerCase();
			var daySlug = aliases[ firstWord ] || getWrapperDay( wrapper );

			if ( daySlug && titles[ daySlug ] ) {
				heading.textContent = titles[ daySlug ];
			}
		} );
	}

	/**
	 * Limpieza visual que también hay que reaplicar cuando WooCommerce
	 * actualiza el carrito/mini-carrito por AJAX (quitar un producto,
	 * refrescar fragments, etc.) — ese contenido se re-arma sin recargar
	 * la página, así que si solo corriéramos esto en DOMContentLoaded,
	 * volvería a verse el HTML "de fábrica" (Subtotal/Envío reapareciendo,
	 * precio real en vez de "$---", rótulos sueltos).
	 */
	function runCleanup() {
		expandGroupHeadersFullWidth();
		expandCheckoutGroupHeadersFullWidth();
		expandOrderDetailGroupHeadersFullWidth();
		hideGroupHeaderLabel();
		toggleUpdateCartButton();
		maskMiniCartTotalForIteo();
		simplifyTotalsForIteoOnlyCart();
		ensureShippingNote();
	}

	/**
	 * Respaldo para widgets de Elementor que no ejecutan los hooks PHP de
	 * totales. Inserta el aviso antes de Envío y lo repone tras cada AJAX.
	 */
	function ensureShippingNote() {
		var note = window.PLLC_Data && PLLC_Data.shipping_note ? PLLC_Data.shipping_note : '';
		var existing = document.querySelector( '.pllc-shipping-note-row' );

		if ( ! note ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}

		var shippingRow = document.querySelector(
			'.woocommerce-checkout-review-order-table tfoot tr.shipping, ' +
			'.woocommerce-checkout-review-order-table tfoot tr.woocommerce-shipping-totals, ' +
			'.cart_totals tr.shipping, ' +
			'.woocommerce-checkout-review-order-table tfoot tr.order-total, ' +
			'.cart_totals tr.order-total'
		);
		if ( existing ) {
			var existingText = existing.querySelector( '.pllc-shipping-note' );
			if ( existingText ) {
				existingText.textContent = note;
			}
			if ( shippingRow && shippingRow.parentNode && existing.nextElementSibling !== shippingRow ) {
				shippingRow.parentNode.insertBefore( existing, shippingRow );
			}
			return;
		}

		if ( ! shippingRow || ! shippingRow.parentNode ) {
			return;
		}

		var row = document.createElement( 'tr' );
		row.className = 'pllc-shipping-note-row';
		var cell = document.createElement( 'td' );
		cell.colSpan = Math.max( shippingRow.children.length, 2 );
		var content = document.createElement( 'div' );
		content.className = 'pllc-shipping-note';
		content.textContent = note;
		cell.appendChild( content );
		row.appendChild( cell );
		shippingRow.parentNode.insertBefore( row, shippingRow );
	}

	/**
	 * El formulario conoce el recorrido aunque el slug real de la página sea
	 * distinto. Usa ese dato para activar los estilos correctos sin depender
	 * de las clases que WordPress o Elementor agreguen al body.
	 */
	function applyOrderPageClass() {
		var form = document.querySelector( '[data-pllc-role="order-form"][data-pllc-form-type]' );
		if ( ! form ) {
			return;
		}
		var formType = form.dataset.pllcFormType || '';
		if ( formType ) {
			document.body.classList.add( 'pllc-page-' + formType.replace( /_/g, '-' ) );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		applyOrderPageClass();
		normalizeLoopAddToCartWidgets();
		initIteoPatientNativeWidgets();
		initParticularBatchWidgets();
		normalizeProductCardModes();
		initParticularProductPage();
		updateDayTitles();
		var resetAfterSubmit = false;
		try {
			resetAfterSubmit = sessionStorage.getItem( FORM_RESET_KEY ) === '1';
			sessionStorage.removeItem( FORM_RESET_KEY );
		} catch ( error ) {}

		if ( resetAfterSubmit ) {
			window.scrollTo( 0, 0 );
			requestAnimationFrame( function () { window.scrollTo( 0, 0 ); } );
			window.addEventListener( 'load', function () { window.scrollTo( 0, 0 ); } );
		}

		hideUnavailableDays();
		hideEmptyDays();
		forceQuantityInputStyles();
		toggleUpdateCartButton();
		expandGroupHeadersFullWidth();
		applyCartState();
		initParticularCartState();
		initStudentPicker();
		initSingleOrderForm();
		runCleanup();
		window.setTimeout( emitRememberedCartEvent, 0 );

		[
			'wc_fragments_refreshed',
			'wc_fragments_loaded',
			'added_to_cart',
			'removed_from_cart',
			'updated_wc_div',
			'updated_cart_totals',
			'updated_checkout'
		].forEach( function ( evt ) {
			document.body.addEventListener( evt, runCleanup );
		} );

		// WooCommerce dispara estos eventos mediante jQuery después de
		// reemplazar la tabla del carrito por AJAX.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on(
				'wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart updated_wc_div updated_cart_totals updated_checkout',
				runCleanup
			);
		}

		// Radios/checkboxes/selects: estado nativo, escuchamos "change".
		document.addEventListener( 'change', function ( e ) {
			var target = e.target;

			if ( target.matches && target.matches( '[data-pllc-role="variant"]' ) ) {
				handleVariantChange( target );
			}

			if ( target.matches && target.matches( '[data-pllc-cascade="colegio"]' ) ) {
				handleColegioChange( target );
			}

			if ( target.matches && target.matches( '[data-pllc-cascade="nivel"]' ) ) {
				handleNivelChange( target );
			}

			if ( target.matches && target.matches( '[data-pllc-role="qty-value"]' ) ) {
				refreshQuantityCardButton( closestCard( target ) );
			}

			if ( target.matches && target.matches( '[data-pllc-role="check"]' ) ) {
				handleMealSelectionChange( target );
			}

			if ( ! getParticularOrderForm() && target.matches && target.matches( '.quantity input.qty, input[name="quantity"]' ) ) {
				refreshParticularQuantityButton( closestCard( target ) );
			}
		} );

		document.addEventListener( 'input', function ( e ) {
			if ( e.target.matches && e.target.matches( '[data-pllc-role="qty-value"]' ) ) {
				refreshQuantityCardButton( closestCard( e.target ) );
			}
			if ( ! getParticularOrderForm() && e.target.matches && e.target.matches( '.quantity input.qty, input[name="quantity"]' ) ) {
				refreshParticularQuantityButton( closestCard( e.target ) );
			}
		} );

		// Captura antes que WooCommerce el click de una línea ya existente.
		document.addEventListener( 'click', handleParticularQuantityUpdateClick, true );

		// Botones (-, +, Agregar al pedido, Agregar al carrito): clicks.
		document.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( '[data-pllc-role]' );
			if ( ! target ) {
				return;
			}

			var role = target.dataset.pllcRole;

			switch ( role ) {
				case 'qty-minus':
					e.preventDefault();
					handleQtyClick( target, -1 );
					break;
				case 'qty-plus':
					e.preventDefault();
					handleQtyClick( target, 1 );
					break;
				case 'add-btn':
					e.preventDefault();
					handleAddClick( target );
					break;
				case 'submit-order':
					e.preventDefault();
					submitOrder( target );
					break;
			}
		} );
	} );
} )();
