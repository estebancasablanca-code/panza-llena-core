<?php
define( 'ABSPATH', __DIR__ );

function current_user_can( $capability ) {
	return false;
}

class PLLC_Roles {
	public static $roles = [];

	public static function get_current_user_roles() {
		return self::$roles;
	}
}

function check_access_rule( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-pllc-code-access.php';

foreach ( [ [], [ 'colegio' ], [ 'iteo_personal' ], [ 'iteo_paciente' ] ] as $roles ) {
	PLLC_Roles::$roles = $roles;
	check_access_rule(
		PLLC_Code_Access::form_type_allowed( 'particular' ),
		'Particular products must be available to visitors and every institutional access.'
	);
}

$access_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-pllc-access.php' );
check_access_rule(
	false === strpos( $access_source, "home_url( '/iteo-pacientes/' )" ),
	'The shop must not redirect ITEO Pacientes back to its institutional catalog.'
);
check_access_rule(
	false === strpos( $access_source, "self::is_particular_product( \$product_id ) && ! in_array( 'iteo_paciente'" ),
	'ITEO Pacientes must be allowed to open Particular product pages.'
);

echo "Access rule checks passed.\n";
