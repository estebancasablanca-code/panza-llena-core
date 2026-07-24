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
 *    en Colegios (agregar un producto deshabilita el resto de los
 *    "Agregar al pedido" del mismo día).
 * 4) Cascada Nivel → Curso en el formulario de Colegios.
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

	var CURSOS_POR_NIVEL = {
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
	};

	function closestCard( el ) {
		return el.closest( '.e-loop-item' );
	}

	function closestGrid( el ) {
		return el.closest( '.elementor-widget-loop-grid' );
	}

	function getProductId( card ) {
		var holder = card.querySelector( '[data-product-id]' );
		return holder ? holder.dataset.productId : null;
	}

	function handleVariantChange( input ) {
		var card = closestCard( input );
		if ( ! card ) {
			return;
		}
		card.dataset.pllcVariant = input.dataset.pllcValue || '';
		card.dataset.pllcVariationId = input.dataset.pllcVariationId || '';
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
		var current = parseInt( input.value, 10 ) || 1;
		input.value = Math.max( 1, current + delta );
	}

	function setGridExclusive( card ) {
		var grid = closestGrid( card );
		if ( ! grid ) {
			return;
		}
		grid.querySelectorAll( '[data-pllc-role="add-btn"]' ).forEach( function ( btn ) {
			if ( closestCard( btn ) !== card ) {
				btn.classList.add( 'pllc-disabled' );
				btn.setAttribute( 'aria-disabled', 'true' );
			}
		} );
	}

	function releaseGridExclusive( card ) {
		var grid = closestGrid( card );
		if ( ! grid ) {
			return;
		}
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
			card.querySelectorAll( '[data-pllc-role="check"]' ).forEach( function ( c ) {
				c.checked = false;
			} );
		}

		if ( 'quantity' === mode ) {
			var qtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
			if ( qtyInput ) {
				qtyInput.value = 1;
			}
		}
	}

	function handleAddClick( btn ) {
		if ( btn.classList.contains( 'pllc-disabled' ) ) {
			return; // Deshabilitado por exclusión del día (Colegios).
		}

		var card = closestCard( btn );
		if ( ! card ) {
			return;
		}

		var mode         = btn.dataset.pllcMode;
		var alreadyAdded = card.dataset.pllcAdded === '1';

		if ( alreadyAdded ) {
			card.dataset.pllcAdded = '0';
			btn.textContent = 'Agregar al pedido';
			btn.classList.remove( 'pllc-added' );

			resetCardSelection( card, mode );

			if ( 'variant' === mode ) {
				releaseGridExclusive( card );
			}
			return;
		}

		if ( 'variant' === mode && ! card.dataset.pllcVariant ) {
			alert( 'Elegí Clásico o XL antes de agregar.' );
			return;
		}

		if ( 'checkbox' === mode ) {
			var anyChecked = card.querySelectorAll( '[data-pllc-role="check"]:checked' ).length > 0;
			if ( ! anyChecked ) {
				alert( 'Elegí Almuerzo y/o Cena antes de agregar.' );
				return;
			}
		}

		card.dataset.pllcAdded = '1';
		btn.textContent = 'Eliminar';
		btn.classList.add( 'pllc-added' );

		if ( 'variant' === mode ) {
			setGridExclusive( card );
		}
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
		var opciones = CURSOS_POR_NIVEL[ select.value ] || [];
		var html     = '<option value="">Seleccionar</option>';
		opciones.forEach( function ( c ) {
			html += '<option value="' + c + '">' + c + '</option>';
		} );
		cursoSelect.innerHTML = html;
	}

	function collectOrderItems() {
		var items = [];

		document.querySelectorAll( '.e-loop-item[data-pllc-added="1"]' ).forEach( function ( card ) {
			var productId = getProductId( card );
			if ( ! productId ) {
				return;
			}

			var item = { product_id: productId };

			if ( card.dataset.pllcVariationId ) {
				item.variation_id = card.dataset.pllcVariationId;
			}

			var checked = card.querySelectorAll( '[data-pllc-role="check"]:checked' );
			if ( checked.length ) {
				item.meals = Array.prototype.map.call( checked, function ( c ) {
					return c.dataset.pllcValue;
				} );
			}

			var qtyInput = card.querySelector( '[data-pllc-role="qty-value"]' );
			if ( qtyInput ) {
				item.qty = qtyInput.value;
			}

			items.push( item );
		} );

		return items;
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
			msg.textContent = text;
		}
	}

	function submitOrder( btn ) {
		var form = btn.closest( '[data-pllc-role="order-form"]' );
		if ( ! form ) {
			return;
		}

		var items = collectOrderItems();
		if ( ! items.length ) {
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
		payload.append( 'form', JSON.stringify( collectFormData( form ) ) );
		payload.append( 'items', JSON.stringify( items ) );

		btn.disabled = true;
		btn.textContent = 'Agregando...';
		showFormMessage( form, '' );

		fetch( PLLC_Data.ajax_url, { method: 'POST', body: payload } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res.success ) {
					window.location.reload();
				} else {
					showFormMessage( form, ( res.data && res.data.message ) || 'Ocurrió un error, probá de nuevo.' );
					btn.disabled = false;
					btn.textContent = 'Agregar al carrito';
				}
			} )
			.catch( function () {
				showFormMessage( form, 'Error de conexión, probá de nuevo.' );
				btn.disabled = false;
				btn.textContent = 'Agregar al carrito';
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

	document.addEventListener( 'DOMContentLoaded', function () {
		hideEmptyDays();
		forceQuantityInputStyles();
		hideGroupHeaderLabel();

		// Radios/checkboxes/selects: estado nativo, escuchamos "change".
		document.addEventListener( 'change', function ( e ) {
			var target = e.target;

			if ( target.matches && target.matches( '[data-pllc-role="variant"]' ) ) {
				handleVariantChange( target );
			}

			if ( target.matches && target.matches( '[data-pllc-cascade="nivel"]' ) ) {
				handleNivelChange( target );
			}
			// Los checkbox no necesitan procesamiento: se leen con
			// :checked directamente al armar/enviar el pedido.
		} );

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
