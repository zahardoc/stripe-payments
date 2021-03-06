<?php

class AcceptStripePayments_CouponsAdmin {

    var $POST_SLUG = 'asp_coupons';

    function __construct() {
	add_action( 'init', array( $this, 'init_handler' ) );
	if ( is_admin() ) {
	    add_action( 'admin_menu', array( $this, 'add_menu' ) );
	    if ( wp_doing_ajax() ) {
		add_action( 'wp_ajax_asp_check_coupon', array( $this, 'frontend_check_coupon' ) );
		add_action( 'wp_ajax_nopriv_asp_check_coupon', array( $this, 'frontend_check_coupon' ) );
	    }
	}
    }

    static function get_coupon( $coupon_code ) {
	$out	 = array(
	    'code'	 => $coupon_code,
	    'valid'	 => true,
	);
	//let's find coupon
	$coupon	 = get_posts( array(
	    'meta_key'	 => 'asp_coupon_code',
	    'meta_value'	 => $coupon_code,
	    'posts_per_page' => 1,
	    'offset'	 => 0,
	    'post_type'	 => 'asp_coupons',
	) );
	if ( empty( $coupon ) ) {
	    //coupon not found
	    $out[ 'valid' ]		 = false;
	    $out[ 'err_msg' ]	 = __( 'Coupon not found.', 'stripe-payments' );
	    return $out;
	}
	$coupon = $coupon[ 0 ];
	//check if coupon is active
	if ( ! get_post_meta( $coupon->ID, 'asp_coupon_active', true ) ) {
	    $out[ 'valid' ]		 = false;
	    $out[ 'err_msg' ]	 = __( 'Coupon is not active.', 'stripe-payments' );
	    return $out;
	}
	//check if coupon start date has come
	$start_date = get_post_meta( $coupon->ID, 'asp_coupon_start_date', true );
	if ( empty( $start_date ) || strtotime( $start_date ) > time() ) {
	    $out[ 'valid' ]		 = false;
	    $out[ 'err_msg' ]	 = __( 'Coupon is not available yet.', 'stripe-payments' );
	    return $out;
	}
	//check if coupon has expired
	$exp_date = get_post_meta( $coupon->ID, 'asp_coupon_exp_date', true );
	if ( ! empty( $exp_date ) && strtotime( $exp_date ) < time() ) {
	    $out[ 'valid' ]		 = false;
	    $out[ 'err_msg' ]	 = __( 'Coupon has expired.', 'stripe-payments' );
	    return $out;
	}
	//check if redemption limit is reached
	$red_limit	 = get_post_meta( $coupon->ID, 'asp_coupon_red_limit', true );
	$red_count	 = get_post_meta( $coupon->ID, 'asp_coupon_red_count', true );
	if ( ! empty( $red_limit ) && intval( $red_count ) >= intval( $red_limit ) ) {
	    $out[ 'valid' ]		 = false;
	    $out[ 'err_msg' ]	 = __( 'Coupon redemption limit is reached.', 'stripe-payments' );
	    return $out;
	}
	$out[ 'id' ]		 = $coupon->ID;
	$out[ 'discount' ]	 = get_post_meta( $coupon->ID, 'asp_coupon_discount', true );
	$out[ 'discountType' ]	 = get_post_meta( $coupon->ID, 'asp_coupon_discount_type', true );
	return $out;
    }

    function frontend_check_coupon() {
	$out = array();
	if ( empty( $_POST[ 'coupon_code' ] ) ) {
	    $out[ 'success' ]	 = false;
	    $out[ 'msg' ]		 = __( 'Empty coupon code', 'stripe-payments' );
	    wp_send_json( $out );
	}
	$coupon_code = strtoupper( $_POST[ 'coupon_code' ] );

	$tax = ! empty( $_POST[ 'tax' ] ) ? intval( $_POST[ 'tax' ] ) : 0;

	$shipping = ! empty( $_POST[ 'shipping' ] ) ? intval( $_POST[ 'shipping' ] ) : 0;

	$coupon = self::get_coupon( $coupon_code );

	if ( ! $coupon[ 'valid' ] ) {
	    $out[ 'success' ]	 = false;
	    $out[ 'msg' ]		 = $coupon[ 'err_msg' ];
	    wp_send_json( $out );
	}

	$curr = isset( $_POST[ 'curr' ] ) ? $_POST[ 'curr' ] : '';

	$discount	 = $coupon[ 'discount' ];
	$discount_type	 = $coupon[ 'discountType' ];

	$amount = intval( $_POST[ 'amount' ] );

	$perc = AcceptStripePayments::is_zero_cents( $curr ) ? 0 : 2;

	if ( $coupon[ 'discountType' ] === 'perc' ) {
	    $discount_amount = round( $amount * ( $coupon[ 'discount' ] / 100 ), 0 );
	} else {
	    $discount_amount = $coupon[ 'discount' ] * ($perc === 0 ? 1 : 100);
	}
	$out[ 'discountAmount' ] = $discount_amount;
	$amount			 = round( ($amount - $discount_amount) / ($perc === 0 ? 1 : 100), $perc );

	$amount = AcceptStripePayments::apply_tax( $amount, $tax, AcceptStripePayments::is_zero_cents( $curr ) );

	$amount = round( $amount + $shipping / 100, 2 );

	$out[ 'tax' ]		 = $tax;
	$out[ 'shipping' ]	 = $shipping;
	$out[ 'amount' ]	 = $amount;
	$out[ 'success' ]	 = true;
	$out[ 'code' ]		 = $coupon_code;
	$out[ 'discount' ]	 = $discount;
	$out[ 'discountType' ]	 = $discount_type;
	$out[ 'discountStr' ]	 = $coupon_code . ': - ' . ($discount_type === "perc" ? $discount . '%' : AcceptStripePayments::formatted_price( $discount, $curr ));
	$out[ 'newAmountFmt' ]	 = AcceptStripePayments::formatted_price( $amount, $curr );
	wp_send_json( $out );
    }

    function init_handler() {
	$args = array(
	    'supports'		 => array( '' ),
	    'hierarchical'		 => false,
	    'public'		 => false,
	    'show_ui'		 => false,
	    'can_export'		 => false,
	    'has_archive'		 => false,
	    'exclude_from_search'	 => true,
	    'publicly_queryable'	 => false,
	    'capability_type'	 => 'post',
	);
	register_post_type( $this->POST_SLUG, $args );

	if ( isset( $_POST[ 'asp_coupon' ] ) ) {
	    $this->save_coupon();
	}
    }

    function add_menu() {
	add_submenu_page( 'edit.php?post_type=' . ASPMain::$products_slug, __( 'Coupons', 'stripe-payments' ), __( 'Coupons', 'stripe-payments' ), 'manage_options', 'stripe-payments-coupons', array( $this, 'display_coupons_menu_page' ) );
    }

    function save_settings() {
	check_admin_referer( 'asp-coupons-settings' );
	$settings			 = get_option( 'AcceptStripePayments-settings' );
	$opts				 = $_POST[ 'asp_coupons_opts' ];
	$settings[ 'coupons_enabled' ]	 = isset( $opts[ 'coupons_enabled' ] ) ? 1 : 0;
	unregister_setting( 'AcceptStripePayments-settings-group', 'AcceptStripePayments-settings' );
	update_option( 'AcceptStripePayments-settings', $settings );
	set_transient( 'asp_coupons_admin_notice', __( 'Settings updated.', 'stripe-payments' ), 60 * 60 );
    }

    function display_coupons_menu_page() {

	if ( isset( $_POST[ 'asp_coupons_opts' ] ) ) {
	    $this->save_settings();
	}

	if ( isset( $_GET[ 'action' ] ) ) {
	    $action = $_GET[ 'action' ];
	    if ( $action === 'asp_add_edit_coupon' ) {
		//coupon add or edit content
		$this->display_coupon_add_edit_page();
		return;
	    }
	    if ( $action === 'asp_delete_coupon' ) {
		//coupon delete action
		$this->delete_coupon();
	    }
	}

	$msg = get_transient( 'asp_coupons_admin_notice' );

	if ( $msg !== false ) {
	    delete_transient( 'asp_coupons_admin_notice' );
	    ?>
	    <div class="notice notice-success">
	        <p><?php echo $msg; ?></p>
	    </div>
	    <?php
	}

	$msg = get_transient( 'asp_coupons_admin_error' );

	if ( $msg !== false ) {
	    delete_transient( 'asp_coupons_admin_error' );
	    ?>
	    <div class="notice notice-error">
	        <p><?php echo $msg; ?></p>
	    </div>
	    <?php
	}

	$asp_main	 = AcceptStripePayments::get_instance();
	$coupons_enabled = $asp_main->get_setting( 'coupons_enabled' );

	if ( ! class_exists( 'WP_List_Table' ) ) {
	    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
	}

	require_once(WP_ASP_PLUGIN_PATH . 'admin/includes/class-cooupons-list-table.php');

	$coupons_tbl = new ASP_Coupons_Table( );
	$coupons_tbl->prepare_items();
	?>
	<style>
	    th#id {
		width: 10%;
	    }
	</style>
	<div class="wrap">
	    <h2><?php _e( 'Coupons', 'stripe-payments' ); ?></h2>
	    <?php
	    ?>
	    <div id="poststuff"><div id="post-body">
		    <div class="postbox">
			<h3 class="hndle"><label for="title"><?php _e( 'Coupon Settings', 'stripe-payments' ); ?></label></h3>
			<div class="inside">
			    <form method="post">
				<input type="hidden" name="asp_coupons_opts[_save-settings]" value="1">
				<table class="form-table">
				    <tr>
					<th scope="row"><?php _e( 'Enable Coupons', 'stripe-payments' ); ?></th>
					<td>
					    <input type="checkbox" name="asp_coupons_opts[coupons_enabled]"<?php echo $coupons_enabled ? ' checked' : ''; ?>>
					    <p class="description"><?php _e( 'Enables the discount coupon functionality.', 'stripe-payments' ); ?></p>
					</td>
				    </tr>
				</table>
				<?php
				wp_nonce_field( 'asp-coupons-settings' );
				submit_button( __( 'Save Settings', 'stripe-payments' ) );
				?>
			    </form>
			</div>
		    </div>
		</div></div>
	    <h2><?php _e( 'Coupons', 'stripe-payments' ); ?> <a class="page-title-action" href="?post_type=asp-products&page=stripe-payments-coupons&action=asp_add_edit_coupon">Add a Coupon</a></h2>
	    <?php $coupons_tbl->display(); ?>
	</div>
	<?php
    }

    function display_coupon_add_edit_page() {

	wp_enqueue_script( 'jquery-ui-datepicker' );
	wp_register_style( 'jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css' );
	wp_enqueue_style( 'jquery-ui' );

	$coupon_id	 = isset( $_GET[ 'asp_coupon_id' ] ) ? absint( $_GET[ 'asp_coupon_id' ] ) : false;
	$is_edit	 = $coupon_id ? true : false;
	if ( $is_edit ) {
	    if ( is_null( get_post( $coupon_id ) ) ) {
		echo 'error';
		return false;
	    }
	    $coupon = array(
		'id'		 => $coupon_id,
		'code'		 => get_post_meta( $coupon_id, 'asp_coupon_code', true ),
		'active'	 => get_post_meta( $coupon_id, 'asp_coupon_active', true ),
		'discount'	 => get_post_meta( $coupon_id, 'asp_coupon_discount', true ),
		'discount_type'	 => get_post_meta( $coupon_id, 'asp_coupon_discount_type', true ),
		'red_limit'	 => get_post_meta( $coupon_id, 'asp_coupon_red_limit', true ),
		'red_count'	 => get_post_meta( $coupon_id, 'asp_coupon_red_count', true ),
		'start_date'	 => get_post_meta( $coupon_id, 'asp_coupon_start_date', true ),
		'exp_date'	 => get_post_meta( $coupon_id, 'asp_coupon_exp_date', true ),
	    );
	}
	?>
	<div class="wrap">
	    <h2><?php empty( $coupon_id ) ? _e( 'Add Coupon', 'stripe-payments' ) : _e( 'Edit Coupon', 'stripe-payments' ); ?></h2>
	    <form method="post">
		<table class="form-table">
		    <tr>
			<th scope="row"><?php _e( 'Active', 'stripe-payments' ); ?></th>
			<td>
			    <input type="checkbox" name="asp_coupon[active]"<?php echo ( ! $is_edit) || ($is_edit && $coupon[ 'active' ]) ? "checked" : '' ?>>
			    <p class="description"><?php _e( 'Use this to enable/disable this coupon.', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <?php if ( $is_edit ) { ?>
	    	    <input type="hidden" name="asp_coupon_id" value="<?php echo $coupon_id; ?>">
	    	    <tr>
	    		<th scope="row"><?php _e( 'Coupon ID', 'stripe-payments' ); ?></th>
	    		<td>
				<?php echo $coupon_id; ?>
	    		    <p class="description"><?php _e( 'Coupon ID. This value cannot be changed.', 'stripe-payments' ); ?></p>
	    		</td>
	    	    </tr>
		    <?php } ?>
		    <tr>
			<th scope="row"><?php _e( 'Coupon Code', 'stripe-payments' ); ?></th>
			<td>
			    <input type="text" name="asp_coupon[code]" value="<?php echo $is_edit ? $coupon[ 'code' ] : '' ?>">
			    <p class="description"><?php _e( 'Coupon code that you can share with your customers. Example: GET10OFF', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <tr>
			<th scope="row"><?php _e( 'Discount', 'stripe-payments' ); ?></th>
			<td>
			    <input style="vertical-align: middle;" type="text" name="asp_coupon[discount]" value="<?php echo $is_edit ? $coupon[ 'discount' ] : '' ?>">
			    <select name="asp_coupon[discount_type]">
				<option value="perc"<?php echo $is_edit && $coupon[ 'discount_type' ] === "perc" ? ' selected' : ''; ?>><?php _e( 'Percent (%)', 'stripe-payments' ); ?></option>
				<option value="fixed"<?php echo $is_edit && $coupon[ 'discount_type' ] === "fixed" ? ' selected' : ''; ?>><?php _e( 'Fixed amount', 'stripe-payments' ); ?></option>
			    </select>
			    <p class="description"><?php _e( 'Select discount amount and type. Enter a numeric value only. Example: 25', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <tr>
			<th scope="row"><?php _e( 'Redemption Limit', 'stripe-payments' ); ?></th>
			<td>
			    <input type="number" name="asp_coupon[red_limit]"value="<?php echo $is_edit ? $coupon[ 'red_limit' ] : 0 ?>">
			    <p class="description"><?php _e( 'Set max number of coupons available for redemption. Put 0 to make it unlimited.', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <tr>
			<th scope="row"><?php _e( 'Redemption Count', 'stripe-payments' ); ?></th>
			<td>
			    <input type="number" name="asp_coupon[red_count]"value="<?php echo $is_edit ? $coupon[ 'red_count' ] : 0 ?>">
			    <p class="description"><?php _e( 'Number of already redeemed coupons.', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <tr>
			<th scope="row"><?php _e( 'Start Date', 'stripe-payments' ); ?></th>
			<td>
			    <input class="datepicker-input" type="text" name="asp_coupon[start_date]"value="<?php echo $is_edit ? $coupon[ 'start_date' ] : date( 'Y-m-d' ) ?>">
			    <p class="description"><?php _e( 'Start date when this coupon can be used.', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		    <tr>
			<th scope="row"><?php _e( 'Expiry Date', 'stripe-payments' ); ?></th>
			<td>
			    <input class="datepicker-input" type="text" name="asp_coupon[exp_date]"value="<?php echo $is_edit ? $coupon[ 'exp_date' ] : 0 ?>">
			    <p class="description"><?php _e( 'Date when this coupon will expire. Put 0 to disable expiry check.', 'stripe-payments' ); ?></p>
			</td>
		    </tr>
		</table>
		<?php
		wp_nonce_field( 'asp-add-edit-coupon' );
		submit_button( $is_edit ? __( 'Update Coupon', 'stripe-payments' ) : __( 'Create Coupon', 'stripe-payments' )  );
		?>
	    </form>
	</div>
	<script>
	    jQuery(document).ready(function ($) {
		$('.datepicker-input').datepicker({
		    dateFormat: 'yy-mm-dd'
		});
	    });
	</script>
	</script>
	<?php
    }

    function delete_coupon() {
	$coupon_id = isset( $_GET[ 'asp_coupon_id' ] ) ? absint( $_GET[ 'asp_coupon_id' ] ) : false;

	if ( ! $coupon_id ) {
	    set_transient( 'asp_coupons_admin_error', __( 'Can\'t delete coupon: coupon ID is not provided.', 'stripe-payments' ), 60 * 60 );
	    return false;
	}
	$post = get_post( $coupon_id );
	if ( is_null( $post ) ) {
	    set_transient( 'asp_coupons_admin_error', sprintf( __( 'Can\'t delete coupon: coupon #%d not found.', 'stripe-payments' ), $coupon_id ), 60 * 60 );
	    return false;
	}
	if ( $post->post_type !== $this->POST_SLUG ) {
	    set_transient( 'asp_coupons_admin_error', sprintf( __( 'Can\'t delete coupon: post #%d is not a coupon.', 'stripe-payments' ), $coupon_id ), 60 * 60 );
	    return false;
	}
	check_admin_referer( 'delete-coupon_' . $coupon_id );
	wp_delete_post( $coupon_id, true );
	set_transient( 'asp_coupons_admin_notice', sprintf( __( 'Coupon #%d has been deleted.', 'stripe-payments' ), $coupon_id ), 60 * 60 );
    }

    function save_coupon() {
	$coupon = $_POST[ 'asp_coupon' ];

	$coupon_id = isset( $_POST[ 'asp_coupon_id' ] ) ? absint( $_POST[ 'asp_coupon_id' ] ) : false;

	$is_edit = $coupon_id ? true : false;

	check_admin_referer( 'asp-add-edit-coupon' );

	$err_msg = array();

	$coupon[ 'active' ] = isset( $coupon[ 'active' ] ) ? 1 : 0;

	if ( empty( $coupon[ 'code' ] ) ) {
	    $err_msg[] = __( 'Please enter coupon code.', 'stripe-payments' );
	}

	if ( empty( $coupon[ 'discount' ] ) ) {
	    $err_msg[] = __( 'Please enter discount.', 'stripe-payments' );
	}

	if ( ! empty( $err_msg ) ) {
	    foreach ( $err_msg as $msg ) {
		?>
		<div class="notice notice-error">
		    <p><?php echo $msg; ?></p>
		</div>
		<?php
	    }
	    return false;
	}
	if ( ! $is_edit ) {
	    $post			 = array();
	    $post[ 'post_title' ]	 = '';
	    $post[ 'post_status' ]	 = 'publish';
	    $post[ 'content' ]	 = '';
	    $post[ 'post_type' ]	 = $this->POST_SLUG;
	    $coupon_id		 = wp_insert_post( $post );
	}
	foreach ( $coupon as $key => $value ) {
	    update_post_meta( $coupon_id, 'asp_coupon_' . $key, $value );
	}
	set_transient( 'asp_coupons_admin_notice', sprintf( $is_edit ? __( 'Coupon "%s" has been updated.', 'stripe-payments' ) : __( 'Coupon "%s" has been created.', 'stripe-payments' ), $coupon[ 'code' ] ), 60 * 60 );

	wp_safe_redirect( 'edit.php?post_type=asp-products&page=stripe-payments-coupons' );
	exit;
    }

}

new AcceptStripePayments_CouponsAdmin();
