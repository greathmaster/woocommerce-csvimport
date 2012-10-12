<?php
function wppcsv_handle_csv_import_random () {
	//try {
	//create temp directory
	$upload_dir = wp_upload_dir();
	$dir = $upload_dir['basedir'] .'/csvimport/' . woocsv_random_string() . '/';
	mkdir($dir);
	//handle upload
	if (!woocsv_handle_uploads ($dir))
		throw new Exception(__('Er is iets mis gegaan bij het uploaden'));

	$are_there_csv_files = glob($dir.'*.csv');
	if (count($are_there_csv_files) != 1) throw new Exception(__('Er is geen of meerdere csv bestand(en) gevonden'));
	//run the magic
	$result = woocsv_import_products_from_csv ($are_there_csv_files[0],$dir);

	//}  catch (Exception $e) {
	// woocsv_admin_notice ($e->getMessage());
	//}

}


function woocsv_handle_zip_import () {
	//create temp directory
	try {
		$upload_dir = wp_upload_dir();
		$dir = $upload_dir['basedir'] .'/' . woocsv_random_string() . '/';
		mkdir($dir);
		$file = $_FILES['zip_file']['name'];
		move_uploaded_file( $_FILES['zip_file']['tmp_name'], $dir.$file );
		$zip = new ZipArchive;
		if ($zip->open($dir.$file) === TRUE) {
			$zip->extractTo($dir);
			$zip->close();
		} else throw new Exception(__('Kan de zip file niet uitpakken'));

		$are_there_csv_files = glob($dir.'*.csv');
		if (count($are_there_csv_files) != 1) throw new Exception(__('Er is geen of meerdere csv bestand(en) gevonden'));
		//run the magic
		$result = woocsv_import_products_from_csv ($are_there_csv_files[0],$dir);
	}
	catch (Exception $e) {
		woocsv_admin_notice ($e->getMessage());
	}

}

function woocsv_handle_fixed_import () {
	//get the upload dir
	$upload_dir = wp_upload_dir();
	$dir = $upload_dir['basedir'] .$_POST['fixed_dir'] .'/';
	try {
		//check the existence of the directory
		if (!glob($dir)) throw new Exception(__('De directory bestaat niet'));

		//check to see if there are files in
		if ( count( scandir( $dir ) ) <= 2) throw new Exception(__('Er zijn geen bestanden aanwezig in de directory'));

		//now check to see if there is a csv in there
		$are_there_csv_files = glob($dir.'*.csv');
		if (count($are_there_csv_files) != 1) throw new Exception(__('Er is geen of meerdere csv bestand(en) gevonden'));

		//run the magic
		$result = woocsv_import_products_from_csv ($are_there_csv_files[0],$dir);


	} catch (Exception $e) {
		woocsv_admin_notice ($e->getMessage());
	}
}

//import the products
function woocsv_import_products_from_csv ($file,$dir) {
	global $wpdb;
	set_time_limit(0);
	$row = 0;

	if ($handle = fopen($file, 'r') == FALSE) throw new Exception(__('Can not open file!'));
	$handle = fopen($file, 'r');
	$csvcontent = '';
	while (($line = fgetcsv($handle)) !== FALSE) {
		if ($row <> 0 ) $csvcontent[] = $line;
		$row ++;
	}
	fclose($handle);

	$content = $csvcontent;
	//0 title,
	//1 description,
	//2 short_description,
	//3 category
	//4 stock,
	//5 price,
	//6 regular_price,
	//7 sales_price,
	//8 weight,
	//9 length,
	//10 width,
	//11 height,
	//12 sku,
	//13 picture

	foreach ( $content as $data ) {
		$num = count($data);
		$row ++;
		$my_product = array(
			'post_title' => wp_strip_all_tags( $data[0] ),
			'post_content' => $data[1],
			'post_excerpt' => $data[2],
			'post_status' => 'publish' ,
			'post_type' => 'product'
		);
		//check to see if the product already exists and add the ID if true
		$product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $data[12] ));
		if ($product_id) $my_product['ID'] = $product_id;
		//now we create the product...ig the id is there is will update the product else it will make a new
		$post_id = wp_update_post($my_product);

		//set the attributes etc
		update_post_meta( $post_id, '_stock', $data[4] );
		update_post_meta( $post_id, '_price', $data[5] );
		update_post_meta( $post_id, '_regular_price', $data[6] );
		update_post_meta( $post_id, '_sale_price', $data[7] );
		update_post_meta( $post_id, '_weight', $data[8] );
		update_post_meta( $post_id, '_length', $data[9] );
		update_post_meta( $post_id, '_width', $data[10] );
		update_post_meta( $post_id, '_height', $data[11] );
		update_post_meta( $post_id, '_sku', $data[12] );

		update_post_meta( $post_id, '_manage_stock', 'yes' );
		update_post_meta( $post_id, '_visibility', 'visible' );

		//link the product to the category
		$category = wp_set_object_terms($post_id, $data[3] ,'product_cat');

		//get picture if there is one and add it as featured image
		if ( isset( $data[13] )) {
			woocsv_add_featured_image ( $post_id , $data[13], $dir );
		}
	}
}

function woocsv_add_featured_image($post_id,$image_array,$dir) {
	$options = get_option('csvimport-options');
	$upload_dir = wp_upload_dir();
	//delete images
	if ($options['deleteimages'] == 1) {
		//get the images
		$attachments = get_posts( array(
				'post_type' => 'attachment',
				'post_parent' => $post_id,
			));
		foreach ($attachments as $attachment) {
			wp_delete_attachment ($attachment->ID);
		}
	}

	$images = explode('|', $image_array);
	if (count($images) > 0) {
		foreach ($images as $image) {
			if (woocsv_isvalidurl($image)) {
				$image_data = file_get_contents($image); 
			} else {
				$image_data = file_get_contents($dir.$image);
			}

			if ( $image_data ) {
				$filename = basename($image);
				if(wp_mkdir_p($upload_dir['path']))
					$file = $upload_dir['path'] . '/' . $filename;
				else
					$file = $upload_dir['basedir'] . '/' . $filename;

				if (file_put_contents($file, $image_data)) {
					$wp_filetype = wp_check_filetype($filename, null );
					$attachment = array(
						'post_mime_type' => $wp_filetype['type'],
						'post_title' => sanitize_file_name($filename),
						'post_content' => '',
						'post_status' => 'inherit'
					);

					$attach_id = wp_insert_attachment( $attachment, $file, $post_id );
					require_once(ABSPATH . 'wp-admin/includes/image.php');
					$attach_data = wp_generate_attachment_metadata( $attach_id, $file );
					wp_update_attachment_metadata( $attach_id, $attach_data );
					set_post_thumbnail( $post_id, $attach_id );

				}
			}
		}
	}

}

function woocsv_admin_notice($message=''){
	if ($message)
		echo '<div class="error"><p>'.$message.'</p></div>';
}

//well this doed what is does....create a reandom string
function woocsv_random_string($length = 10) {
	$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW';
	$string = '';
	for ($p = 0; $p < $length; $p++) {
		$string .= $characters[mt_rand(0, strlen($characters))];
	}
	return $string;
}

function woocsv_get_normalized_files()
{
	$newfiles = array();
	foreach($_FILES as $fieldname => $fieldvalue)
		foreach($fieldvalue as $paramname => $paramvalue)
			foreach((array)$paramvalue as $index => $value)
				$newfiles[$fieldname][$index][$paramname] = $value;
			return $newfiles;
}



//handle file uploads
function woocsv_handle_uploads ( $dir ){
	try {
		$files = woocsv_get_normalized_files();
		foreach ($files['all_files'] as $file) {
			$from_location = $file['tmp_name'];
			$to_location = $dir . $file['name'];
			//check if file is csv or jpg
			move_uploaded_file($from_location, $to_location);
		}
		return true;
	} catch (MyException $e) {
		return false;
	}
}

function woocsv_isvalidurl($url)
{
return preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url);
}